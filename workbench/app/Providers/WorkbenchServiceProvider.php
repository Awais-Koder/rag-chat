<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * The workbench is a real consumer app for the package. Local dev has no AI
     * provider API keys, so we register deterministic offline embedding fakes —
     * the same role a real app's config/ai.php plays in production for embeddings.
     *
     * Chat/agents are owned by the host via laravel/ai; this package only enables
     * local RAG (ingest → embed → store → retrieve / SearchKnowledge tool).
     *
     * Embeddings are derived from the text itself (shared vocabulary → close in
     * vector space), so retrieval ranking is REAL and meaningful — not random.
     */
    public function boot(): void
    {
        if (! $this->app->environment('local', 'testing')) {
            return;
        }

        Embeddings::fake(fn (EmbeddingsPrompt $prompt) => array_map(
            fn (string $input) => $this->vectorFor($input),
            $prompt->inputs,
        ));
    }

    /**
     * Deterministic bag-of-words embedding: hash each token into a bucket,
     * accumulate, then L2-normalize. No network, no API key, stable across runs.
     *
     * @return array<float>
     */
    protected function vectorFor(string $text, int $dimensions = 32): array
    {
        $vector = array_fill(0, $dimensions, 0.0);

        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokens as $token) {
            $bucket = (int) (hexdec(substr(md5($token), 0, 8)) % $dimensions);
            $vector[$bucket] += 1.0;
        }

        $magnitude = sqrt(array_sum(array_map(fn ($v) => $v * $v, $vector)));

        return $magnitude > 0.0
            ? array_map(fn ($v) => $v / $magnitude, $vector)
            : $vector;
    }
}
