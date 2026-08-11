<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('rag-chat.database.documents_table', 'rag_documents');

        Schema::create($table, function (Blueprint $table) {
            $table->id();
            $table->string('source')->nullable(); // path or origin identifier
            $table->string('title')->nullable();
            $table->string('checksum', 64)->nullable(); // sha256 of raw content for dedupe
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('checksum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('rag-chat.database.documents_table', 'rag_documents'));
    }
};
