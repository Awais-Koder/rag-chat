<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch for the package HTTP routes (document ingest). When false,
    | no routes are registered — the programmatic RagChat API, SearchKnowledge
    | tool, and artisan commands still work.
    |
    */

    'enabled' => env('RAG_CHAT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Document ingestion endpoint only. Chat/agents/streaming belong to the
    | host app via laravel/ai. This endpoint incurs embedding provider cost and
    | is UNAUTHENTICATED by default — add auth middleware before going public.
    |
    */

    'route' => [
        'prefix' => env('RAG_CHAT_PREFIX', 'rag-chat'),
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | Used to embed ingested chunks and query strings via Laravel\Ai\Embeddings.
    | Leave provider/model null so config/ai.php defaults apply
    | (ai.default_for_embeddings). Set them only as an optional override.
    | Dimensions null uses the provider default. Query and chunk embeddings
    | MUST share these settings.
    |
    */

    'embedding' => [
        'provider' => env('RAG_EMBED_PROVIDER'),
        'model' => env('RAG_EMBED_MODEL'),
        'dimensions' => env('RAG_EMBED_DIMENSIONS') ? (int) env('RAG_EMBED_DIMENSIONS') : null,
        // Max inputs sent to the embeddings API per request during ingestion.
        'batch' => (int) env('RAG_EMBED_BATCH', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chunking
    |--------------------------------------------------------------------------
    |
    | How ingested documents are split before embedding. Sizes are in
    | characters. Overlap preserves context across chunk boundaries.
    |
    */

    'chunk' => [
        'size' => (int) env('RAG_CHUNK_SIZE', 1000),
        'overlap' => (int) env('RAG_CHUNK_OVERLAP', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retrieval
    |--------------------------------------------------------------------------
    |
    | top_k       : number of most-similar chunks returned by retrieve / tools.
    | min_score   : chunks below this cosine similarity are discarded (0..1).
    |
    */

    'retrieval' => [
        'top_k' => (int) env('RAG_RETRIEVAL_TOP_K', 5),
        'min_score' => (float) env('RAG_RETRIEVAL_MIN_SCORE', 0.0),
        // Extra embedding searches for intent-only questions (e.g. "contact info?").
        'expand_queries' => (bool) env('RAG_RETRIEVAL_EXPAND_QUERIES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat
    |--------------------------------------------------------------------------
    |
    | pre_retrieve : inject top retrieval chunks into the agent user message
    |                before the LLM runs (hybrid RAG). Improves vague questions
    |                where tool-only search may miss or return empty answers.
    |
    */

    'chat' => [
        'pre_retrieve' => (bool) env('RAG_CHAT_PRE_RETRIEVE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | Table names for stored documents and their embedded chunks. Portable
    | across MySQL/SQLite/Postgres — similarity is computed in PHP for the
    | default json store.
    |
    */

    'database' => [
        'documents_table' => 'rag_documents',
        'chunks_table' => 'rag_document_chunks',
    ],

    /*
    |--------------------------------------------------------------------------
    | Vector Store
    |--------------------------------------------------------------------------
    |
    | Which driver persists embeddings and executes similarity search:
    |
    |   'json'  — Portable default. Embeddings kept as JSON, cosine similarity
    |             computed in PHP. Works on SQLite/MySQL/MariaDB/Postgres.
    |   'mysql' — MySQL >= 9.0 community. Uses the native VECTOR type and
    |             DISTANCE() (COSINE) so similarity is computed in the DB
    |             engine. Reuses the same JSON column — no schema change.
    |
    | Both drivers share the portable schema, so switching only changes where
    | the distance math runs. Community MySQL has no ANN index, so neither is
    | sub-linear; 'mysql' just avoids hydrating every row into PHP.
    |
    | This is local RAG storage — distinct from Laravel\Ai\Stores / FileSearch
    | (provider-hosted vector stores).
    |
    */

    'store' => env('RAG_STORE', 'json'),

    /*
    |--------------------------------------------------------------------------
    | Ingestion
    |--------------------------------------------------------------------------
    |
    | File extensions accepted by the upload API. Keep in sync with the
    | registered DocumentLoader implementations (txt, md/markdown, pdf).
    | DOCX is not supported yet.
    |
    */

    'ingestion' => [
        'extensions' => ['txt', 'md', 'markdown', 'pdf'],
        // Max upload size in kilobytes for the document API endpoint.
        'max_upload_kb' => (int) env('RAG_MAX_UPLOAD_KB', 5120),
    ],

];
