<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('rag-chat.database.crawl_runs_table', 'rag_crawl_runs');

        Schema::create($table, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->string('seed_url');
            $table->string('status')->default('running')->index(); // running | completed | failed
            $table->integer('discovered')->default(0);
            $table->integer('ingested')->default(0);
            $table->integer('skipped')->default(0);
            $table->integer('failed')->default(0);
            $table->json('failed_urls')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('rag-chat.database.crawl_runs_table', 'rag_crawl_runs'));
    }
};
