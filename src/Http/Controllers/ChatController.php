<?php

namespace Awais\RagChat\Http\Controllers;

use Awais\RagChat\Http\Requests\ChatRequest;
use Awais\RagChat\RagChat;
use Illuminate\Http\JsonResponse;

/**
 * Thin HTTP wrapper around the package Laravel AI SDK RagAgent.
 *
 * The response is backward compatible: `answer` and `sources` are unchanged,
 * and a new `citations` array is added alongside them.
 */
class ChatController
{
    public function __invoke(ChatRequest $request, RagChat $rag): JsonResponse
    {
        $message = $request->message();
        $response = $rag->respond($message);

        return response()->json([
            'answer' => $response->answer,
            'citations' => $response->citations->toArray(),
            'sources' => $response->sources,
        ]);
    }
}
