<?php

namespace Awais\RagChat\Support;

/**
 * Request-bound project scope for multi-tenant ingest and retrieval.
 *
 * Host apps set this before ingesting or chatting so documents and search
 * stay isolated per project.
 */
class RagProjectScope
{
    protected static ?int $projectId = null;

    public static function set(?int $projectId): void
    {
        self::$projectId = $projectId;
    }

    public static function get(): ?int
    {
        return self::$projectId;
    }

    public static function clear(): void
    {
        self::$projectId = null;
    }
}
