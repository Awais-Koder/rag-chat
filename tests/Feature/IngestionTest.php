<?php

namespace Awais\RagChat\Tests\Feature;

use Awais\RagChat\Facades\RagChat;
use Awais\RagChat\Models\RagChunk;
use Awais\RagChat\Models\RagDocument;
use Awais\RagChat\Tests\Support\FakeEmbeddings;
use Awais\RagChat\Tests\TestCase;
use Laravel\Ai\Embeddings;

class IngestionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Embeddings::fake(FakeEmbeddings::closure(32));
    }

    public function test_ingesting_text_persists_a_document_and_embedded_chunks(): void
    {
        $document = RagChat::ingestText(
            text: str_repeat('Acme Corp offers cloud hosting. ', 50),
            source: 'acme-overview',
            title: 'Acme Overview',
        );

        $this->assertInstanceOf(RagDocument::class, $document);
        $this->assertDatabaseHas('rag_documents', ['title' => 'Acme Overview']);
        $this->assertGreaterThan(0, RagChunk::count());

        // Every chunk stored a non-empty embedding vector.
        RagChunk::all()->each(function (RagChunk $chunk) {
            $this->assertIsArray($chunk->embedding);
            $this->assertNotEmpty($chunk->embedding);
        });
    }

    public function test_identical_content_is_deduplicated_by_checksum(): void
    {
        $text = 'Acme Corp was founded in 2010 and is headquartered in Berlin.';

        $first = RagChat::ingestText($text, source: 'a');
        $chunkCountAfterFirst = RagChunk::count();

        $second = RagChat::ingestText($text, source: 'b');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, RagDocument::count());
        $this->assertSame($chunkCountAfterFirst, RagChunk::count());
    }
}
