<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('rag-chat.database.documents_table', 'rag_documents');

        Schema::table($table, function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable()->after('id');
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        $table = config('rag-chat.database.documents_table', 'rag_documents');

        Schema::table($table, function (Blueprint $table) {
            $table->dropIndex(['project_id']);
            $table->dropColumn('project_id');
        });
    }
};
