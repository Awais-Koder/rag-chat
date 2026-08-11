<?php

namespace Awais\RagChat\Http\Controllers;

use Awais\RagChat\Http\Requests\ChatRequest;
use Awais\RagChat\RagChat;
use Illuminate\Http\JsonResponse;

/**
 * Thin HTTP wrapper around the package Laravel AI SDK RagAgent.
 */
class ChatController
{
    public function __invoke(ChatRequest $request, RagChat $rag): JsonResponse
    {
        $message = $request->message();

        return response()->json([
            'answer' => $rag->answer($message),
            'sources' => $rag->sources($message),
        ]);
    }
}
