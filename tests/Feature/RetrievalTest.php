<?php

namespace Awais\RagChat\Tests\Feature;

use Awais\RagChat\RagChat;
use Awais\RagChat\Tests\Support\FakeEmbeddings;
use Awais\RagChat\Tools\SearchKnowledge;
use Awais\RagChat\Tests\TestCase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\SimilaritySearch;

class RetrievalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Embeddings::fake(FakeEmbeddings::closure(32));
    }

    protected function ingestAcmeCorpus(RagChat $rag): void
    {
        $rag->ingest(__DIR__.'/../fixtures/acme');
    }

    public function test_retrieval_surfaces_the_relevant_document(): void
    {
        $rag = $this->app->make(RagChat::class);
        $this->ingestAcmeCorpus($rag);

        $matches = $rag->retrieve('What is the monthly subscription payment and net 30 terms?');

        $this->assertNotEmpty($matches, 'Expected retrieval to return matches.');

        $topSource = $matches->first()['chunk']->document->source;
        $this->assertStringContainsString('pricing.md', $topSource);

        $scores = $matches->pluck('score')->all();
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores, 'Matches should be ordered by descending score.');
    }

    public function test_vague_contact_question_retrieves_contact_chunk(): void
    {
        $rag = $this->app->make(RagChat::class);

        $rag->ingestText(
            'Muhammad Awais — Email: awaistayyab27@gmail.com — Phone: +92 300 7926926 — Location: Bahawalnagar, Pakistan',
            'awais-profile',
            'Awais profile',
        );

        $matches = $rag->retrieve('what is the contact info?');

        $this->assertNotEmpty($matches);

        $combined = $matches
            ->map(fn (array $match) => $match['chunk']->content)
            ->implode(' ');

        $this->assertStringContainsString('awaistayyab27@gmail.com', $combined);
        $this->assertStringContainsString('+92 300 7926926', $combined);
    }

    public function test_prompt_message_includes_retrieved_context(): void
    {
        $rag = $this->app->make(RagChat::class);
        $this->ingestAcmeCorpus($rag);

        $prompt = $rag->promptMessage('What is the monthly subscription payment?');

        $this->assertStringContainsString('Context:', $prompt);
        $this->assertStringContainsString('Question: What is the monthly subscription payment?', $prompt);
    }

    public function test_context_and_sources_helpers_return_rag_data_without_llm(): void
    {
        $rag = $this->app->make(RagChat::class);
        $this->ingestAcmeCorpus($rag);

        $context = $rag->context('What is the monthly subscription payment and net 30 terms?');
        $sources = $rag->sources('What is the monthly subscription payment and net 30 terms?');

        $this->assertStringContainsString('Question:', $context);
        $this->assertStringContainsString('Context:', $context);
        $this->assertNotEmpty($sources);
        $this->assertArrayHasKey('document_id', $sources[0]);
        $this->assertArrayHasKey('score', $sources[0]);
        $this->assertArrayHasKey('excerpt', $sources[0]);
    }

    public function test_search_knowledge_tool_queries_the_corpus(): void
    {
        $rag = $this->app->make(RagChat::class);
        $this->ingestAcmeCorpus($rag);

        $tool = SearchKnowledge::make();

        $this->assertInstanceOf(SimilaritySearch::class, $tool);

        $result = $tool->handle(new Request([
            'query' => 'What is the monthly subscription payment and net 30 terms?',
        ]));

        $this->assertStringContainsString('Relevant results found', $result);
        $this->assertStringContainsString('pricing', strtolower($result));
    }

    public function test_documents_endpoint_ingests_raw_text(): void
    {
        $response = $this->postJson('/rag-chat/documents', [
            'text' => 'Acme Robotics was founded in 2015 in Portland, Oregon.',
            'title' => 'Founding facts',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['document' => ['id', 'title', 'source', 'chunks']]);

        $this->assertGreaterThan(0, $response->json('document.chunks'));
    }
}
