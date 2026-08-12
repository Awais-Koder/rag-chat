<?php

namespace Awais\RagChat\Support;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;

/**
 * Execution metadata for one chat turn: a ULID rag_run_id used to correlate
 * retrieval, tool calls, model execution, citations, and latency.
 *
 * Always built in-memory; optionally persisted to the rag_runs table via
 * config('rag-chat.usage_tracking.persist'). Provider token usage is nullable
 * because not every provider reports it.
 */
class RagRun implements Arrayable
{
    public string $id;

    public string $query;

    /** completed | ungrounded | error | cached */
    public string $status = 'completed';

    public float $startedAt;

    public ?float $endedAt = null;

    /** @var array<string, mixed> */
    public array $usage = [];

    public int $toolCalls = 0;

    public int $agentSteps = 0;

    public int $retrievals = 0;

    public ?string $error = null;

    public function __construct(string $query)
    {
        $this->id = (string) Str::ulid();
        $this->query = $query;
        $this->startedAt = microtime(true);
    }

    public function latencyMs(): ?float
    {
        if ($this->endedAt === null) {
            return null;
        }

        return round(($this->endedAt - $this->startedAt) * 1000, 2);
    }

    public function complete(array $usage = [], int $toolCalls = 0, int $agentSteps = 0, int $retrievals = 0): static
    {
        $this->endedAt = microtime(true);
        $this->usage = $usage;
        $this->toolCalls = $toolCalls;
        $this->agentSteps = $agentSteps;
        $this->retrievals = $retrievals;

        return $this;
    }

    public function fail(?\Throwable $exception = null): static
    {
        $this->endedAt = microtime(true);
        $this->status = 'error';
        $this->error = $exception?->getMessage();

        return $this;
    }

    /**
     * @return array{rag_run_id: string, query: string, status: string, latency_ms: float|null, usage: array<string, mixed>, tool_calls: int, agent_steps: int, retrievals: int, error: string|null}
     */
    public function toArray(): array
    {
        return [
            'rag_run_id' => $this->id,
            'query' => $this->query,
            'status' => $this->status,
            'latency_ms' => $this->latencyMs(),
            'usage' => $this->usage,
            'tool_calls' => $this->toolCalls,
            'agent_steps' => $this->agentSteps,
            'retrievals' => $this->retrievals,
            'error' => $this->error,
        ];
    }
}
