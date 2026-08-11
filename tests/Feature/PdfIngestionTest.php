<?php

namespace Awais\RagChat\Tests\Feature;

use Awais\RagChat\Facades\RagChat;
use Awais\RagChat\Models\RagChunk;
use Awais\RagChat\Models\RagDocument;
use Awais\RagChat\Tests\Support\FakeEmbeddings;
use Awais\RagChat\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Laravel\Ai\Embeddings;

class PdfIngestionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Embeddings::fake(FakeEmbeddings::closure(32));
    }

    public function test_ingesting_a_pdf_file_persists_embedded_chunks(): void
    {
        $path = __DIR__.'/../fixtures/acme/pricing.pdf';

        $document = RagChat::ingest($path, [
            'title' => 'Acme Pricing PDF',
        ]);

        $this->assertInstanceOf(RagDocument::class, $document);
        $this->assertSame('Acme Pricing PDF', $document->title);
        $this->assertGreaterThan(0, RagChunk::count());

        $contents = RagChunk::pluck('content')->implode(' ');
        $this->assertStringContainsString('AcmeMover X1', $contents);
    }

    public function test_documents_endpoint_accepts_pdf_upload(): void
    {
        $fixture = __DIR__.'/../fixtures/acme/pricing.pdf';
        $upload = new UploadedFile(
            $fixture,
            'pricing.pdf',
            'application/pdf',
            null,
            true,
        );

        $response = $this->post('/rag-chat/documents', [
            'file' => $upload,
            'title' => 'Uploaded Pricing PDF',
            'source' => 'uploads/pricing.pdf',
        ]);

        $response->assertCreated()
            ->assertJsonPath('document.title', 'Uploaded Pricing PDF')
            ->assertJsonPath('document.source', 'uploads/pricing.pdf');

        $this->assertGreaterThan(0, $response->json('document.chunks'));
        $this->assertDatabaseHas('rag_documents', [
            'title' => 'Uploaded Pricing PDF',
            'source' => 'uploads/pricing.pdf',
        ]);
    }
}
