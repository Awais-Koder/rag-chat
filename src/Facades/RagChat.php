<?php

namespace Awais\RagChat\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Awais\RagChat\Models\RagDocument|\Awais\RagChat\Models\RagDocument[] ingest(string $path, array $meta = [])
 * @method static \Awais\RagChat\Models\RagDocument ingestText(string $text, string $source, ?string $title = null, array $meta = [])
 * @method static string answer(string $question, \Laravel\Ai\Enums\Lab|array|string|null $provider = null, ?string $model = null)
 * @method static \Illuminate\Support\Collection retrieve(string $question)
 * @method static string context(string $question)
 * @method static array sources(string $question)
 *
 * @see \Awais\RagChat\RagChat
 */
class RagChat extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Awais\RagChat\RagChat::class;
    }
}
