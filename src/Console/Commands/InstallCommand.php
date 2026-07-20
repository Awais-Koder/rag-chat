<?php

namespace Awais\RagChat\Console\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'rag-chat:install';

    protected $description = 'Install the Rag Chat package';

    public function handle(): int
    {
        $this->info('Installing Rag Chat...');

        $this->call('vendor:publish', [
            '--tag' => 'rag-chat-config',
        ]);

        $this->newLine();

        $this->info('✅ Rag Chat installed successfully.');

        return self::SUCCESS;
    }
}