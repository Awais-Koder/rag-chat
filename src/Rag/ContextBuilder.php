<?php

namespace Awais\RagChat\Rag;

use Awais\RagChat\Models\RagChunk;
use Illuminate\Support\Collection;

/**
 * Expands and trims retrieval matches before the LLM context is assembled.
 *
 * Strategies (rag-chat.retrieval.context_expansion):
 *  - disabled             : matches pass through untouched (Tier 1 behaviour)
 *  - parent_only          : append each child's stored parent chunk
 *  - neighboring_chunks   : append surrounding chunks (±N positions)
 *  - parent_and_neighbors : both
 *
 * Deduplicates by chunk id, never exceeds max_context_chunks, and loads
 * parents/neighbors with bulk queries instead of one query per chunk.
 */
class ContextBuilder
{
    /**
     * @param  Collection<int, array{chunk: mixed, score: float}>  $matches
     * @return Collection<int, array{chunk: mixed, score: float}>
     */
    public function expand(Collection $matches): Collection
    {
        $strategy = (string) config('rag-chat.retrieval.context_expansion', 'disabled');

        if ($strategy === 'disabled' || $matches->isEmpty()) {
            return $matches;
        }

        if (in_array($strategy, ['parent_only', 'parent_and_neighbors'], true)) {
            $matches = $matches->concat($this->parents($matches));
        }

        if (in_array($strategy, ['neighboring_chunks', 'parent_and_neighbors'], true)) {
            $matches = $matches->concat($this->neighbors($matches));
        }

        $limit = max(1, (int) config('rag-chat.retrieval.max_context_chunks', 10));

        return $this->dedupe($matches)->take($limit)->values();
    }

    /**
     * Load the stored parent chunk of every child match in one query.
     *
     * @param  Collection<int, array{chunk: mixed, score: float}>  $matches
     * @return Collection<int, array{chunk: mixed, score: float}>
     */
    protected function parents(Collection $matches): Collection
    {
        $parents = [];

        foreach ($matches as $match) {
            $meta = is_array($match['chunk']->meta) ? $match['chunk']->meta : [];

            if (filled($meta['parent_chunk_id'] ?? null)) {
                $parents[(int) $meta['parent_chunk_id']] = (float) $match['score'];
            }
        }

        if ($parents === []) {
            return collect();
        }

        return RagChunk::query()
            ->with('document')
            ->whereIn('id', array_keys($parents))
            ->get()
            ->map(fn (RagChunk $parent) => [
                'chunk' => $parent,
                // Parents rank just below the child that pulled them in.
                'score' => $parents[(int) $parent->id] * 0.95,
            ])
            ->values();
    }

    /**
     * Load chunks around each match's position in the same document.
     *
     * Positions are grouped per document so a document's neighbor window is
     * one query instead of one per chunk (bounded by distinct documents).
     *
     * @param  Collection<int, array{chunk: mixed, score: float}>  $matches
     * @return Collection<int, array{chunk: mixed, score: float}>
     */
    protected function neighbors(Collection $matches): Collection
    {
        $radius = max(1, (int) config('rag-chat.retrieval.neighboring_chunks', 1));

        $groups = [];

        foreach ($matches as $match) {
            $chunk = $match['chunk'];
            $groups[(int) $chunk->document_id][] = [
                'position' => (int) $chunk->position,
                'score' => (float) $match['score'],
            ];
        }

        if ($groups === []) {
            return collect();
        }

        $found = [];

        foreach ($groups as $documentId => $positions) {
            $bounds = array_map(
                fn (array $position) => [$position['position'] - $radius, $position['position'] + $radius],
                $positions,
            );

            $chunks = RagChunk::query()
                ->with('document')
                ->where('document_id', $documentId)
                ->where(function ($query) use ($bounds) {
                    foreach ($bounds as [$min, $max]) {
                        $query->orWhereBetween('position', [$min, $max]);
                    }
                })
                ->get()
                // Parent chunks are pulled in by the parent strategy only.
                ->reject(fn (RagChunk $chunk) => ($chunk->meta['is_parent'] ?? false) === true);

            foreach ($chunks as $chunk) {
                $found[(int) $chunk->id] = $chunk;
            }
        }

        $scores = collect($groups)->map(
            fn (array $positions) => collect($positions)->max('score')
        );

        return collect($found)
            ->map(fn (RagChunk $chunk) => [
                'chunk' => $chunk,
                'score' => (float) $scores[(int) $chunk->document_id] * 0.9,
            ])
            ->values();
    }

    /**
     * Keep the first occurrence of each chunk (retrieved matches win over
     * their own expansions).
     *
     * @param  Collection<int, array{chunk: mixed, score: float}>  $matches
     * @return Collection<int, array{chunk: mixed, score: float}>
     */
    protected function dedupe(Collection $matches): Collection
    {
        $seen = [];

        return $matches->filter(function (array $match) use (&$seen) {
            $id = (int) $match['chunk']->id;

            if (isset($seen[$id])) {
                return false;
            }

            $seen[$id] = true;

            return true;
        })->values();
    }
}
