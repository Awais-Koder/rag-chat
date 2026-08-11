<?php

namespace Awais\RagChat\Contracts;

use Illuminate\Support\Collection;

/**
 * A vector store owns how chunk embeddings are persisted and how similarity
 * search is executed. This is the seam that lets the package stay portable
 * (JSON + PHP cosine, works on every DB) while allowing a DB-native driver
 * (e.g. MySQL 9 VECTOR + DISTANCE()) to be swapped in without touching the
 * ingestion or chat pipeline.
 */
interface VectorStore
{
    /**
     * Persist embedded chunks for a document.
     *
     * @param  int  $documentId
     * @param  array<int, array{position: int, content: string, vector: array<float>, dimensions: int, meta: ?array}>  $chunks
     */
    public function insert(int $documentId, array $chunks): void;

    /**
     * Return the top-K most similar chunks to the query vector, each paired
     * with its similarity score (higher = more similar), filtered by minScore.
     *
     * @param  array<float>  $queryVector
     * @return Collection<int, array{chunk: \Awais\RagChat\Models\RagChunk, score: float}>
     */
    public function search(array $queryVector, int $topK, float $minScore): Collection;
}
