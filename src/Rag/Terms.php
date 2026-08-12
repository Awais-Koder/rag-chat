<?php

namespace Awais\RagChat\Rag;

/**
 * Shared extraction of significant search terms from free text.
 *
 * Lowercases, drops stopwords and tokens shorter than three characters, and
 * deduplicates. Used by the hybrid keyword pass and the lexical reranker so
 * both judge term overlap identically.
 */
class Terms
{
    public static function extract(string $text): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];

        return collect($tokens)
            ->filter(fn (string $token) => mb_strlen($token) >= 3)
            ->reject(fn (string $token) => in_array($token, static::$stopWords, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @var list<string>
     */
    protected static array $stopWords = [
        'the', 'and', 'are', 'for', 'with', 'from', 'that', 'this', 'these',
        'those', 'can', 'could', 'would', 'should', 'does', 'did', 'have',
        'has', 'had', 'what', 'how', 'why', 'when', 'where', 'who', 'whom',
        'which', 'you', 'your', 'yours', 'tell', 'please', 'about', 'there',
        'their', 'they', 'them', 'will', 'not', 'but', 'was', 'were', 'been',
        'being', 'more', 'most', 'than', 'into', 'over', 'under', 'between',
        'very', 'just', 'get', 'got', 'give', 'gives', 'using', 'used', 'use',
        'know', 'some', 'such', 'also', 'then', 'it', 'is', 'am', 'of', 'to',
        'in', 'on', 'at', 'by', 'as', 'or', 'an', 'a', 'if', 'so', 'no',
        'yes',
    ];
}
