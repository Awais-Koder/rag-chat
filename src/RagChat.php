<?php

namespace Awais\RagChat;

use Awais\RagChat\Agents\AgenticRagAgent;
use Awais\RagChat\Agents\CitedRagAgent;
use Awais\RagChat\Agents\RagAgent;
use Awais\RagChat\Citations\Citation;
use Awais\RagChat\Citations\CitationCollection;
use Awais\RagChat\Citations\CitationRegistry;
use Awais\RagChat\Citations\CitationValidator;
use Awais\RagChat\Citations\EntityPrioritizer;
use Awais\RagChat\Citations\EntityResolver;
use Awais\RagChat\Citations\RagResponse;
use Awais\RagChat\Jobs\IndexDocumentJob;
use Awais\RagChat\Models\RagChunk;
use Awais\RagChat\Models\RagDocument;
use Awais\RagChat\Rag\ContextBuilder;
use Awais\RagChat\Rag\Ingestor;
use Awais\RagChat\Rag\PromptBuilder;
use Awais\RagChat\Rag\Retriever;
use Awais\RagChat\Support\RagProjectScope;
use Awais\RagChat\Support\RagRun;
use Awais\RagChat\Support\ResultAuthorizer;
use Closure;
use Laravel\Ai\Attributes\MaxSteps;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;

/**
 * Local RAG enabler for Laravel applications.
 *
 * Owns document ingest, embedding persistence, retrieval, citation-aware
 * chat, optional agentic mode (Tier 3, off by default), streaming, caching,
 * and per-run usage tracking.
 */
class RagChat
{
    protected ?RagRun $lastRun = null;

    /** @var array<string, mixed> */
    protected array $lastUsage = [];

    protected int $lastRetrievals = 0;

    protected ?Closure $authorizeResults = null;

    public function __construct(
        protected Ingestor $ingestor,
        protected Retriever $retriever,
        protected PromptBuilder $promptBuilder,
        protected ?CitationRegistry $registry = null,
        protected ?ContextBuilder $contextBuilder = null,
    ) {
        $this->registry ??= new CitationRegistry();
        $this->contextBuilder ??= new ContextBuilder();
    }

    /**
     * Ingest a file or directory from disk into the RAG store.
     *
     * With config('rag-chat.indexing.queue') enabled the file is queued for
     * background indexing and a pending RagDocument is returned immediately.
     *
     * @return RagDocument|RagDocument[]
     */
    public function ingest(string $path, array $meta = []): RagDocument|array
    {
        if (is_dir($path)) {
            return $this->ingestor->ingestDirectory($path);
        }

        if ((bool) config('rag-chat.indexing.queue', false)) {
            return $this->queueFile($path, $meta);
        }

        return $this->ingestor->ingestFile($path, $meta);
    }

    /**
     * Ingest raw text directly (no file on disk required).
     */
    public function ingestText(string $text, string $source, ?string $title = null, array $meta = []): RagDocument
    {
        if ((bool) config('rag-chat.indexing.queue', false)) {
            return $this->queueText($text, $source, $title, $meta);
        }

        return $this->ingestor->ingestText($text, $source, $title, $meta);
    }

    /**
     * Ask the SDK RagAgent and return only the final answer text.
     *
     * Kept for backward compatibility. Prefer respond() to get validated
     * citations alongside the answer.
     *
     * @param  Lab|array<int, Lab|string>|string|null  $provider
     */
    public function answer(
        string $question,
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): string {
        return $this->respond($question, $provider, $model)->answer;
    }

