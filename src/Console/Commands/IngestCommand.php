<?php

namespace Awais\RagChat\Console\Commands;

use Awais\RagChat\RagChat;
use Illuminate\Console\Command;
use Throwable;

class IngestCommand extends Command
{
    protected $signature = 'rag-chat:ingest
        {path : Path to a file or directory to ingest}
        {--title= : Optional title (single-file ingestion only)}';

    protected $description = 'Ingest a file or directory of documents into the RAG store';

    public function handle(RagChat $ragChat): int
    {
        $path = $this->argument('path');

        if (! file_exists($path)) {
            $this->error("Path [{$path}] does not exist.");

            return self::FAILURE;
        }

        $meta = array_filter(['title' => $this->option('title')]);

        try {
            if (is_dir($path)) {
                $documents = $ragChat->ingest($path);

                $this->info(sprintf('Ingested %d document(s) from [%s].', count($documents), $path));

                foreach ($documents as $document) {
                    $this->line("  • {$document->title} ({$document->chunks_count} chunks)");
                }

                return self::SUCCESS;
            }

            $document = $ragChat->ingest($path, $meta);

            $this->info("Ingested [{$document->title}] — {$document->chunks_count} chunk(s).");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Ingestion failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
