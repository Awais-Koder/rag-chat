<?php

namespace Awais\RagChat\Tests\Feature;

use Awais\RagChat\Support\EnvInstaller;
use Awais\RagChat\Tests\TestCase;
use Illuminate\Support\Facades\File;

class InstallCommandTest extends TestCase
{
    public function test_install_publishes_rag_and_agent_assets(): void
    {
        $this->artisan('rag-chat:install', ['--no-migrate' => true, '--force' => true])
            ->assertSuccessful();

        $this->assertFileExists(config_path('rag-chat.php'));
        $this->assertFileExists(config_path('ai.php'));
        $this->assertFileExists(app_path('Ai/Agents/RagAgent.php'));

        $aiConfig = File::get(config_path('ai.php'));
        $this->assertStringContainsString("env('AI_DEFAULT', 'openrouter')", $aiConfig);
        $this->assertStringContainsString("env('AI_DEFAULT_EMBEDDINGS', 'openrouter')", $aiConfig);

        $agent = File::get(app_path('Ai/Agents/RagAgent.php'));
        $this->assertStringContainsString('SearchKnowledge::make()', $agent);
        $this->assertStringContainsString('namespace App\\Ai\\Agents', $agent);
    }

    public function test_env_installer_appends_missing_keys_only(): void
    {
        $path = storage_path('framework/testing-rag-env-'.uniqid('', true));

        File::put($path, "APP_NAME=Test\nOPENAI_API_KEY=existing\n");

        $appended = EnvInstaller::ensureKeys($path);

        $this->assertContains('AI_DEFAULT', $appended);
        $this->assertContains('OPENROUTER_API_KEY', $appended);
        $this->assertNotContains('OPENAI_API_KEY', $appended);

        $contents = File::get($path);
        $this->assertStringContainsString('OPENAI_API_KEY=existing', $contents);
        $this->assertStringContainsString('AI_DEFAULT=openrouter', $contents);

        File::delete($path);
    }
}