    /**
     * Ask the citation-aware agent and return the answer plus validated citations.
     *
     * Two modes, controlled by config:
     *
     *  - agent.enabled = false (default): classic pipeline — retrieve once,
     *    build the cited context, ground the LLM on it.
     *  - agent.enabled = true: agentic mode — the raw question goes to
     *    AgenticRagAgent, which searches via tools as many times as it needs.
     *
     * Every call produces a RagRun (rag_run_id, usage, latency) attached to
     * the response metadata. With config('rag-chat.cache.answer') enabled,
     * non-agentic responses are cached and invalidated on any content change.
     *
     * @param  Lab|array<int, Lab|string>|string|null  $provider
     */
    public function respond(
        string $question,
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): RagResponse {
        if ((bool) config('rag-chat.agent.enabled', false)) {
            return $this->respondAgentically($question, $provider, $model);
        }

        if ($this->answerCacheEnabled() && ($cached = $this->cachedResponse($question)) !== null) {
            $run = (new RagRun($question))->complete();
            $run->status = 'cached';
            $this->lastRun = $run;

            // The cached payload carries the original run's metadata; point it
            // at the cached run so rag_run_id is always consistent with lastRun().
            return new RagResponse(
                answer: $cached->answer,
                citations: $cached->citations,
                sources: $cached->sources,
                metadata: array_merge($cached->metadata, ['rag_run_id' => $run->id, 'status' => 'cached']),
            );
        }

        $run = new RagRun($question);

        try {
            $response = $this->respondStandard($question, $provider, $model);
            $run->complete(
                usage: $this->lastUsage,
                retrievals: $this->lastRetrievals,
            );
        } catch (\Throwable $exception) {
            $run->fail($exception);

            throw $exception;
        }

        $this->lastRun = $run;
        $this->persistRun($run);

        $final = $this->withRunMetadata($response, $run, agent: false);

        if ($this->answerCacheEnabled()) {
            Cache::put(
                $this->answerCacheKey($question),
                $final->toArray(),
                (int) config('rag-chat.cache.ttl', 3600),
            );
        }

        return $final;
    }

    /**
     * Stream a RAG answer as laravel/ai events (SSE when returned from a
     * controller). Retrieval still runs first (context is pre-injected), so
     * streaming works with the classic pipeline. Agentic streaming is
     * available directly via (new AgenticRagAgent)->stream(...).
     *
     * @param  Lab|array<int, Lab|string>|string|null  $provider
     */
    public function stream(
        string $question,
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): StreamableAgentResponse {
        return (new RagAgent)->stream(
            $this->promptMessage($question),
            provider: $provider,
            model: $model,
        );
    }

    /**
     * User message sent to the agent (optionally includes pre-retrieved context).
     */
    public function promptMessage(string $question): string
    {
        if (! config('rag-chat.chat.pre_retrieve', true)) {
            return $question;
        }

        return $this->context($question);
    }

    /**
     * The retrieval matches for a question, without generating an answer.
     *
     * @return Collection<int, array{chunk: \Awais\RagChat\Models\RagChunk, score: float}>
     */
    public function retrieve(string $question): Collection
    {
        return $this->authorizeMatches($this->retriever->retrieve($question));
    }

    /**
     * Build a ready-to-send context block + question string from retrieved chunks.
     */
    public function context(string $question): string
    {
        $matches = $this->contextBuilder->expand(
            $this->authorizeMatches($this->retriever->retrieve($question))
        );

        return $this->promptBuilder->build($question, $matches->all());
    }

