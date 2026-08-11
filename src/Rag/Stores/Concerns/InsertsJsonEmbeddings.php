<?php

namespace Awais\RagChat\Rag\Stores\Concerns;

use Awais\RagChat\Models\RagChunk;

/**
 * Shared insert path for stores that persist embeddings as JSON arrays.
 * Both the portable JSON store and the MySQL DISTANCE() store reuse this
 * column format, so inserts stay identical across drivers.
 */
trait InsertsJsonEmbeddings
{
    /**
     * @param  array<int, array{position: int, content: string, vector: array<float>, dimensions: int, meta: ?array}>  $chunks
     */
    public function insert(int $documentId, array $chunks): void
    {
        $rows = [];

        foreach ($chunks as $chunk) {
            $rows[] = [
                'document_id' => $documentId,
                'position' => $chunk['position'],
                'content' => $chunk['content'],
                'embedding' => json_encode($chunk['vector']),
                'dimensions' => $chunk['dimensions'],
                'meta' => isset($chunk['meta']) ? json_encode($chunk['meta']) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Insert in reasonable batches to avoid oversized single statements.
        foreach (array_chunk($rows, 500) as $batch) {
            RagChunk::insert($batch);
        }
    }
}
