<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('rag-chat.database.runs_table', 'rag_runs');

        Schema::create($table, function (Blueprint $table) {
            $table->id();
            $table->string('rag_run_id', 26)->unique(); // ULID
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->text('query');
            $table->string('status', 20)->default('completed');
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('tool_calls')->default(0);
            $table->unsignedInteger('agent_steps')->default(0);
            $table->unsignedInteger('retrievals')->default(0);
            $table->json('usage')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('rag-chat.database.runs_table', 'rag_runs'));
    }
};
