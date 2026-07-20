<?php

namespace Awais\RagChat;

use Illuminate\Support\ServiceProvider;

class RagChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/rag-chat.php', 'rag-chat');

        $this->app->singleton(RagChat::class, function ($app) {
            return new RagChat();
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/rag-chat.php' => config_path('rag-chat.php'),
        ], 'rag-chat-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Awais\RagChat\Console\Commands\InstallCommand::class,
            ]);
        }
    }
}
