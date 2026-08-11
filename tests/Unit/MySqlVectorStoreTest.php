<?php

namespace Awais\RagChat\Tests\Unit;

use Awais\RagChat\Models\RagChunk;
use Awais\RagChat\Models\RagDocument;
use Awais\RagChat\Rag\Stores\MySqlVectorStore;
use Awais\RagChat\Tests\TestCase;

class MySqlVectorStoreTest extends TestCase
{
    public function test_insert_persists_json_embeddings(): void
    {
        $document = RagDocument::create([
            'source' => 'fixture',
            'title' => 'Fixture',
            'checksum' => hash('sha256', 'fixture'),
        ]);

        $store = new MySqlVectorStore();
        $store->insert($document->id, [[
            'position' => 0,
            'content' => 'hello world',
            'vector' => [0.1, 0.2, 0.3],
            'dimensions' => 3,
            'meta' => null,
        ]]);

        $chunk = RagChunk::first();

        $this->assertNotNull($chunk);
        $this->assertSame('hello world', $chunk->content);
        $this->assertSame([0.1, 0.2, 0.3], $chunk->embedding);
        $this->assertSame(3, $chunk->dimensions);
    }

    public function test_search_query_uses_mysql_distance_helpers(): void
    {
        $store = new MySqlVectorStore();
        $query = $store->newSearchQuery([0.1, 0.2, 0.3], topK: 5, minScore: 0.25);

        $sql = $query->toSql();
        $bindings = $query->getBindings();

        $this->assertStringContainsString('DISTANCE(', $sql);
        $this->assertStringContainsString('STRING_TO_VECTOR(', $sql);
        $this->assertStringContainsString('COSINE', $sql);
        $this->assertContains(json_encode([0.1, 0.2, 0.3]), $bindings);
        $this->assertContains(0.25, $bindings);
    }
}
