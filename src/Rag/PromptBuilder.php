<?php

namespace Awais\RagChat\Rag;

use Awais\RagChat\Citations\CitationRegistry;
use Awais\RagChat\Citations\RetrievedChunk;
use Awais\RagChat\Models\RagChunk;

class PromptBuilder
{
    /**
     * Assemble the final user prompt from the question and retrieved chunks.
     *
     * The retrieved context is embedded directly in the prompt (rather than as
     * separate messages) so it works with any provider and stays stateless.
     *
     * @param  array<int, array{chunk: RagChunk, score: float}>  $matches
     */
    public function build(string $question, array $matches): string
    {
        if ($matches === []) {
            return "Context:\n(no relevant documents found)\n\n"
                ."Question: {$question}";
        }

        $context = '';

        foreach ($matches as $i => $match) {
            $n = $i + 1;
            $chunk = $match['chunk'];
            $source = $chunk->document?->title
                ?? $chunk->document?->source
                ?? "document #{$chunk->document_id}";

            $prefix = $this->chunkContext($chunk);

            $context .= "[{$n}] (source: {$source})".($prefix !== '' ? "\nContext: {$prefix}" : '')."\n{$chunk->content}\n\n";
        }

        return "Use the following context to answer the question. "
            ."If the question is vague (for example contact info without a name), list every relevant fact found in the excerpts.\n\n"
            ."Context:\n{$context}"
            ."Question: {$question}";
    }

    /**
     * Build the citation-aware prompt from chunks registered in the registry.
     *
     * Every chunk is presented with a stable [SOURCE_ID: n] identifier and its
     * public metadata (document name, page, section, source URL). The agent is
     * told to answer as JSON and may only cite these identifiers — never
     * document names or URLs it invented.
     */
    public function buildCited(string $question, CitationRegistry $registry): string
    {
        $chunks = $registry->all();

        if ($chunks === []) {
            return "Context:\n(no relevant documents found)\n\n"
                ."Question: {$question}";
        }

        $context = '';

        foreach ($chunks as $chunk) {
            $context .= $this->citedBlock($chunk);
        }

        return "You are answering a question against a knowledge base. "
            ."The excerpts below are the only sources you may use. "
            ."Answer the question using ONLY these excerpts. "
            ."If the question asks about a specific person or entity and the excerpts do not contain that person's or entity's information, "
            ."state clearly that the information is not available in the knowledge base. "
            ."Never use unrelated knowledge, assumptions, or another person's or entity's information.\n\n"
            ."Context:\n{$context}"
            ."Question: {$question}\n\n"
            ."Respond with a single JSON object in exactly this shape (and nothing else):\n"
            ."{\"answer\": \"your plain-text answer\", \"citations\": [source ids you used]}\n"
            ."- The citations array may contain ONLY the [SOURCE_ID: n] identifiers listed above that you actually used.\n"
            ."- If no excerpt supports the answer (general conversation or missing information), return an empty citations array.\n"
            ."- Never invent SOURCE_IDs, document names, page numbers, or URLs.";
    }

    /**
     * Render a single retrieved chunk as a cited context block.
     */
    protected function citedBlock(RetrievedChunk $chunk): string
    {
        $lines = [
            "[SOURCE_ID: {$chunk->sourceId}]",
            "Document: {$chunk->documentName()}",
        ];

        if ($chunk->page() !== null) {
            $lines[] = "Page: {$chunk->page()}";
        }

        if ($chunk->section() !== null) {
            $lines[] = "Section: {$chunk->section()}";
        }

        if ($chunk->sourceUrl() !== null) {
            $lines[] = "Source URL: {$chunk->sourceUrl()}";
        }

        $context = $this->chunkContext($chunk->chunk);

        if ($context !== '') {
            $lines[] = "Context: {$context}";
        }

        $lines[] = "Chunk ID: {$chunk->chunkId()}";
        $lines[] = '';
        $lines[] = "Content:\n{$chunk->chunk->content}";
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Optional contextual prefix stored on the chunk at ingest time
     * (features.contextual_chunking). Empty when the feature is off.
     */
    protected function chunkContext(RagChunk $chunk): string
    {
        $meta = is_array($chunk->meta) ? $chunk->meta : [];

        return trim((string) ($meta['context'] ?? ''));
    }
}
