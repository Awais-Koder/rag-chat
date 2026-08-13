<?php

namespace Awais\RagChat\Evaluation;

use Awais\RagChat\Rag\Retriever;
use Illuminate\Support\Collection;

/**
 * Offline evaluation of a knowledge base against a dataset of cases (Tier 4).
 *
 * For every case it runs real retrieval and reports:
 *  - retrieval hit: did an expected source surface in the top-K matches?
 *  - best score: the top match's similarity for the case
 *  - retrieved sources: which document sources came back
 *
 * Answer relevance / citation correctness can be layered on top by passing a
 * custom per-case evaluator callback (see evaluate()).
 */
class RagEvaluator
{
    public function __construct(protected Retriever $retriever) {}

    /**
     * @param  list<EvaluationCase>  $dataset
     * @param  callable(array{question: string, expected_answer: ?string, matches: Collection}): array<string, mixed>|null  $perCase
     */
    public function evaluate(array $dataset, ?callable $perCase = null): EvaluationReport
    {
        $topK = (int) config('rag-chat.evaluation.top_k', 5);

        $rows = [];
        $hits = 0;

        foreach ($dataset as $case) {
            $matches = $this->retriever->retrieve($case->question, $case->filters)->take($topK);

            $sources = $matches
                ->map(fn (array $match) => (string) (
                    $match['chunk']->document?->source
                    ?? $match['chunk']->document?->title
                    ?? ''
                ))
                ->filter()
                ->unique()
                ->values()
                ->all();

            $hit = $this->hit($case->expectedSources, $sources);
            $hits += $hit ? 1 : 0;

            $row = [
                'question' => $case->question,
                'expected_sources' => $case->expectedSources,
                'retrieval_hit' => $hit,
                'best_score' => round((float) ($matches->first()['score'] ?? 0.0), 4),
                'retrieved_sources' => $sources,
            ];

            if ($perCase !== null) {
                $row = array_merge($row, $perCase([
                    'question' => $case->question,
                    'expected_answer' => $case->expectedAnswer,
                    'matches' => $matches,
                ]));
            }

            $rows[] = $row;
        }

        return new EvaluationReport(
            total: count($rows),
            retrievalHits: $hits,
            cases: $rows,
        );
    }

    /**
     * Any expected source must appear among the retrieved document sources.
     *
     * @param  list<string>  $expectedSources
     * @param  list<string>  $retrievedSources
     */
    protected function hit(array $expectedSources, array $retrievedSources): bool
    {
        if ($expectedSources === []) {
            return true;
        }

        $haystack = implode("\n", $retrievedSources);

        foreach ($expectedSources as $expected) {
            if ($expected !== '' && str_contains(mb_strtolower($haystack), mb_strtolower($expected))) {
                return true;
            }
        }

        return false;
    }
}
