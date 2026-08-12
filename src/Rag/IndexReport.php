<?php

namespace Awais\RagChat\Rag;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Summary of one ingestion run: how many chunks were added, updated, removed,
 * or left unchanged (embedding reused), enabling incremental indexing
 * observability.
 */
class IndexReport implements Arrayable
{
    public function __construct(
        public readonly int $added = 0,
        public readonly int $updated = 0,
        public readonly int $removed = 0,
        public readonly int $unchanged = 0,
        public readonly int $reusedEmbeddings = 0,
    ) {
    }

    public function total(): int
    {
        return $this->added + $this->updated + $this->removed + $this->unchanged;
    }

    /**
     * @return array{added: int, updated: int, removed: int, unchanged: int, reused_embeddings: int}
     */
    public function toArray(): array
    {
        return [
            'added' => $this->added,
            'updated' => $this->updated,
            'removed' => $this->removed,
            'unchanged' => $this->unchanged,
            'reused_embeddings' => $this->reusedEmbeddings,
        ];
    }
}
