<?php

namespace Awais\RagChat\Tests;

use Awais\RagChat\RagChatServiceProvider;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            RagChatServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Deterministic, cheap embeddings + chunking for tests.
        $app['config']->set('rag-chat.embedding.dimensions', 32);
        $app['config']->set('rag-chat.chunk.size', 200);
        $app['config']->set('rag-chat.chunk.overlap', 40);
        $app['config']->set('rag-chat.retrieval.top_k', 3);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
