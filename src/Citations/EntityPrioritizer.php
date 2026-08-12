<?php

namespace Awais\RagChat\Citations;

use Illuminate\Support\Collection;

/**
 * Re-orders retrieval matches so chunks that literally mention the entity
 * being asked about rank above merely-semantic matches.
 *
 * This prevents the classic RAG failure where a question about "Muhammad
 * Awais" answers from a different person's chunk that happens to embed
 * similarly. The boost is small and additive, so high-relevance unrelated
 * chunks are still not completely hidden — only deprioritized.
 */
class EntityPrioritizer
{
    /**
     * Boost added to a match's score when its content contains an entity token.
     */
    public const BOOST = 0.10;

    /**
     * @param  Collection<int, array{chunk: mixed, score: float}>  $matches
     * @return Collection<int, array{chunk: mixed, score: float}>
     */
    public function prioritize(Collection $matches, string $question): Collection
    {
        $tokens = $this->entityTokens($question);

        if ($tokens === []) {
            return $matches;
        }

        return $matches
            ->map(function (array $match) use ($tokens) {
                $content = mb_strtolower((string) $match['chunk']->content);

                $mentions = collect($tokens)->contains(
                    fn (string $token) => str_contains($content, $token)
                );

                return [
                    'chunk' => $match['chunk'],
                    'score' => (float) $match['score'] + ($mentions ? self::BOOST : 0.0),
                ];
            })
            ->sortByDesc('score')
            ->values();
    }

    /**
     * Extract proper-noun-like tokens (capitalized words) from the question,
     * excluding common question/stop words that are not entity names.
     *
     * Shared with EntityResolver so both judge "which entity is asked about"
     * identically.
     *
     * @return list<string> lowercased entity tokens
     */
    public function entityTokens(string $question): array
    {
        $stopWords = [
            'what', 'how', 'why', 'when', 'where', 'who', 'which', 'whom',
            'the', 'are', 'you', 'your', 'and', 'for', 'with', 'from', 'that',
            'this', 'can', 'could', 'would', 'should', 'does', 'do', 'is', 'was',
            'tell', 'please', 'about', 'email', 'phone', 'contact', 'details',
            'information', 'services', 'price', 'pricing', 'cost', 'hello', 'hi',
        ];

        $tokens = preg_match_all('/\b([A-Z][a-zA-Z]{1,})\b/u', $question, $matches)
            ? $matches[1]
            : [];

        $tokens = array_map(fn (string $token) => mb_strtolower($token), $tokens);

        return collect($tokens)
            ->reject(fn (string $token) => in_array($token, $stopWords, true))
            ->unique()
            ->values()
            ->all();
    }
}
