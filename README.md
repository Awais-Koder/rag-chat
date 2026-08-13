# laravelartist/rag-chat

Plug-and-play local RAG for Laravel, powered by [`laravel/ai`](https://github.com/laravel/ai).

One install command publishes **Laravel AI SDK** + this package, migrates, scaffolds a ready `RagAgent`, and writes provider `.env` keys. You only set an API key and ingest docs.

Chat runs through a real Laravel AI SDK agent (`RagAgent` + `SearchKnowledge` tool). Providers stay in `config/ai.php`.

## Requirements

- PHP 8.3+
- Laravel 12 or 13

## Install (plug-and-play)

```bash
composer require laravelartist/rag-chat
php artisan rag-chat:install
```

That single command:

1. Publishes `laravel/ai` config + conversation migrations
2. Publishes Rag Chat config + migrations
3. Publishes `app/Ai/Agents/RagAgent.php` for optional customization
4. Patches `config/ai.php` defaults to `env('AI_DEFAULT')` / `env('AI_DEFAULT_EMBEDDINGS')`
5. Appends missing keys to `.env` / `.env.example`
6. Runs `php artisan migrate` (skip with `--no-migrate`)

### Connect a provider

Edit `.env` — set **one** provider:

```env
AI_DEFAULT=openrouter
AI_DEFAULT_EMBEDDINGS=openrouter
OPENROUTER_API_KEY=sk-or-v1-...
```

Other options: `openai`, `anthropic`, `gemini` (+ matching `OPENAI_API_KEY` / `ANTHROPIC_API_KEY` / `GEMINI_API_KEY`).

### Ingest + chat

```bash
php artisan rag-chat:ingest storage/app/docs
```

```bash
curl -X POST /rag-chat/chat \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"message":"What is the refund policy?"}'
```

Or in PHP:

```php
use Awais\RagChat\Agents\RagAgent;
use Awais\RagChat\Facades\RagChat;

RagChat::ingest(storage_path('app/docs'));

$response = (new RagAgent)->prompt('What is the refund policy?');
echo $response->text;
```

Streaming:

```php
return (new RagAgent)->stream('Summarize pricing');
// or POST /rag-chat/chat/stream
```

## What the package owns vs Laravel AI

| Concern | Owner |
|---------|--------|
| Document ingest (txt/md/pdf), chunking, local vector store | `laravelartist/rag-chat` |
| Embeddings API calls | `laravel/ai` (`Embeddings`) |
| Chat agents, tools, streaming, conversations, providers | `laravel/ai` (`RagAgent`) |
| Provider API keys | Your `.env` / `config/ai.php` |

Customize instructions/tools in the published `app/Ai/Agents/RagAgent.php`. Package HTTP routes use `Awais\RagChat\Agents\RagAgent` by default.

## HTTP API

Unauthenticated by default — add middleware in `config/rag-chat.php` before going public.

| Method | Path | Body |
|--------|------|------|
| `POST` | `/rag-chat/chat` | `{ "message": "..." }` |
| `POST` | `/rag-chat/chat/stream` | `{ "message": "..." }` (SSE) |
| `POST` | `/rag-chat/documents` | `{ "text": "..." }` or multipart `file` |

## Vector stores (local RAG)

| Driver | Config | Behaviour |
|--------|--------|-----------|
| `json` | `RAG_STORE=json` (default) | JSON embeddings + PHP cosine (any SQL DB) |
| `mysql` | `RAG_STORE=mysql` | MySQL 9 `DISTANCE(..., 'COSINE')` |

Separate from provider-hosted `Laravel\Ai\Stores` / `FileSearch`.

## Supported files

- `.txt`, `.md` / `.markdown`, `.pdf` (text PDFs via smalot/pdfparser)

## Tier 4: advanced RAG features

All Tier 4 features are **opt-in and off by default** — Tier 1–3 behaviour is unchanged.

### Metadata filters

Filter retrieval by chunk/document metadata at the retrieval layer (not just app-side):

```php
$rag->retrieve('Laravel backend', ['department' => 'engineering', 'document_type' => 'resume']);
$rag->respond('What projects has the backend team shipped?', filters: ['department' => 'engineering']);
```

Dot notation works (`'meta_group.tier' => 'senior'`). Filters apply **before** the top-K cut, and cache keys include them, so filtered results never leak across scopes or configurations. Scoping is enforced via `RagProjectScope` (project = tenant); cross-project retrieval is impossible.

### Conversation-aware rewriting

```php
$response = $rag->respond('What framework does he use?', history: [
    ['role' => 'user', 'content' => 'Tell me about Muhammad Awais.'],
]);
```

With `RAG_CONVERSATION_ENABLED=true`, pronoun-led questions without their own entity are rewritten into a standalone query (heuristic by default, no LLM call; `RAG_CONVERSATION_REWRITE=llm` for an LLM rewrite). Questions that already name an entity are never rewritten. The original question is kept in response metadata as `original_query`.

### Context compression

With `RAG_COMPRESSION_ENABLED=true`, chunks below `RAG_COMPRESSION_MIN_RELEVANCE` are dropped and total context is capped at `RAG_COMPRESSION_MAX_CONTEXT_CHARS` before the LLM call. Citation metadata survives compression — only the chunk set changes, never source identity.

### Answer confidence / groundedness

Every `respond()` response carries `metadata.confidence`: `high` (strong match + citations), `medium`, `low`, or `unsupported` (the not-found fallback). Thresholds: `RAG_CONFIDENCE_HIGH` (default 0.65), `RAG_CONFIDENCE_MEDIUM` (default 0.45).

### Prompt-injection guard

Retrieved excerpts are presented to the model as **untrusted data**: the default prompt tells the model to ignore any instructions found inside documents ("ignore previous instructions", "act as…"). Disable with `RAG_PROMPT_INJECTION_GUARD=false` if you have a reason to.

### Knowledge-base management API

```php
$rag->deleteDocument($id);        // document + chunks (driver-independent)
$rag->reindexDocument($id);       // re-ingest from stored source (dedup applies)
$rag->clearKnowledgeBase();       // all docs in the active project scope
$rag->chunks($documentId);        // inspect chunks (project-scoped)
$rag->search($question, $filters); // alias of retrieve()
```

### Evaluation framework

Run a dataset of cases against the real retrieval pipeline and get a hit-rate report:

```php
use Awais\RagChat\Evaluation\EvaluationCase;

$report = app(\Awais\RagChat\Evaluation\RagEvaluator::class)->evaluate([
    new EvaluationCase('How much does the AcmeMover X1 cost?', ['pricing.md']),
    new EvaluationCase('What warranty does Acme offer?', ['warranty.txt'], '12 months'),
]);

$report->retrievalHitRate(); // 1.0
```

Add a custom per-case evaluator (answer relevance, citation correctness) via the optional `$perCase` callback.

### New config keys

| Key | Env | Default |
|-----|-----|---------|
| `conversation.enabled` | `RAG_CONVERSATION_ENABLED` | `false` |
| `conversation.rewrite` | `RAG_CONVERSATION_REWRITE` | `heuristic` (`heuristic`\|`llm`\|`disabled`) |
| `conversation.history_turns` | `RAG_CONVERSATION_HISTORY_TURNS` | `4` |
| `compression.enabled` | `RAG_COMPRESSION_ENABLED` | `false` |
| `compression.min_relevance` | `RAG_COMPRESSION_MIN_RELEVANCE` | `0.0` |
| `compression.max_context_chars` | `RAG_COMPRESSION_MAX_CONTEXT_CHARS` | `12000` |
| `confidence.high_score` | `RAG_CONFIDENCE_HIGH` | `0.65` |
| `confidence.medium_score` | `RAG_CONFIDENCE_MEDIUM` | `0.45` |
| `evaluation.top_k` | `RAG_EVAL_TOP_K` | `5` |
| `prompt.injection_guard` | `RAG_PROMPT_INJECTION_GUARD` | `true` |

### Extension points

- **Retrieval**: already composable — `RAG_FEATURE_MULTI_QUERY`, `RAG_RETRIEVAL_HYBRID`, `RAG_RETRIEVAL_RERANKER` (string class implementing `Awais\RagChat\Contracts\Reranker`), `RAG_CONTEXT_EXPANSION`.
- **Metadata filters**: any `array<string, mixed>` against chunk/document `meta`.
- **Evaluators**: pass a `callable` to `RagEvaluator::evaluate()` for per-case metrics.
- **Scoping**: `RagProjectScope::set($projectId)` before ingest/chat; authorization hooks via `RagChat::authorizeResultsUsing()`.

## Testing

```bash
composer install
./vendor/bin/phpunit
```

## License

MIT
