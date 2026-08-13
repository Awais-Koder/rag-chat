<?php

namespace Awais\RagChat\Http\Controllers;

use Awais\RagChat\Http\Requests\ChatRequest;
use Awais\RagChat\RagChat;
use Laravel\Ai\Responses\StreamableAgentResponse;

/**
 * Thin SSE wrapper around RagChat::stream().
 *
 * Retrieval runs first (pre-retrieved context is injected), then the
 * laravel/ai agent streams text deltas and other structured events that
 * Laravel serializes as SSE for the frontend.
 */
class ChatStreamController
{
    public function __invoke(ChatRequest $request, RagChat $rag): StreamableAgentResponse
    {
        return $rag->stream($request->message());
    }
}