    /**
     * Debug trace of one retrieval pass (generated queries, per-query hits,
     * final matches) plus the configured context expansion. Internal
     * diagnostics — not part of the chat API.
     *
     * @return array<string, mixed>
     */
    public function trace(string $question): array
    {
        $trace = $this->retriever->trace($question)->toArray();

        $trace['context_expansion'] = [
            'strategy' => (string) config('rag-chat.retrieval.context_expansion', 'disabled'),
            'final_chunks' => $this->contextBuilder
                ->expand($this->retriever->retrieve($question))
                ->pluck('chunk.id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        ];

        return $trace;
    }

    /**
     * Provenance rows for the top retrieval matches (no LLM call).
     *
     * @return array<int, array{document_id: int, title: ?string, source: ?string, score: float, excerpt: string}>
     */
    public function sources(string $question): array
    {
        return $this->formatSources($this->authorizeMatches($this->retriever->retrieve($question)));
    }

    /**
     * All indexed documents with their chunk counts (indexing observability).
     *
     * @return Collection<int, RagDocument>
     */
    public function documents(): Collection
    {
        return RagDocument::query()
            ->withCount('chunks')
            ->when(RagProjectScope::get() !== null, fn ($query) => $query->where('project_id', RagProjectScope::get()))
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The latest (current) version of a document, if it exists.
     */
    public function document(int $documentId): ?RagDocument
    {
        return RagDocument::query()
            ->when(RagProjectScope::get() !== null, fn ($query) => $query->where('project_id', RagProjectScope::get()))
            ->find($documentId);
    }

    /**
     * Every version of a versioned document (versioning introduced by the
     * 'create_new_version' duplicate behavior).
     *
     * @return Collection<int, RagDocument>
     */
    public function versions(int $documentId): Collection
    {
        $document = $this->document($documentId);

        if ($document === null) {
            return collect();
        }

        $rootId = (int) ($document->meta['version_of'] ?? $documentId);

        return RagDocument::query()
            ->withCount('chunks')
            ->when(RagProjectScope::get() !== null, fn ($query) => $query->where('project_id', RagProjectScope::get()))
            ->get()
            ->filter(fn (RagDocument $candidate) => (int) ($candidate->meta['version_of'] ?? $candidate->id) === $rootId)
            ->sortByDesc('id')
            ->values();
    }

    /**
     * The RagRun metadata of the last chat turn (usage tracking, debug).
     */
    public function lastRun(): ?RagRun
    {
        return $this->lastRun;
    }

    /**
     * Register a callback that filters which retrieved chunks may reach the
     * LLM. The callback receives a Collection of RagChunk models and must
     * return the allowed chunks (or their ids). Applied to every retrieval
     * path and to the read-only agent tools.
     */
    public function authorizeResultsUsing(callable $callback): static
    {
        $this->authorizeResults = Closure::fromCallable($callback);

        return $this;
    }

    /**
     * The registered authorization filter, if any (used by the agent tools).
     */
    public function authorizationFilter(): ?Closure
    {
        return $this->authorizeResults;
    }

    /**
     * Classic citation-aware pipeline: retrieve -> entity prioritization ->
     * grounding gates -> context expansion -> cited LLM -> citation validation.
     *
     * @param  Lab|array<int, Lab|string>|string|null  $provider
     */
    protected function respondStandard(
        string $question,
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): RagResponse {
        $matches = $this->authorizeMatches($this->retriever->retrieve($question));
        $this->lastRetrievals = $matches->count();

        // Prefer chunks that literally mention the entity being asked about
        // (e.g. "Muhammad Awais") over merely-semantic matches.
        $matches = (new EntityPrioritizer)->prioritize($matches, $question);

        // Grounding gates: never hand the LLM empty, weak, or wrong-entity
        // evidence and expect the prompt alone to fix it. General conversation
        // (no named entity) still flows through so greetings stay friendly.
        $verdict = (new EntityResolver)->resolve($question, $matches);

        if ($verdict['status'] === 'missing' || $verdict['status'] === 'ambiguous') {
            return $this->ungroundedResponse($matches, $verdict['status'], $verdict['candidates']);
        }

        $minEvidence = config('rag-chat.retrieval.min_evidence_score');

        // Entity-aware like the resolver: general conversation (no named
        // entity) is never gated by the score floor.
        if (
            $verdict['status'] !== 'no_entity'
            && $minEvidence !== null
            && $matches->isNotEmpty()
            && (float) $matches->first()['score'] < (float) $minEvidence
        ) {
            return $this->ungroundedResponse($matches, 'missing');
        }

        // Context expansion (parent / neighboring chunks) — config-gated and
        // runs after the grounding gates so the entity verdict is judged on
        // the retrieved children only.
        $matches = $this->contextBuilder->expand($matches);

        $this->registry->reset();

        foreach ($matches as $match) {
            $this->registry->register($match['chunk'], (float) $match['score']);
        }

        $prompt = $this->promptBuilder->buildCited($question, $this->registry);

        try {
            $response = (new CitedRagAgent)->prompt(
                $prompt,
                provider: $provider,
                model: $model,
            );

            $this->lastUsage = $this->usageFrom($response);
        } catch (\Throwable $exception) {
            // Provider/model without structured output support — fall back to a
            // plain, citation-free answer rather than breaking the chat.
            report($exception);

            return new RagResponse(
                answer: $this->plainAnswer($question, $provider, $model),
                citations: new CitationCollection(),
                sources: $this->formatSources($matches),
            );
        }

        [$answer, $rawCitations] = $this->decodeCitedResponse($response);

        $citations = (new CitationValidator)->validate(
            $rawCitations,
            $this->registry->all(),
        );

        return new RagResponse(
            answer: $this->fallbackAnswer($answer),
            citations: $citations,
            sources: $this->formatSources($matches),
        );
    }

    /**
     * Agentic mode: the laravel/ai tool-calling loop answers using the
     * read-only tools instead of the pre-retrieved context.
     *
     * Citations registered by the search tool (via the shared CitationRegistry
     * binding) are validated exactly like the classic path. On provider
     * failure it falls back to the classic pipeline so chat never breaks.
     *
     * @param  Lab|array<int, Lab|string>|string|null  $provider
     */
    protected function respondAgentically(
        string $question,
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): RagResponse {
        $run = new RagRun($question);

        $this->registry->reset();
        app()->instance(CitationRegistry::class, $this->registry);

        try {
            $response = (new AgenticRagAgent)->prompt(
                $question,
                provider: $provider,
                model: $model,
                timeout: (int) config('rag-chat.agent.timeout', 20),
            );

            $run->complete(
                usage: $this->usageFrom($response),
                agentSteps: $this->agentMaxSteps(),
                retrievals: $this->registry->count(),
            );

            [$answer, $rawCitations] = $this->decodeCitedResponse($response);

            $citations = (new CitationValidator)->validate(
                $rawCitations,
                $this->registry->all(),
            );

            $response = new RagResponse(
                answer: $this->fallbackAnswer($answer),
                citations: $citations,
                sources: $this->formatSources($this->authorizeMatches($this->retriever->retrieve($question))),
            );
        } catch (\Throwable $exception) {
            report($exception);
            $run->error = $exception->getMessage();

            // Resilience: fall back to the classic pre-retrieve pipeline and
            // record the turn as completed (the error stays on the run).
            $response = $this->respondStandard($question, $provider, $model);
            $run->complete(
                usage: $this->lastUsage,
                retrievals: $this->lastRetrievals,
            );
        } finally {
            // Restore the scoped registry binding for the next request.
            app()->forgetInstance(CitationRegistry::class);
        }

        $this->lastRun = $run;
        $this->persistRun($run);

        return $this->withRunMetadata($response, $run, agent: true);
    }

    /**
     * Decode the agent response into [answer, rawCitationIds].
     *
     * Handles both native structured output and providers that ignored the
     * schema and returned JSON (or plain text) inside the response text.
     *
     * @return array{0: string, 1: mixed}
     */
    protected function decodeCitedResponse(AgentResponse $response): array
    {
        if ($response instanceof StructuredAgentResponse) {
            $data = $response->toArray();

            // Some providers/fakes wrap a single structured object in a list.
            if (array_is_list($data) && count($data) === 1 && is_array($data[0] ?? null)) {
                $data = $data[0];
            }

            return [
                (string) ($data['answer'] ?? ''),
                $data['citations'] ?? [],
            ];
        }

        $text = trim((string) $response->text);

        // Some providers wrap the JSON in markdown code fences (```json ... ```).
        $text = preg_replace('/^\s*```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```\s*$/', '', (string) $text);

        if ($text !== '' && str_starts_with($text, '{')) {
            $decoded = json_decode($text, true);

            if (is_array($decoded)) {
                return [
                    (string) ($decoded['answer'] ?? ''),
                    $decoded['citations'] ?? [],
                ];
            }
        }

        return [(string) $response->text, []];
    }

    /**
     * Plain-text answer via the standard RagAgent (no structured output).
     * Used as a fallback when the citation-aware agent is unavailable.
     */
    protected function plainAnswer(string $question, Lab|array|string|null $provider = null, ?string $model = null): string
    {
        try {
            $response = (new RagAgent)->prompt(
                $this->promptMessage($question),
                provider: $provider,
                model: $model,
            );

            $this->lastUsage = $this->usageFrom($response);

            return $this->fallbackAnswer((string) ($response->text ?? $response));
        } catch (\Throwable $exception) {
            report($exception);

            return $this->fallbackAnswer('');
        }
    }

    /**
     * Return a helpful fallback when the model produced no answer text.
     */
    protected function fallbackAnswer(string $answer): string
    {
        $answer = trim($answer);

        if ($answer !== '') {
            return $answer;
        }

        // Fallback keeps old published configs (no chat.not_found key) working.
        return (string) (config('rag-chat.chat.not_found')
            ?: 'I could not find an answer in the knowledge base for that question. Try adding a name, product, or topic.');
    }

    /**
     * Safe response when the evidence is missing or ambiguous — no LLM call.
     *
     * @param  Collection<int, array{chunk: \Awais\RagChat\Models\RagChunk, score: float}>  $matches
     * @param  list<string>  $candidates
     */
    protected function ungroundedResponse(Collection $matches, string $status, array $candidates = []): RagResponse
    {
        $answer = $status === 'ambiguous'
            ? 'I found more than one person matching that name in the knowledge base: '
                .implode(', ', array_slice($candidates, 0, 3))
                .'. Please tell me which one you mean.'
            : $this->fallbackAnswer('');

        return new RagResponse(
            answer: $answer,
            citations: new CitationCollection(),
            sources: $this->formatSources($matches),
        );
    }

    /**
     * @param  Collection<int, array{chunk: \Awais\RagChat\Models\RagChunk, score: float}>  $matches
     * @return array<int, array{document_id: int, title: ?string, source: ?string, score: float, excerpt: string}>
     */
    protected function formatSources(Collection $matches): array
    {
        return $matches->map(function (array $match) {
            $chunk = $match['chunk'];

            return [
                'document_id' => (int) $chunk->document_id,
                'title' => $chunk->document?->title,
                'source' => $chunk->document?->source,
                'score' => round($match['score'], 4),
                'excerpt' => \Illuminate\Support\Str::limit($chunk->content, 200),
            ];
        })->all();
    }

    /**
     * Wrap a response with run-level metadata (rag_run_id, usage, latency).
     *
     * @param  array<string, mixed>  $extra
     */
    protected function withRunMetadata(RagResponse $response, RagRun $run, bool $agent, array $extra = []): RagResponse
    {
        return new RagResponse(
            answer: $response->answer,
            citations: $response->citations,
            sources: $response->sources,
            metadata: array_merge([
                'rag_run_id' => $run->id,
                'agent' => $agent,
                'usage' => $run->usage,
                'latency_ms' => $run->latencyMs(),
                'status' => $run->status,
            ], $extra),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function usageFrom(AgentResponse $response): array
    {
        return [
            'input_tokens' => isset($response->usage) ? (int) $response->usage->promptTokens : null,
            'output_tokens' => isset($response->usage) ? (int) $response->usage->completionTokens : null,
            'provider' => $response->meta->provider ?? null,
            'model' => $response->meta->model ?? null,
        ];
    }

    /**
     * Apply the registered authorization filter to retrieval matches.
     *
     * @param  Collection<int, array{chunk: \Awais\RagChat\Models\RagChunk, score: float}>  $matches
     * @return Collection<int, array{chunk: \Awais\RagChat\Models\RagChunk, score: float}>
     */
    protected function authorizeMatches(Collection $matches): Collection
    {
        $allowedIds = ResultAuthorizer::allowedIds($matches->pluck('chunk'), $this->authorizeResults);

        return $matches
            ->filter(fn (array $match) => in_array((int) $match['chunk']->id, $allowedIds, true))
            ->values();
    }

    /**
     * The agent's declared step budget (SDK #[MaxSteps] attribute), used for
     * usage reporting. The SDK enforces the budget on the class itself.
     */
    protected function agentMaxSteps(): int
    {
        $attributes = (new \ReflectionClass(AgenticRagAgent::class))->getAttributes(MaxSteps::class);

        return $attributes[0]?->newInstance()->value ?? (int) config('rag-chat.agent.max_steps', 4);
    }

    protected function answerCacheEnabled(): bool
    {
        return (bool) config('rag-chat.cache.enabled', false)
            && (bool) config('rag-chat.cache.answer', true);
    }

    protected function answerCacheKey(string $question): string
    {
        return 'rag-chat:answer:'.md5(implode('|', [
            (string) RagProjectScope::get(),
            mb_strtolower(trim($question)),
            json_encode([
                'chat' => config('rag-chat.chat'),
                'min_evidence' => config('rag-chat.retrieval.min_evidence_score'),
                'expansion' => config('rag-chat.retrieval.context_expansion'),
            ]),
            $this->documentsFingerprint(),
        ]));
    }

    /**
     * Latest document/chunk change fingerprint shared with the retrieval cache.
     *
     * Counts are included so any content change (insert/delete) invalidates
     * caches even when a database stores second-precision timestamps and the
     * change lands inside the same second as the previous write.
     */
    protected function documentsFingerprint(): string
    {
        return implode(':', [
            (string) RagDocument::query()->count(),
            (string) RagDocument::query()->max('updated_at'),
            (string) RagChunk::query()->count(),
            (string) RagChunk::query()->max('updated_at'),
        ]);
    }

    protected function cachedResponse(string $question): ?RagResponse
    {
        $cached = Cache::get($this->answerCacheKey($question));

        if (! is_array($cached)) {
            return null;
        }

        return new RagResponse(
            answer: (string) ($cached['answer'] ?? ''),
            citations: new CitationCollection(array_map(
                fn (array $citation) => Citation::fromArray($citation),
                $cached['citations'] ?? [],
            )),
            sources: $cached['sources'] ?? [],
            metadata: $cached['metadata'] ?? [],
        );
    }

    /**
     * Create a pending document row and dispatch the background indexing job.
     */
    protected function queueText(string $text, string $source, ?string $title = null, array $meta = []): RagDocument
    {
        $projectId = $meta['project_id'] ?? RagProjectScope::get();
        unset($meta['project_id']);

        $document = RagDocument::create([
            'project_id' => $projectId,
            'source' => $source,
            'title' => $title ?? $source,
            'meta' => array_merge($meta, [
                'status' => 'pending',
                'pending_text' => $text,
            ]),
        ]);

        IndexDocumentJob::dispatch($document->id);

        return $document;
    }

    /**
     * Create a pending document row for a file and dispatch the background job.
     */
    protected function queueFile(string $path, array $meta = []): RagDocument
    {
        $source = $meta['source'] ?? $path;
        $title = $meta['title'] ?? basename($path);
        unset($meta['source'], $meta['title']);

        $projectId = $meta['project_id'] ?? RagProjectScope::get();
        unset($meta['project_id']);

        $document = RagDocument::create([
            'project_id' => $projectId,
            'source' => $source,
            'title' => $title,
            'meta' => array_merge($meta, [
                'status' => 'pending',
                'pending_path' => $path,
            ]),
        ]);

        IndexDocumentJob::dispatch($document->id);

        return $document;
    }

    /**
     * Optionally persist the run to the rag_runs table.
     */
    protected function persistRun(RagRun $run): void
    {
        if (! (bool) config('rag-chat.usage_tracking.persist', false)) {
            return;
        }

        $model = (string) config('rag-chat.usage_tracking.model');

        if (! class_exists($model)) {
            return;
        }

        /** @var \Awais\RagChat\Models\RagRun $model */
        $model::create([
            'rag_run_id' => $run->id,
            'project_id' => RagProjectScope::get(),
            'query' => $run->query,
            'status' => $run->status,
            'latency_ms' => $run->latencyMs(),
            'input_tokens' => $run->usage['input_tokens'] ?? null,
            'output_tokens' => $run->usage['output_tokens'] ?? null,
            'provider' => $run->usage['provider'] ?? null,
            'model' => $run->usage['model'] ?? null,
            'tool_calls' => $run->toolCalls,
            'agent_steps' => $run->agentSteps,
            'retrievals' => $run->retrievals,
            'usage' => $run->usage,
            'error' => $run->error,
        ]);
    }
}
