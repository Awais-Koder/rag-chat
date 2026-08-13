<?php

namespace Awais\RagChat\Evaluation;

/**
 * One evaluation case: a question, the expected answer (optional), and the
 * document source(s) that should surface in retrieval.
 */
class EvaluationCase
{
    /**
     * @param  list<string>  $expectedSources  document source/title strings that must appear in retrieval
     * @param  array<string, mixed>  $filters  optional retrieval metadata filters for the case
     */
    public function __construct(
        public readonly string $question,
        public readonly array $expectedSources = [],
        public readonly ?string $expectedAnswer = null,
        public readonly array $filters = [],
    ) {}
}
