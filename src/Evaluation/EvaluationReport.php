<?php

namespace Awais\RagChat\Evaluation;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Aggregate metrics for one evaluation run over a dataset.
 */
class EvaluationReport implements Arrayable
{
    /**
     * @param  list<array<string, mixed>>  $cases
     */
    public function __construct(
        public readonly int $total,
        public readonly int $retrievalHits,
        public readonly array $cases,
    ) {}

    public function retrievalHitRate(): float
    {
        if ($this->total === 0) {
            return 0.0;
        }

        return round($this->retrievalHits / $this->total, 4);
    }

    /**
     * @return array{total: int, retrieval_hits: int, retrieval_hit_rate: float, cases: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'retrieval_hits' => $this->retrievalHits,
            'retrieval_hit_rate' => $this->retrievalHitRate(),
            'cases' => $this->cases,
        ];
    }
}
