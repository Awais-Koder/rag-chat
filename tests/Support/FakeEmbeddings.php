<?php

namespace Awais\RagChat\Tests\Support;

use Closure;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

/**
 * Deterministic, dependency-free fake embeddings for tests.
 *
 * The SDK's built-in embedding fake returns RANDOM vectors, which makes cosine
 * similarity meaningless and retrieval non-deterministic. This helper instead
 * derives a vector from the text itself: each token is hashed into a bucket and
 * accumulated, then the vector is L2-normalized. Documents that share words end
 * up close in vector space, so retrieval genuinely surfaces the relevant chunk
 * and tests can assert on ranking — all without any API key or network call.
 */
class FakeEmbeddings
{
    /**
     * A closure suitable for Embeddings::fake() / Ai::fakeEmbeddings().
     */
    public static function closure(int $dimensions = 32): Closure
    {
        return fn (EmbeddingsPrompt $prompt) => array_map(
            fn (string $input) => self::vectorFor($input, $dimensions),
            $prompt->inputs,
        );
    }

    /**
     * @return array<float>
     */
    public static function vectorFor(string $text, int $dimensions = 32): array
    {
        $vector = array_fill(0, $dimensions, 0.0);

        // Tokenize on non-word characters; lowercase for case-insensitive match.
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokens as $token) {
            $bucket = (int) (hexdec(substr(md5($token), 0, 8)) % $dimensions);
            $vector[$bucket] += 1.0;
        }

        // L2-normalize so vectors are comparable regardless of text length.
        $magnitude = sqrt(array_sum(array_map(fn ($v) => $v * $v, $vector)));

        if ($magnitude <= 0.0) {
            return $vector;
        }

        return array_map(fn ($v) => $v / $magnitude, $vector);
    }
}
