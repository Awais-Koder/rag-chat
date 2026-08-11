<?php

namespace Awais\RagChat\Rag;

use Laravel\Ai\Embeddings;

class Embedder
{
    public function __construct(
        protected ?string $provider = null,
        protected ?string $model = null,
        protected ?int $dimensions = null,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            provider: config('rag-chat.embedding.provider'),
            model: config('rag-chat.embedding.model'),
            dimensions: config('rag-chat.embedding.dimensions'),
        );
    }

    public function dimensions(): ?int
    {
        return $this->dimensions;
    }

    /**
     * Embed a single string, returning its vector.
     *
     * @return array<float>
     */
    public function embed(string $input): array
    {
        return $this->embedMany([$input])[0];
    }

    /**
     * Embed a search query. Alias of embed() for call-site clarity; query and
     * document embeddings must use identical provider/model/dimensions.
     *
     * @return array<float>
     */
    public function embedQuery(string $query): array
    {
        return $this->embed($query);
    }

    /**
     * Embed a batch of document chunks. Alias of embedMany() for call-site
     * clarity; document and query embeddings must use identical settings.
     *
     * @param  string[]  $inputs
     * @return array<int, array<float>>
     */
    public function embedDocuments(array $inputs): array
    {
        return $this->embedMany($inputs);
    }

    /**
     * Embed a list of strings, returning one vector per input (order preserved).
     *
     * @param  string[]  $inputs
     * @return array<int, array<float>>
     */
    public function embedMany(array $inputs): array
    {
        $inputs = array_values($inputs);

        if ($inputs === []) {
            return [];
        }

        $pending = Embeddings::for($inputs);

        if ($this->dimensions !== null) {
            $pending = $pending->dimensions($this->dimensions);
        }

        $response = $pending->generate($this->provider ?: null, $this->model ?: null);

        return $response->embeddings;
    }
}
