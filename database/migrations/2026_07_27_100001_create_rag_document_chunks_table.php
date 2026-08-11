<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $chunks = config('rag-chat.database.chunks_table', 'rag_document_chunks');
        $documents = config('rag-chat.database.documents_table', 'rag_documents');

        Schema::create($chunks, function (Blueprint $table) use ($documents) {
            $table->id();
            $table->foreignId('document_id')->constrained($documents)->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0); // ordinal within the document
            $table->longText('content');
            $table->json('embedding');                       // array<float>, portable JSON store
            $table->unsignedInteger('dimensions')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('rag-chat.database.chunks_table', 'rag_document_chunks'));
    }
};
