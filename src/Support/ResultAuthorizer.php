<?php

namespace Awais\RagChat\Support;

use Awais\RagChat\Models\RagChunk;
use Illuminate\Support\Collection;

/**
 * Shared normalization for host-registered authorization filters.
 *
 * The filter callback receives a Collection of RagChunk models and may return
 * the allowed chunks, their ids, or any iterable of either — this helper
 * reduces that to a flat list of chunk ids used by the retrieval pipeline and
 * the read-only agent tools.
 */
final class ResultAuthorizer
{
    /**
     * @param  Collection<int, RagChunk>  $candidates
     * @return list<int>
     */
    public static function allowedIds(Collection $candidates, ?callable $filter): array
    {
        if ($filter === null) {
            return $candidates->map(fn (RagChunk $chunk) => (int) $chunk->id)->all();
        }

        $allowed = collect($filter($candidates));

        return $allowed
            ->map(fn (mixed $item) => $item instanceof RagChunk ? (int) $item->id : (int) $item)
            ->all();
    }
}
