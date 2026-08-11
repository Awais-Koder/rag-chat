<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Orphan web routes (not loaded by RagChatServiceProvider)
|--------------------------------------------------------------------------
|
| Chat belongs to the host application via laravel/ai agents. Package HTTP
| surface is documents ingest only — see routes/api.php.
|
*/

Route::get('/', function () {
    return response()->json([
        'package' => 'awais/rag-chat',
        'role' => 'local RAG enabler — use SearchKnowledge::make() on your Agent',
    ]);
});
