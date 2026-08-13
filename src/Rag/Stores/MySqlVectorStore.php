<?php

namespace Awais\RagChat\Rag\Stores;

use Awais\RagChat\Contracts\VectorStore;
use Awais\RagChat\Models\RagChunk;
use Awais\RagChat\Rag\Stores\Concerns\InsertsJsonEmbeddings;
use Awais\RagChat\Support\RagProjectScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * DB-native store for MySQL >= 9.0 community, which ships the VECTOR type and
 * a DISTANCE() function (COSINE metric). Distance is computed inside the DB
 * engine instead of in PHP.
 *
 * It reuses the portable `embedding` JSON column verbatim: STRING_TO_VECTOR()
 * parses the stored JSON-array string on the fly, so no schema change or
 * separate VECTOR column is required to opt in.
 *
 * Note: community MySQL has no ANN vector index, so DISTANCE() still performs a
 * full scan. The win over the JSON store is engine-side computation (no row
 * hydration into PHP), not sub-linear search.
 */
class MySqlVectorStore implements VectorStore
{
    use InsertsJsonEmbeddings;

    public function search(array $queryVector, int $topK, float $minScore): Collection
    {
        $rows = $this->newSearchQuery($queryVector, $topK, $minScore)->get();

        return $rows->map(fn (RagChunk $chunk) => [
            'chunk' => $chunk,
            'score' => (float) $chunk->similarity,
        ])->values()->collect();
    }

    /**
     * Build the MySQL DISTANCE() search query without executing it.
     *
     * @param  array<float>  $queryVector
     */
    public function newSearchQuery(array $queryVector, int $topK, float $minScore): Builder
    {
        $table = (new RagChunk)->getTable();

        // COSINE distance is in [0, 2]; similarity = 1 - distance maps it back
        // to the [-1, 1] cosine-similarity convention the rest of the package
        // uses, so min_score behaves identically across drivers.
        $queryJson = json_encode(array_values($queryVector));

        $query = RagChunk::query()
            ->select(['id', 'document_id', 'position', 'content', 'embedding', 'meta'])
            ->selectRaw(
                "(1 - DISTANCE(STRING_TO_VECTOR(`{$table}`.`embedding`), STRING_TO_VECTOR(?), 'COSINE')) as similarity",
                [$queryJson]
            )
            ->whereRaw(
                "(1 - DISTANCE(STRING_TO_VECTOR(`{$table}`.`embedding`), STRING_TO_VECTOR(?), 'COSINE')) >= ?",
                [$queryJson, $minScore]
            )
            ->orderByDesc('similarity')
            ->limit($topK);

        if ($projectId = RagProjectScope::get()) {
            $query->whereHas('document', fn ($documents) => $documents->where('project_id', $projectId));
        }

        return $query;
    }
}
