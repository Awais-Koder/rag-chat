<?php

namespace Awais\RagChat\Rag;

class Similarity
{
    /**
     * Cosine similarity between two equal-length vectors. Returns a value in
     * [-1, 1]; 0.0 if either vector has zero magnitude or lengths mismatch.
     *
     * @param  array<float>  $a
     * @param  array<float>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        $length = count($a);

        if ($length === 0 || $length !== count($b)) {
            return 0.0;
        }

        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;

        // Index-based loop over aligned vectors; both are dense lists.
        $bValues = array_values($b);
        $i = 0;

        foreach (array_values($a) as $av) {
            $bv = $bValues[$i++];
            $dot += $av * $bv;
            $magA += $av * $av;
            $magB += $bv * $bv;
        }

        if ($magA <= 0.0 || $magB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($magA) * sqrt($magB));
    }
}
