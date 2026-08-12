<?php

namespace Awais\RagChat\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single website crawl: its lifecycle (running → completed | failed) and
 * per-page counters so the knowledge UI can show live progress.
 */
class RagCrawlRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'discovered' => 'integer',
        'ingested' => 'integer',
        'skipped' => 'integer',
        'failed' => 'integer',
        'failed_urls' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('rag-chat.database.crawl_runs_table', 'rag_crawl_runs');
    }

    /**
     * Pages actually processed (ingested + skipped + failed).
     */
    public function processed(): int
    {
        return $this->ingested + $this->skipped + $this->failed;
    }
}
