<?php

namespace Awais\RagChat\Citations;

/**
 * Validates the citation IDs returned by the LLM against the chunks that were
 * actually retrieved and shown in the prompt context.
 *
 * - Drops IDs that do not exist in the retrieved map (LLM hallucination).
 * - Deduplicates while preserving first-seen order.
 * - Maps surviving IDs back to public-safe Citation objects.
 */
class CitationValidator
{
    /**
     * @param  mixed  $rawCitations  LLM-provided citation IDs (array, list of ints/strings).
     * @param  array<int, RetrievedChunk>  $retrieved  source ID => RetrievedChunk map.
     */
    public function validate(mixed $rawCitations, array $retrieved): CitationCollection
    {
        if (! is_array($rawCitations)) {
            return new CitationCollection();
        }

        $seen = [];
        $citations = [];

        foreach ($rawCitations as $rawId) {
            if (is_array($rawId)) {
                continue;
            }

            $sourceId = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($sourceId === false || ! isset($retrieved[$sourceId]) || isset($seen[$sourceId])) {
                continue;
            }

            $seen[$sourceId] = true;
            $citations[] = Citation::fromRetrievedChunk($retrieved[$sourceId]);
        }

        return new CitationCollection($citations);
    }
}
