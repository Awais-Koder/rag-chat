<?php

namespace Awais\RagChat\Tools;

use Awais\RagChat\Rag\Retriever;
use Laravel\Ai\Tools\SimilaritySearch;

/**
 * Factory for a Laravel AI SDK SimilaritySearch tool backed by this package's
 * local RAG retriever. Host agents add it via HasTools — chat stays with the SDK.
 */
class SearchKnowledge
{
    /**
     * Build a SimilaritySearch tool that queries the ingested corpus.
     *
     * Pass a custom $description when publishing a host agent that needs different tool guidance.
     */
    public static function make(?string $description = null): SimilaritySearch
    {
        $tool = new SimilaritySearch(using: function (string $query) {
            return app(Retriever::class)
                ->retrieve($query)
                ->map(function (array $match) {
                    $chunk = $match['chunk'];

                    return [
                        'document_id' => (int) $chunk->document_id,
                        'title' => $chunk->document?->title,
                        'source' => $chunk->document?->source,
                        'score' => round($match['score'], 4),
                        'content' => $chunk->content,
                    ];
                })
                ->values()
                ->all();
        });

        return $tool->withDescription(
            $description ?? 'Search locally ingested documents (not the open web) for text similar to the query. '
                .'Use a retrieval-oriented query string: keywords, entity names, and field labels (e.g. phone, email, price, refund policy), '
                .'not necessarily the user\'s exact short phrase. '
                .'Call this tool multiple times with different queries when the question is vague or the first search returns little useful content.'
        );
    }
}
