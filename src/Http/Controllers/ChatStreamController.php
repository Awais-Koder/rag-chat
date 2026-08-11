<?php

namespace Awais\RagChat\Http\Controllers;

use Awais\RagChat\Agents\RagAgent;
use Awais\RagChat\RagChat;
use Awais\RagChat\Http\Requests\ChatRequest;
use Laravel\Ai\Responses\StreamableAgentResponse;

/**
 * Thin SSE wrapper around the package Laravel AI SDK RagAgent.
 */
class ChatStreamController
{
    public function __invoke(ChatRequest $request, RagChat $rag): StreamableAgentResponse
    {
        return (new RagAgent)->stream($rag->promptMessage($request->message()));
    }
}
