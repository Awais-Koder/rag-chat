# awais/rag-chat

Plug-and-play local RAG for Laravel, powered by [`laravel/ai`](https://github.com/laravel/ai).

One install command publishes **Laravel AI SDK** + this package, migrates, scaffolds a ready `RagAgent`, and writes provider `.env` keys. You only set an API key and ingest docs.

Chat runs through a real Laravel AI SDK agent (`RagAgent` + `SearchKnowledge` tool). Providers stay in `config/ai.php`.

## Requirements

- PHP 8.3+
- Laravel 12 or 13

## Install (plug-and-play)

```bash
composer require awais/rag-chat
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
| Document ingest (txt/md/pdf), chunking, local vector store | `awais/rag-chat` |
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

## Testing

```bash
composer install
./vendor/bin/phpunit
```

## License

MIT
