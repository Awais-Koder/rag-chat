<?php

namespace Awais\RagChat\Tools;

use Awais\RagChat\Citations\CitationRegistry;
use Awais\RagChat\Rag\Retriever;
use Awais\RagChat\Support\ResultAuthorizer;
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
     * Results carry citation-safe metadata (document name, page, section,
     * source URL) plus a stable source_id when a CitationRegistry is bound,
     * so an agent can cite tool results using the same identifiers as the
     * pre-retrieved prompt context.
     *
     * Pass a custom $description when publishing a host agent that needs different tool guidance.
     */
    public static function make(?string $description = null): SimilaritySearch
    {
        $tool = new SimilaritySearch(using: function (string $query) {
            $registry = app()->bound(CitationRegistry::class)
                ? app(CitationRegistry::class)
                : new CitationRegistry();

            $matches = app(Retriever::class)->retrieve($query);

            // Host authorization hook: drop chunks the current user may not see
            // before anything reaches the agent.
            $filter = app()->bound(RagChat::class)
                ? app(RagChat::class)->authorizationFilter()
                : null;

            $allowedIds = ResultAuthorizer::allowedIds($matches->pluck('chunk'), $filter);

            $matches = $matches
                ->filter(fn (array $match) => in_array((int) $match['chunk']->id, $allowedIds, true))
                ->values();

            return $matches
                ->map(function (array $match) use ($registry) {
                    $chunk = $match['chunk'];
                    $sourceId = $registry->register($chunk, (float) $match['score']);
                    $retrieved = $registry->retrieved($sourceId);

                    return [
                        'source_id' => $sourceId,
                        'document_id' => (int) $chunk->document_id,
                        'title' => $chunk->document?->title,
                        'document_name' => $retrieved?->documentName(),
                        'source' => $chunk->document?->source,
                        'document_type' => $retrieved?->documentType(),
                        'page' => $retrieved?->page(),
                        'section' => $retrieved?->section(),
                        'source_url' => $retrieved?->sourceUrl(),
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
