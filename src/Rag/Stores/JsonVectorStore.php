<?php

namespace Awais\RagChat\Rag\Stores;

use Awais\RagChat\Contracts\VectorStore;
use Awais\RagChat\Models\RagChunk;
use Awais\RagChat\Rag\Similarity;
use Awais\RagChat\Rag\Stores\Concerns\InsertsJsonEmbeddings;
use Awais\RagChat\Support\RagProjectScope;
use Illuminate\Support\Collection;

/**
 * Portable default store. Embeddings are kept as JSON and cosine similarity is
 * computed in PHP, so it behaves identically on SQLite/MySQL/MariaDB/Postgres.
 * Retrieval is an O(n) scan — fine for modest corpora; swap in a DB-native
 * driver for larger-scale search.
 */
class JsonVectorStore implements VectorStore
{
    use InsertsJsonEmbeddings;

    public function search(array $queryVector, int $topK, float $minScore): Collection
    {
        $query = RagChunk::query()
            ->select(['id', 'document_id', 'position', 'content', 'embedding', 'meta']);

        if ($projectId = RagProjectScope::get()) {
            $query->whereHas('document', fn ($documents) => $documents->where('project_id', $projectId));
        }

        return $query
            ->lazy()
            ->map(fn (RagChunk $chunk) => [
                'chunk' => $chunk,
                'score' => Similarity::cosine($queryVector, $chunk->embedding ?? []),
            ])
            ->filter(fn (array $row) => $row['score'] >= $minScore)
            ->sortByDesc('score')
            ->take($topK)
            ->values()
            ->collect();
    }
}
