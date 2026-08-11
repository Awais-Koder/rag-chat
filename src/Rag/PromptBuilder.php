<?php

namespace Awais\RagChat\Rag;

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

            $context .= "[{$n}] (source: {$source})\n{$chunk->content}\n\n";
        }

        return "Use the following context to answer the question. "
            ."If the question is vague (for example contact info without a name), list every relevant fact found in the excerpts.\n\n"
            ."Context:\n{$context}"
            ."Question: {$question}";
    }
}
