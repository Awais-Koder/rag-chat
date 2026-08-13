<?php

use Awais\RagChat\Models\RagRun;

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

    /*
    |--------------------------------------------------------------------------
    | Features (Tier 2)
    |--------------------------------------------------------------------------
    |
    | Advanced RAG capabilities. All off by default — enabling any of them
    | only affects newly ingested documents / new queries, and existing
    | simple chunks keep working untouched.
    |
    */

    'features' => [
        // Generate multiple search query variations (heuristic, no LLM call)
        // and merge the results.
        'multi_query' => (bool) env('RAG_FEATURE_MULTI_QUERY', false),
        // Store parent chunks that group consecutive children so context
        // expansion can widen a retrieved child with its parent section.
        'parent_child' => (bool) env('RAG_FEATURE_PARENT_CHILD', false),
        // Attach a "Document: …" context prefix to every chunk's metadata.
        'contextual_chunking' => (bool) env('RAG_FEATURE_CONTEXTUAL_CHUNKING', false),
    ],

    'retrieval' => [
        'top_k' => (int) env('RAG_RETRIEVAL_TOP_K', 5),
        'min_score' => (float) env('RAG_RETRIEVAL_MIN_SCORE', 0.0),
        // Extra embedding searches for intent-only questions (e.g. "contact info?").
        'expand_queries' => (bool) env('RAG_RETRIEVAL_EXPAND_QUERIES', true),
        // Number of variations generated when features.multi_query is enabled.
        'multi_query_count' => (int) env('RAG_RETRIEVAL_MULTI_QUERY_COUNT', 3),
        // Hybrid search: merge a portable exact-keyword pass (LIKE on chunk
        // content) with the vector results, so names, emails, phones, and
        // other exact tokens are not lost to embedding blur.
        'hybrid' => [
            'enabled' => (bool) env('RAG_RETRIEVAL_HYBRID', true),
            // Base score given to a keyword hit; rises toward 1.0 as more
            // query terms match the chunk.
            'keyword_weight' => (float) env('RAG_RETRIEVAL_KEYWORD_WEIGHT', 0.8),
        ],
        // Reranking stage after retrieval. null/'none' disables it, 'lexical'
        // uses the built-in term-overlap reranker, or set a Reranker
        // implementation class-string to plug in a custom one.
        'reranker' => env('RAG_RETRIEVAL_RERANKER'),
        // Grounding floor: when the best match scores below this, respond
        // with the not-found message instead of calling the LLM. null = off.
        'min_evidence_score' => env('RAG_RETRIEVAL_MIN_EVIDENCE') !== null && env('RAG_RETRIEVAL_MIN_EVIDENCE') !== ''
            ? (float) env('RAG_RETRIEVAL_MIN_EVIDENCE')
            : null,
        // Widen retrieval results before building the LLM context:
        //   disabled | parent_only | neighboring_chunks | parent_and_neighbors
        'context_expansion' => env('RAG_CONTEXT_EXPANSION', 'disabled'),
        // Chunks before/after a match pulled in by neighboring_chunks.
        'neighboring_chunks' => (int) env('RAG_CONTEXT_NEIGHBORS', 1),
        // Hard cap on chunks sent to the LLM after expansion.
        'max_context_chunks' => (int) env('RAG_CONTEXT_MAX_CHUNKS', 10),
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
        // Message used when the knowledge base holds no usable evidence for a
        // question (entity absent, or best match below min_evidence_score).
        'not_found' => env(
            'RAG_CHAT_NOT_FOUND',
            'I could not find an answer in the knowledge base for that question. Try adding a name, product, or topic.'
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Citations
    |--------------------------------------------------------------------------
    |
    | Citation-aware answers (RagChat::respond / ChatController). Retrieved
    | chunks are presented to the LLM with stable [SOURCE_ID: n] identifiers,
    | the model returns JSON {answer, citations}, and every citation is
    | validated against the chunks that were actually retrieved. Invalid or
    | invented citation IDs never reach the API response.
    |
    */

    'citations' => [
        // Master switch for the citation pipeline.
        'enabled' => (bool) env('RAG_CHAT_CITATIONS', true),
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
        'crawl_runs_table' => 'rag_crawl_runs',
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
        // Children grouped per parent when features.parent_child is enabled.
        'parent_window' => (int) env('RAG_PARENT_WINDOW', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Website crawling
    |--------------------------------------------------------------------------
    |
    | SiteCrawler ingests a website (sitemap-first, link-following fallback)
    | into the RAG store so chatbots can answer from public web pages. Pages
    | carry source_url + document_type=web metadata for citations.
    |
    */

    'crawl' => [
        // Maximum number of pages ingested per crawl.
        'max_pages' => (int) env('RAG_CRAWL_MAX_PAGES', 50),
        // Link-following depth used when no sitemap is available.
        'max_depth' => (int) env('RAG_CRAWL_MAX_DEPTH', 3),
        // HTTP timeouts in seconds.
        'timeout' => (int) env('RAG_CRAWL_TIMEOUT', 15),
        'connect_timeout' => (int) env('RAG_CRAWL_CONNECT_TIMEOUT', 5),
        // Largest page (bytes) accepted before a page is skipped.
        'max_page_bytes' => (int) env('RAG_CRAWL_MAX_PAGE_BYTES', 2000000),
        // Pages yielding fewer plain-text chars than this are skipped.
        'min_text_chars' => (int) env('RAG_CRAWL_MIN_TEXT_CHARS', 40),
        'user_agent' => env('RAG_CRAWL_USER_AGENT', 'RagChatCrawler/1.0 (+https://github.com/Awais-Koder/rag-chat)'),
        // Block requests to private/loopback/link-local addresses (SSRF guard).
        // Disabled by default; enable before exposing crawling to untrusted users.
        'block_private_ips' => (bool) env('RAG_CRAWL_BLOCK_PRIVATE_IPS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Agentic RAG (Tier 3)
    |--------------------------------------------------------------------------
    |
    | Optional agent mode. OFF by default: respond() runs the classic
    | pre-retrieve -> context -> LLM pipeline. When enabled, respond() hands
    | the raw question to the laravel/ai agent loop (AgenticRagAgent), which
    | decides how many searches/tool calls are actually needed and only then
    | generates the answer. max_steps maps to the SDK #[MaxSteps] attribute;
    | hosts can lower/raise it by subclassing AgenticRagAgent. Tools are
    | READ-ONLY and each must be listed here to be available.
    |
    */

    'agent' => [
        'enabled' => (bool) env('RAG_AGENT_ENABLED', false),
        // The SDK enforces the step budget through the #[MaxSteps] attribute on
        // AgenticRagAgent (default 4). This key is used for usage reporting and
        // as the fallback default; hosts change the actual budget by
        // subclassing the agent and overriding the attribute.
        'max_steps' => (int) env('RAG_AGENT_MAX_STEPS', 4),
        // Seconds allowed for the whole agent turn (passed to prompt()).
        'timeout' => (int) env('RAG_AGENT_TIMEOUT', 20),
        // Whitelist of tools attached to the agent. Read-only tools only.
        'tools' => [
            'search_documents',
            'get_document',
            'get_document_section',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Streaming
    |--------------------------------------------------------------------------
    |
    | RagChat::stream() returns the laravel/ai StreamableAgentResponse, which
    | Laravel serializes as SSE for the frontend. The SDK yields structured
    | events (stream.started, text.delta, tool.call, …) over the wire.
    |
    */

    'streaming' => [
        'enabled' => (bool) env('RAG_STREAMING_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Indexing
    |--------------------------------------------------------------------------
    |
    | queue      : run ingestion through IndexDocumentJob (background queue).
    |              Document rows are created immediately with status 'pending'.
    | incremental: when re-ingesting the same source, compare chunk content
    |              hashes and reuse embeddings for unchanged chunks.
    | duplicates : what happens when identical content is ingested again:
    |              reject (return existing) | reuse (alias) |
    |              create_new_version (new versioned document row).
    |
    */

    'indexing' => [
        'queue' => (bool) env('RAG_INDEXING_QUEUE', false),
        // Re-ingests diff chunk content hashes and reuse embeddings for
        // unchanged chunks. Opt-in: writing content_hash to every chunk would
        // otherwise alter Tier 1/2 chunk metadata.
        'incremental' => (bool) env('RAG_INDEXING_INCREMENTAL', false),
        'duplicates' => env('RAG_INDEXING_DUPLICATES', 'reject'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Optional retrieval + answer caching. Cache keys include the project
    | scope, the query, the relevant config, and a fingerprint of the latest
    | document/chunk change, so caches invalidate automatically when content
    | changes. Disabled by default.
    |
    */

    'cache' => [
        'enabled' => (bool) env('RAG_CACHE_ENABLED', false),
        'ttl' => (int) env('RAG_CACHE_TTL', 3600),
        'retrieval' => (bool) env('RAG_CACHE_RETRIEVAL', true),
        'answer' => (bool) env('RAG_CACHE_ANSWER', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage tracking
    |--------------------------------------------------------------------------
    |
    | Every respond()/answer() call produces a RagRun (id, query, status,
    | latency, tokens, tool calls, agent steps) attached to the response
    | metadata as rag_run_id. persist: write it to the rag_runs table
    | (requires `php artisan migrate` for the package migration).
    |
    */

    'usage_tracking' => [
        'enabled' => (bool) env('RAG_USAGE_TRACKING', true),
        'persist' => (bool) env('RAG_USAGE_PERSIST', false),
        'model' => RagRun::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversation awareness (Tier 4)
    |--------------------------------------------------------------------------
    |
    | When enabled and history is passed to respond()/stream(), the latest
    | user question is rewritten into a standalone query so pronouns and
    | references ("he", "the company") resolve against prior turns before
    | retrieval. rewrite: heuristic (no LLM call) | llm | disabled.
    |
    */

    'conversation' => [
        'enabled' => (bool) env('RAG_CONVERSATION_ENABLED', false),
        'rewrite' => env('RAG_CONVERSATION_REWRITE', 'heuristic'),
        'history_turns' => (int) env('RAG_CONVERSATION_HISTORY_TURNS', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Context compression (Tier 4)
    |--------------------------------------------------------------------------
    |
    | Optional post-retrieval compression before the LLM context is built:
    | drops chunks below a relevance floor and caps the total context size in
    | characters. Citation metadata survives — compressed chunks still map to
    | their original sources.
    |
    */

    'compression' => [
        'enabled' => (bool) env('RAG_COMPRESSION_ENABLED', false),
        'min_relevance' => (float) env('RAG_COMPRESSION_MIN_RELEVANCE', 0.0),
        'max_context_chars' => (int) env('RAG_COMPRESSION_MAX_CONTEXT_CHARS', 12000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Answer confidence (Tier 4)
    |--------------------------------------------------------------------------
    |
    | Confidence is derived from the retrieval evidence after generation:
    |   high   - strong best-match score with supporting citations
    |   medium - usable evidence, weaker match or thin citations
    |   low    - weak evidence
    |   unsupported - no usable evidence (answer was the not-found fallback)
    |
    */

    'confidence' => [
        'high_score' => (float) env('RAG_CONFIDENCE_HIGH', 0.65),
        'medium_score' => (float) env('RAG_CONFIDENCE_MEDIUM', 0.45),
    ],

    /*
    |--------------------------------------------------------------------------
    | Evaluation framework (Tier 4)
    |--------------------------------------------------------------------------
    |
    | Offline evaluation of a knowledge base against a dataset of
    | {question, expected answer, expected source} cases.
    |
    */

    'evaluation' => [
        'top_k' => (int) env('RAG_EVAL_TOP_K', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompt safety (Tier 4)
    |--------------------------------------------------------------------------
    |
    | injection_guard: instructs the model to treat retrieved excerpts as DATA,
    | never as instructions, so instructions embedded in documents cannot
    | override the system prompt.
    |
    */

    'prompt' => [
        'injection_guard' => (bool) env('RAG_PROMPT_INJECTION_GUARD', true),
    ],

];
