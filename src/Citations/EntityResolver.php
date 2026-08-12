<?php

namespace Awais\RagChat\Citations;

use Illuminate\Support\Collection;

/**
 * Decides whether the entity named in a question is actually present in the
 * retrieved evidence, so the pipeline never hands the LLM evidence about a
 * different person/entity and asks it to answer anyway.
 *
 * Verdicts:
 *  - no_entity : the question names no proper-noun entity — nothing to gate
 *  - supported : at least one retrieved chunk mentions the full entity
 *  - missing   : the entity never appears in the retrieved content
 *  - ambiguous : the same name maps to distinct full names across chunks
 */
class EntityResolver
{
    /**
     * @param  Collection<int, array{chunk: mixed, score: float}>  $matches
     * @return array{status: string, candidates: list<string>}
     */
    public function resolve(string $question, Collection $matches): array
    {
        $tokens = (new EntityPrioritizer)->entityTokens($question);

        if ($tokens === []) {
            return ['status' => 'no_entity', 'candidates' => []];
        }

        $mentioning = $matches->filter(
            fn (array $match) => $this->mentions((string) $match['chunk']->content, $tokens)
        );

        if ($mentioning->isEmpty()) {
            return ['status' => 'missing', 'candidates' => []];
        }

        // One full-name candidate per document: two names in a single chunk
        // are usually the same person's context ("Muhammad Awais … his
        // brother Awais Koder"), not ambiguity.
        // ponytail: per-document first-name only — a single document that
        // genuinely covers two people with the same first name is treated as
        // supported; add per-document candidate counts if that case matters.
        $candidates = $mentioning
            ->map(function (array $match) use ($tokens) {
                $names = $this->fullNames((string) $match['chunk']->content, $tokens);

                return $names[0] ?? null;
            })
            ->filter()
            ->unique()
            ->values();

        if ($candidates->count() >= 2) {
            return ['status' => 'ambiguous', 'candidates' => $candidates->all()];
        }

        return ['status' => 'supported', 'candidates' => []];
    }

    /**
     * Every token of the asked entity must appear in a chunk for it to count
     * as evidence about that entity — a chunk about "John" alone is not
     * evidence about "John Smith", and never mixes people who share a name.
     *
     * @param  list<string>  $tokens
     */
    protected function mentions(string $content, array $tokens): bool
    {
        $content = mb_strtolower($content);

        return collect($tokens)->every(
            fn (string $token) => str_contains($content, $token)
        );
    }

    /**
     * Find "Token Surname" style full names in content around the asked tokens.
     *
     * Case-insensitive to match mentions() — all-caps documents count too.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    protected function fullNames(string $content, array $tokens): array
    {
        $names = [];

        foreach ($tokens as $token) {
            $pattern = '/\b'.preg_quote(ucfirst($token), '/').'\s+[A-Z][a-z]+\b/iu';

            if (preg_match_all($pattern, $content, $hits)) {
                array_push($names, ...$hits[0]);
            }
        }

        return $names;
    }
}
