<?php

namespace Awais\RagChat\Support;

/**
 * Appends missing AI / RAG env keys without overwriting existing values.
 */
class EnvInstaller
{
    /**
     * Keys and default values written during plug-and-play install.
     *
     * @return array<string, string>
     */
    public static function defaultKeys(): array
    {
        return [
            'AI_DEFAULT' => 'openrouter',
            'AI_DEFAULT_EMBEDDINGS' => 'openrouter',
            'OPENROUTER_API_KEY' => '',
            'OPENAI_API_KEY' => '',
            'ANTHROPIC_API_KEY' => '',
            'GEMINI_API_KEY' => '',
            'RAG_CHAT_ENABLED' => 'true',
            'RAG_STORE' => 'json',
        ];
    }

    /**
     * Ensure each key exists in the given env file. Existing values are kept.
     *
     * @return list<string> Keys that were appended
     */
    public static function ensureKeys(string $path): array
    {
        if (! is_file($path)) {
            $dir = dirname($path);

            if (! is_dir($dir)) {
                return [];
            }

            file_put_contents($path, '');
        }

        $contents = (string) file_get_contents($path);
        $appended = [];
        $block = '';

        foreach (self::defaultKeys() as $key => $value) {
            if (preg_match('/^'.preg_quote($key, '/').'\s*=/m', $contents) === 1) {
                continue;
            }

            $appended[] = $key;
            $block .= $key.'='.$value.PHP_EOL;
        }

        if ($block === '') {
            return [];
        }

        $prefix = '';

        if (! str_contains($contents, 'AI_DEFAULT') && ! str_contains($contents, 'RAG_CHAT_ENABLED')) {
            $prefix = PHP_EOL.'# Laravel AI + RAG Chat (set ONE provider key + AI_DEFAULT)'.PHP_EOL;
        }

        $separator = $contents === '' || str_ends_with($contents, PHP_EOL) ? '' : PHP_EOL;

        file_put_contents($path, $contents.$separator.$prefix.$block);

        return $appended;
    }

    /**
     * Patch published config/ai.php so defaults read from AI_DEFAULT / AI_DEFAULT_EMBEDDINGS.
     */
    public static function patchAiConfigDefaults(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $contents = (string) file_get_contents($path);
        $original = $contents;

        $contents = preg_replace(
            "/'default'\s*=>\s*'[^']*'/",
            "'default' => env('AI_DEFAULT', 'openrouter')",
            $contents,
            1
        ) ?? $contents;

        $contents = preg_replace(
            "/'default_for_embeddings'\s*=>\s*'[^']*'/",
            "'default_for_embeddings' => env('AI_DEFAULT_EMBEDDINGS', 'openrouter')",
            $contents,
            1
        ) ?? $contents;

        if ($contents === $original) {
            return false;
        }

        file_put_contents($path, $contents);

        return true;
    }
}
