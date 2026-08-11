<?php

namespace Awais\RagChat\Rag;

/**
 * Adds supplemental retrieval queries for short or intent-only questions
 * (e.g. "contact info?" without a person name) so embeddings match factual chunks.
 */
class QueryExpander
{
    /**
     * @return list<string> Unique queries to run, always including the original question first.
     */
    public static function queries(string $question): array
    {
        $normalized = mb_strtolower(trim($question));

        if ($normalized === '') {
            return [$question];
        }

        $queries = [$question];

        if (self::looksLikeContactIntent($normalized)) {
            $queries[] = 'email phone mobile address contact information';
            $queries[] = 'contact details support line';
        }

        if (self::looksLikePricingIntent($normalized)) {
            $queries[] = 'price cost subscription payment terms pricing';
        }

        if (self::looksLikePolicyIntent($normalized)) {
            $queries[] = 'policy terms warranty refund conditions';
        }

        if (self::looksLikeHoursIntent($normalized)) {
            $queries[] = 'support hours availability schedule';
        }

        return array_values(array_unique($queries));
    }

    protected static function looksLikeContactIntent(string $normalized): bool
    {
        return self::containsAny($normalized, [
            'contact',
            'reach',
            'email',
            'phone',
            'call',
            'mobile',
            'whatsapp',
            'address',
        ]);
    }

    protected static function looksLikePricingIntent(string $normalized): bool
    {
        return self::containsAny($normalized, [
            'price',
            'pricing',
            'cost',
            'fee',
            'subscription',
            'payment',
            'how much',
        ]);
    }

    protected static function looksLikePolicyIntent(string $normalized): bool
    {
        return self::containsAny($normalized, [
            'policy',
            'refund',
            'warranty',
            'terms and conditions',
            'terms',
        ]);
    }

    protected static function looksLikeHoursIntent(string $normalized): bool
    {
        return self::containsAny($normalized, [
            'hours',
            'open',
            'availability',
            'when are you',
        ]);
    }

    /**
     * @param  list<string>  $needles
     */
    protected static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
