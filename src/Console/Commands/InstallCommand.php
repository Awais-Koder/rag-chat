<?php

namespace Awais\RagChat\Console\Commands;

use Awais\RagChat\Support\EnvInstaller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallCommand extends Command
{
    protected $signature = 'rag-chat:install
        {--force : Overwrite existing published files}
        {--no-migrate : Skip running migrations}';

    protected $description = 'Install Rag Chat + Laravel AI SDK (publish, env keys, migrate, scaffold agent)';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->info('Installing Rag Chat (plug-and-play)...');
        $this->newLine();

        $this->publishLaravelAi($force);
        $this->publishRagChat($force);
        $this->publishAgentStub($force);
        $this->patchAiConfig();
        $this->ensureEnvKeys();

        if (! $this->option('no-migrate')) {
            $this->components->task('Running migrations', fn () => $this->callSilent('migrate', [
                '--force' => true,
            ]) === self::SUCCESS);
        } else {
            $this->warn('Skipped migrations (--no-migrate). Run `php artisan migrate` yourself.');
        }

        $this->printNextSteps();

        return self::SUCCESS;
    }

    protected function publishLaravelAi(bool $force): void
    {
        $this->components->task('Publishing Laravel AI SDK config + migrations', function () use ($force) {
            $this->callSilent('vendor:publish', array_filter([
                '--provider' => 'Laravel\Ai\AiServiceProvider',
                '--force' => $force ?: null,
            ]));
        });
    }

    protected function publishRagChat(bool $force): void
    {
        $this->components->task('Publishing Rag Chat config + migrations', function () use ($force) {
            $this->callSilent('vendor:publish', array_filter([
                '--tag' => 'rag-chat-config',
                '--force' => $force ?: null,
            ]));

            $this->callSilent('vendor:publish', array_filter([
                '--tag' => 'rag-chat-migrations',
                '--force' => $force ?: null,
            ]));
        });
    }

    protected function publishAgentStub(bool $force): void
    {
        $this->components->task('Publishing App\\Ai\\Agents\\RagAgent stub', function () use ($force) {
            $this->callSilent('vendor:publish', array_filter([
                '--tag' => 'rag-chat-agent',
                '--force' => $force ?: null,
            ]));
        });
    }

    protected function patchAiConfig(): void
    {
        $path = config_path('ai.php');

        if (EnvInstaller::patchAiConfigDefaults($path)) {
            $this->line('  Patched config/ai.php defaults to use AI_DEFAULT / AI_DEFAULT_EMBEDDINGS.');
        }
    }

    protected function ensureEnvKeys(): void
    {
        $appended = [];

        foreach ([base_path('.env.example'), base_path('.env')] as $path) {
            if (! File::exists($path) && ! str_ends_with($path, '.env.example')) {
                continue;
            }

            $keys = EnvInstaller::ensureKeys($path);

            if ($keys !== []) {
                $appended[$path] = $keys;
            }
        }

        if ($appended === []) {
            $this->line('  Env keys already present (or no .env / .env.example found).');

            return;
        }

        foreach ($appended as $path => $keys) {
            $this->line('  Appended to '.basename($path).': '.implode(', ', $keys));
        }
    }

    protected function printNextSteps(): void
    {
        $this->newLine();
        $this->info('Rag Chat is ready.');
        $this->newLine();
        $this->line('1) Set ONE provider API key in .env, for example:');
        $this->line('     AI_DEFAULT=openrouter');
        $this->line('     AI_DEFAULT_EMBEDDINGS=openrouter');
        $this->line('     OPENROUTER_API_KEY=sk-or-v1-...');
        $this->line('   Other options: openai, anthropic, gemini (+ matching *_API_KEY).');
        $this->newLine();
        $this->line('2) Ingest documents:');
        $this->line('     php artisan rag-chat:ingest storage/app/docs');
        $this->newLine();
        $this->line('3) Chat (Laravel AI SDK agent under the hood):');
        $this->line('     POST /rag-chat/chat  {"message":"Your question"}');
        $this->line('     or: (new \\Awais\\RagChat\\Agents\\RagAgent)->prompt(\'...\');');
        $this->newLine();
        $this->line('Customize the published agent at app/Ai/Agents/RagAgent.php if needed.');
        $this->warn('HTTP routes are unauthenticated by default — add auth middleware before going public.');
    }
}
