<?php

namespace Awais\RagChat;

use Awais\RagChat\Citations\CitationRegistry;
use Awais\RagChat\Contracts\VectorStore;
use Awais\RagChat\Crawl\HtmlExtractor;
use Awais\RagChat\Crawl\SiteCrawler;
use Awais\RagChat\Crawl\UrlDiscoverer;
use Awais\RagChat\Rag\Chunker;
use Awais\RagChat\Rag\ContextBuilder;
use Awais\RagChat\Rag\Embedder;
use Awais\RagChat\Rag\Ingestor;
use Awais\RagChat\Rag\LoaderManager;
use Awais\RagChat\Rag\PromptBuilder;
use Awais\RagChat\Rag\Retriever;
use Awais\RagChat\Rag\Stores\JsonVectorStore;
use Awais\RagChat\Rag\Stores\MySqlVectorStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class RagChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/rag-chat.php',
            'rag-chat'
        );

        // RAG engine bindings. Each resolves from config so the host app can
        // tune providers/models/chunking without touching the container.
        $this->app->singleton(Embedder::class, fn () => Embedder::fromConfig());

        $this->app->singleton(Chunker::class, fn () => new Chunker(
            size: (int) config('rag-chat.chunk.size', 1000),
            overlap: (int) config('rag-chat.chunk.overlap', 200),
        ));

        $this->app->singleton(LoaderManager::class, fn () => new LoaderManager());

        // Vector store driver: portable JSON by default, DB-native opt-in.
        $this->app->singleton(VectorStore::class, fn ($app) => $this->resolveStore($app));

        $this->app->singleton(Ingestor::class, fn ($app) => new Ingestor(
            $app->make(LoaderManager::class),
            $app->make(Chunker::class),
            $app->make(Embedder::class),
            $app->make(VectorStore::class),
        ));

        $this->app->bind(Retriever::class, fn ($app) => new Retriever(
            $app->make(Embedder::class),
            $app->make(VectorStore::class),
        ));

        $this->app->bind(PromptBuilder::class, fn () => new PromptBuilder());

        $this->app->bind(ContextBuilder::class, fn () => new ContextBuilder());

        // One citation registry per request so the prompt context and the
        // SearchKnowledge tool share the same source ID mapping.
        $this->app->scoped(CitationRegistry::class, fn () => new CitationRegistry());

        $this->app->singleton(RagChat::class, function ($app) {
            return new RagChat(
                $app->make(Ingestor::class),
                $app->make(Retriever::class),
                $app->make(PromptBuilder::class),
                $app->make(CitationRegistry::class),
                $app->make(ContextBuilder::class),
            );
        });

        $this->app->alias(RagChat::class, 'rag-chat');

        // Website crawler: sitemap-first discovery + HTML text extraction,
        // ingesting pages through the same Ingestor pipeline as file uploads.
        $this->app->singleton(SiteCrawler::class, fn ($app) => new SiteCrawler(
            new UrlDiscoverer(),
            new HtmlExtractor(),
            $app->make(Ingestor::class),
        ));
    }

    public function boot(): void
    {
        // Keep upload validation extensions aligned with registered loaders.
        config([
            'rag-chat.ingestion.extensions' => $this->app->make(LoaderManager::class)->supportedExtensions(),
        ]);

        if (config('rag-chat.enabled')) {
            $this->loadRoutes();
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->publishes([
            __DIR__ . '/../config/rag-chat.php' => config_path('rag-chat.php'),
        ], 'rag-chat-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'rag-chat-migrations');

        $this->publishes([
            __DIR__ . '/../stubs/RagAgent.stub' => app_path('Ai/Agents/RagAgent.php'),
        ], 'rag-chat-agent');

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Awais\RagChat\Console\Commands\InstallCommand::class,
                \Awais\RagChat\Console\Commands\IngestCommand::class,
            ]);
        }

        $this->warnIfMysqlStoreUnsupported();
    }

    /**
     * The mysql vector store requires MySQL >= 9.0 (DISTANCE / STRING_TO_VECTOR).
     * Log a warning when the configured connection cannot satisfy that.
     */
    protected function warnIfMysqlStoreUnsupported(): void
    {
        if (config('rag-chat.store', 'json') !== 'mysql') {
            return;
        }

        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();

            if ($driver !== 'mysql') {
                Log::warning("rag-chat store is [mysql] but the default DB driver is [{$driver}]. Falling back behaviour will fail at search time — switch RAG_STORE=json or use MySQL >= 9.0.");

                return;
            }

            $version = (string) $connection->selectOne('select version() as v')?->v;
            $major = (int) explode('.', $version)[0];

            if ($major < 9) {
                Log::warning("rag-chat store is [mysql] but server version is [{$version}]. MySQL >= 9.0 is required for DISTANCE()/STRING_TO_VECTOR(). Set RAG_STORE=json for portable PHP cosine search.");
            }
        } catch (\Throwable $e) {
            Log::warning('rag-chat could not verify MySQL version for the mysql store: '.$e->getMessage());
        }
    }

    protected function loadRoutes(): void
    {
        Route::middleware(config('rag-chat.route.middleware'))
            ->prefix(config('rag-chat.route.prefix'))
            ->group(__DIR__ . '/../routes/api.php');
    }

    /**
     * Resolve the configured vector store driver. Defaults to the portable
     * JSON store; 'mysql' opts into DB-native DISTANCE() search. Custom drivers
     * may be bound to the VectorStore contract by the host app before this runs.
     */
    protected function resolveStore($app): VectorStore
    {
        $driver = config('rag-chat.store', 'json');

        return match ($driver) {
            'json' => new JsonVectorStore(),
            'mysql' => new MySqlVectorStore(),
            default => throw new InvalidArgumentException(
                "Unknown rag-chat vector store driver [{$driver}]. Supported: json, mysql."
            ),
        };
    }
}
