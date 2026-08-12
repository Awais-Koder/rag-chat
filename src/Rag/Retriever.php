<?php

namespace Awais\RagChat\Rag;

use Awais\RagChat\Contracts\Reranker;
use Awais\RagChat\Contracts\VectorStore;
use Awais\RagChat\Models\RagChunk;
use Awais\RagChat\Models\RagDocument;
use Awais\RagChat\Rag\Rerankers\LexicalReranker;
use Awais\RagChat\Rag\Rerankers\NoopReranker;
use Awais\RagChat\Support\RagProjectScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class Retriever
{
    public function __construct(
        protected Embedder $embedder,
        protected VectorStore $store,
    ) {}

    /**
     * Retrieve the top-K most relevant chunks for a query.
     *
     * Pipeline: (optional) multi-query generation -> vector search per query
     * -> optional hybrid exact-keyword merge -> optional reranking stage.
     *
     * @return Collection<int, array{chunk: \Awais\RagChat\Models\RagChunk, score: float}>
     */
    public function retrieve(string $query): Collection
    {
        return $this->search($query)->retrieved;
    }

    /**
     * Same retrieval pass as retrieve(), but with full debug tracing:
     * generated queries, per-query hits, and final matches with attribution.
     */
    public function trace(string $query): RetrievalTrace
    {
        return $this->search($query);
    }

    /**
     * The cache key for one retrieval pass: query, project scope, retrieval
     * config, and a fingerprint of the latest document/chunk change — so any
     * content change automatically invalidates the cached results.
     */
    public function cacheKey(string $query): string
    {
        return 'rag-chat:retrieval:'.md5(implode('|', [
            (string) RagProjectScope::get(),
            mb_strtolower(trim($query)),
            json_encode([
                'top_k' => config('rag-chat.retrieval.top_k'),
                'min_score' => config('rag-chat.retrieval.min_score'),
                'multi_query' => config('rag-chat.features.multi_query'),
                'expand_queries' => config('rag-chat.retrieval.expand_queries'),
                'hybrid' => config('rag-chat.retrieval.hybrid.enabled'),
                'reranker' => config('rag-chat.retrieval.reranker'),
            ]),
            $this->fingerprint(),
        ]));
    }

    /**
     * Latest document/chunk change fingerprint, shared with the answer cache.
     *
     * Counts are included so any content change (insert/delete) invalidates
     * caches even when a database stores second-precision timestamps and the
     * change lands inside the same second as the previous write.
     */
    protected function fingerprint(): string
    {
        return implode(':', [
            (string) RagDocument::query()->count(),
            (string) RagDocument::query()->max('updated_at'),
            (string) RagChunk::query()->count(),
            (string) RagChunk::query()->max('updated_at'),
        ]);
    }

    /**
     * @return array{0: Collection<int, array{chunk: \Awais\RagChat\Models\RagChunk, score: float}>, 1: RetrievalTrace}
     */
    protected function search(string $query): RetrievalTrace
    {
        if ((bool) config('rag-chat.cache.enabled', false) && (bool) config('rag-chat.cache.retrieval', true)) {
            return Cache::remember(
                $this->cacheKey($query),
                (int) config('rag-chat.cache.ttl', 3600),
                fn () => $this->searchUncached($query),
            );
        }

        return $this->searchUncached($query);
    }

    /**
     * @return array{0: Collection<int, array{chunk: \Awais\RagChat\Models\RagChunk, score: float}>, 1: RetrievalTrace}
     */
    protected function searchUncached(string $query): RetrievalTrace
    {
        $topK = (int) config('rag-chat.retrieval.top_k', 5);
        $minScore = (float) config('rag-chat.retrieval.min_score', 0.0);
        $multiQuery = (bool) config('rag-chat.features.multi_query', false);

        $searchQueries = $multiQuery
            ? MultiQuery::queries($query, (int) config('rag-chat.retrieval.multi_query_count', 3))
            : (config('rag-chat.retrieval.expand_queries', true)
                ? QueryExpander::queries($query)
                : [$query]);

        /** @var array<int, array{match: array{chunk: \Awais\RagChat\Models\RagChunk, score: float}, query: string}> $bestByChunkId */
        $bestByChunkId = [];
        $perQuery = [];

        foreach ($searchQueries as $searchQuery) {
            $hits = $this->searchOnce($searchQuery, $topK, $minScore);

            $perQuery[$searchQuery] = $hits
                ->map(fn (array $match) => [
                    'chunk_id' => (int) $match['chunk']->id,
                    'score' => round((float) $match['score'], 4),
                ])
                ->all();

            foreach ($hits as $match) {
                $this->rememberBest($bestByChunkId, $match, $searchQuery);
            }
        }

        // Hybrid search: merge exact-keyword matches (portable LIKE on chunk
        // content) so names, emails, phones, and other exact tokens survive
        // even when embeddings blur them. Vector score wins on overlap; a
        // keyword hit that the vector pass missed is still admitted.
        if ((bool) config('rag-chat.retrieval.hybrid.enabled', true)) {
            foreach ($this->keywordSearch($query, $topK) as $match) {
                $this->rememberBest($bestByChunkId, $match, 'keyword');
            }
        }

        $ordered = collect($bestByChunkId)
            ->sortByDesc(fn (array $entry) => $entry['match']['score'])
            ->take($topK)
            ->values();

        $matches = $this->rerank($query, $ordered->map(fn (array $entry) => $entry['match'])->values());

        $queryFor = collect($bestByChunkId)->map(fn (array $entry) => $entry['query']);

        $traceMatches = $matches->map(fn (array $match) => [
            'chunk_id' => (int) $match['chunk']->id,
            'score' => round((float) $match['score'], 4),
            'query' => $queryFor[(int) $match['chunk']->id] ?? $query,
        ])->all();

        return new RetrievalTrace(
            query: $query,
            generatedQueries: array_values($searchQueries),
            perQuery: $perQuery,
            matches: $traceMatches,
            reranked: $this->rerankConfigured(),
            retrieved: $matches,
        );
    }

    /**
     * @param  array<int, array{match: array{chunk: \Awais\RagChat\Models\RagChunk, score: float}, query: string}>  $bestByChunkId
     * @param  array{chunk: \Awais\RagChat\Models\RagChunk, score: float}  $match
     */
    protected function rememberBest(array &$bestByChunkId, array $match, string $query): void
    {
        $chunkId = (int) $match['chunk']->id;

        if (! isset($bestByChunkId[$chunkId]) || $match['score'] > $bestByChunkId[$chunkId]['match']['score']) {
            $bestByChunkId[$chunkId] = ['match' => $match, 'query' => $query];
        }
    }

    /**
     * @return Collection<int, array{chunk: \Awais\RagChat\Models\RagChunk, score: float}>
     */
    protected function searchOnce(string $query, int $topK, float $minScore): Collection
    {
        $queryVector = $this->embedder->embedQuery($query);

        return $this->store->search($queryVector, $topK, $minScore);
    }

    /**
     * Portable exact-token search over chunk content, scoped to the active
     * project. Store-agnostic: runs directly against the chunks table so it
     * behaves identically on the JSON and MySQL drivers.
     *
     * @return Collection<int, array{chunk: \Awais\RagChat\Models\RagChunk, score: float}>
     */
    protected function keywordSearch(string $query, int $topK): Collection
    {
        $terms = Terms::extract($query);

        if ($terms === []) {
            return collect();
        }

        $chunks = RagChunk::query()
            ->select(['id', 'document_id', 'position', 'content', 'embedding', 'meta'])
            ->where(function (Builder $builder) use ($terms) {
                foreach ($terms as $term) {
                    $builder->orWhere('content', 'like', '%'.addcslashes($term, '%_\\').'%');
                }
            });

        if ($projectId = RagProjectScope::get()) {
            $chunks->whereHas('document', fn ($documents) => $documents->where('project_id', $projectId));
        }

        $weight = (float) config('rag-chat.retrieval.hybrid.keyword_weight', 0.8);

        // Fetch a superset and let the score-based merge pick the top-K;
        // ponytail: DB-order truncation at scale, use a scored subquery if a
        // corpus grows past tens of thousands of chunks.
        return $chunks->limit($topK * 3)->get()
            ->map(fn (RagChunk $chunk) => [
                'chunk' => $chunk,
                'score' => $this->keywordScore($weight, $terms, $chunk->content),
            ])
            ->values();
    }

    /**
     * A keyword hit scores at least the configured weight, rising toward 1.0
     * as a larger share of the query terms match the chunk.
     *
     * @param  list<string>  $terms
     */
    protected function keywordScore(float $weight, array $terms, string $content): float
    {
        $content = mb_strtolower($content);

        $matched = collect($terms)
            ->filter(fn (string $term) => str_contains($content, $term))
            ->count();

        if ($matched === 0) {
            return 0.0;
        }

        return min(1.0, $weight + (0.1 * ($matched / count($terms))));
    }

    /**
     * @return Collection<int, array{chunk: \Awais\RagChat\Models\RagChunk, score: float}>
     */
    protected function rerank(string $query, Collection $matches): Collection
    {
        return $this->reranker()->rerank($query, $matches);
    }

    /**
     * Resolve the configured reranker: null/'none' disables it, 'lexical'
     * selects the built-in term-overlap reranker, anything else is treated
     * as a Reranker class-string resolved from the container.
     */
    protected function reranker(): Reranker
    {
        $configured = config('rag-chat.retrieval.reranker');

        if ($configured === null || $configured === 'none' || $configured === false) {
            return new NoopReranker;
        }

        if ($configured === 'lexical') {
            return new LexicalReranker;
        }

        return app((string) $configured);
    }

    protected function rerankConfigured(): bool
    {
        return ! in_array(config('rag-chat.retrieval.reranker'), [null, 'none', false], true);
    }
}
