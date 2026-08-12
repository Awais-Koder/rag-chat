<?php

namespace Awais\RagChat\Rag;

use Awais\RagChat\Citations\EntityPrioritizer;

/**
 * Heuristic multi-query generation — no LLM call, so it is safe on local
 * models. Variations are: the original query, intent-based expansions
 * (QueryExpander), and the extracted entity phrase. Output is deduplicated,
 * original first, and capped at the configured count so simple questions
 * generate few or no extra queries.
 */
class MultiQuery
{
    /**
     * @return list<string>
     */
    public static function queries(string $query, int $count = 3): array
    {
        if ($count <= 1) {
            return [$query];
        }

        $queries = [$query];

        // The entity phrase is the highest-value variation for entity
        // questions, so it outranks generic intent expansions.
        $entity = implode(' ', (new EntityPrioritizer)->entityTokens($query));

        if ($entity !== '') {
            $queries[] = $entity;
        }

        foreach (QueryExpander::queries($query) as $variant) {
            $queries[] = $variant;
        }

        return array_slice(array_values(array_unique($queries)), 0, max(1, $count));
    }
}
