<?php

namespace Awais\RagChat\Jobs;

use Awais\RagChat\Models\RagDocument;
use Awais\RagChat\Rag\Ingestor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Background indexing for config('rag-chat.indexing.queue'). Receives a
 * pending RagDocument id, runs the full load -> chunk -> embed -> store
 * pipeline through the Ingestor, and marks the row indexed (or failed).
 */
class IndexDocumentJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $documentId,
    ) {}

    public function handle(Ingestor $ingestor): void
    {
        $document = RagDocument::query()->find($this->documentId);

        if ($document === null) {
            return;
        }

        $ingestor->processPending($document);
    }
}
