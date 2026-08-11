<?php

namespace Awais\RagChat;

use Awais\RagChat\Agents\RagAgent;
use Awais\RagChat\Models\RagDocument;
use Awais\RagChat\Rag\Ingestor;
use Awais\RagChat\Rag\PromptBuilder;
use Awais\RagChat\Rag\Retriever;
use Illuminate\Support\Collection;
use Laravel\Ai\Enums\Lab;

/**
 * Local RAG enabler for Laravel applications.
 *
 * Owns document ingest, embedding persistence, and retrieval. Chat goes through
 * the Laravel AI SDK RagAgent — use answer() for plain text, or RagAgent directly.
 */
class RagChat
{
    public function __construct(
        protected Ingestor $ingestor,
        protected Retriever $retriever,
        protected PromptBuilder $promptBuilder,
    ) {}

    /**
     * Ingest a file or directory from disk into the RAG store.
     *
     * @return RagDocument|RagDocument[]
     */
    public function ingest(string $path, array $meta = []): RagDocument|array
    {
        if (is_dir($path)) {
            return $this->ingestor->ingestDirectory($path);
        }

        return $this->ingestor->ingestFile($path, $meta);
    }

    /**
     * Ingest raw text directly (no file on disk required).
     */
    public function ingestText(string $text, string $source, ?string $title = null, array $meta = []): RagDocument
    {
        return $this->ingestor->ingestText($text, $source, $title, $meta);
    }

    /**
     * Ask the SDK RagAgent and return only the final answer text.
     *
     * @param  Lab|array<int, Lab|string>|string|null  $provider
     */
    public function answer(
        string $question,
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): string {
        $response = (new RagAgent)->prompt(
            $this->promptMessage($question),
            provider: $provider,
            model: $model,
        );

        $text = trim((string) ($response->text ?? $response));

        if ($text !== '') {
            return $text;
        }

        return 'I could not find an answer in the knowledge base for that question. Try adding a name, product, or topic.';
    }

    /**
     * User message sent to the agent (optionally includes pre-retrieved context).
     */
    public function promptMessage(string $question): string
    {
        if (! config('rag-chat.chat.pre_retrieve', true)) {
            return $question;
        }

        return $this->context($question);
    }

    /**
     * The retrieval matches for a question, without generating an answer.
     *
     * @return Collection<int, array{chunk: \Awais\RagChat\Models\RagChunk, score: float}>
     */
    public function retrieve(string $question): Collection
    {
        return $this->retriever->retrieve($question);
    }

    /**
     * Build a ready-to-send context block + question string from retrieved chunks.
     */
    public function context(string $question): string
    {
        return $this->promptBuilder->build($question, $this->retriever->retrieve($question)->all());
    }

    /**
     * Provenance rows for the top retrieval matches (no LLM call).
     *
     * @return array<int, array{document_id: int, title: ?string, source: ?string, score: float, excerpt: string}>
     */
    public function sources(string $question): array
    {
        return $this->formatSources($this->retriever->retrieve($question));
    }

    /**
     * @param  Collection<int, array{chunk: \Awais\RagChat\Models\RagChunk, score: float}>  $matches
     * @return array<int, array{document_id: int, title: ?string, source: ?string, score: float, excerpt: string}>
     */
    protected function formatSources(Collection $matches): array
    {
        return $matches->map(function (array $match) {
            $chunk = $match['chunk'];

            return [
                'document_id' => (int) $chunk->document_id,
                'title' => $chunk->document?->title,
                'source' => $chunk->document?->source,
                'score' => round($match['score'], 4),
                'excerpt' => \Illuminate\Support\Str::limit($chunk->content, 200),
            ];
        })->all();
    }
}
