<?php

namespace Awais\RagChat\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Optional persisted form of Support\RagRun, written when
 * config('rag-chat.usage_tracking.persist') is enabled. Correlates queries,
 * retrieval, tokens, tool calls, and latency for production debugging.
 */
class RagRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'usage' => 'array',
        'latency_ms' => 'float',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'tool_calls' => 'integer',
        'agent_steps' => 'integer',
        'retrievals' => 'integer',
    ];

    public function getTable(): string
    {
        return config('rag-chat.database.runs_table', 'rag_runs');
    }
}
