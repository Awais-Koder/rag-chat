<?php

use Awais\RagChat\Http\Controllers\ChatController;
use Awais\RagChat\Http\Controllers\ChatStreamController;
use Awais\RagChat\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| RAG routes
|--------------------------------------------------------------------------
|
| Document ingest + thin chat wrappers around Awais\RagChat\Agents\RagAgent
| (Laravel AI SDK). Providers come from config/ai.php — not this package.
| UNAUTHENTICATED by default — add auth middleware before going public.
|
*/

Route::post('/chat', ChatController::class)->name('rag-chat.chat');
Route::post('/chat/stream', ChatStreamController::class)->name('rag-chat.chat.stream');
Route::post('/documents', DocumentController::class)->name('rag-chat.documents.store');
