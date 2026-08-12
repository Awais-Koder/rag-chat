<?php

namespace Awais\RagChat\Tests\Feature;

use Awais\RagChat\Agents\CitedRagAgent;
use Awais\RagChat\Agents\RagAgent;
use Awais\RagChat\RagChat;
use Awais\RagChat\Tests\Support\FakeEmbeddings;
use Awais\RagChat\Tests\TestCase;
use Awais\RagChat\Tools\SearchKnowledge;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\SimilaritySearch;

class ChatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Embeddings::fake(FakeEmbeddings::closure(32));
    }

    public function test_rag_agent_exposes_search_knowledge_tool(): void
    {
        $tools = iterator_to_array((new RagAgent)->tools());

        $this->assertCount(1, $tools);
        $this->assertInstanceOf(SimilaritySearch::class, $tools[0]);
        $this->assertInstanceOf(SimilaritySearch::class, SearchKnowledge::make());
    }

    public function test_rag_agent_instructions_require_grounded_retrieval(): void
    {
        $instructions = (string) (new RagAgent)->instructions();

        $this->assertStringContainsString('call the knowledge search tool at least once', $instructions);
        $this->assertStringContainsString('reformulated queries', $instructions);
        $this->assertStringContainsString('not found in the knowledge base', $instructions);
        $this->assertStringContainsString('Do not guess', $instructions);
        $this->assertStringContainsString('Do not wait for the user to mention a PDF', $instructions);
        $this->assertStringContainsString('Context section', $instructions);
    }

    public function test_search_knowledge_description_encourages_query_expansion(): void
    {
        $description = SearchKnowledge::make()->description();

        $this->assertStringContainsString('retrieval-oriented', strtolower($description));
        $this->assertStringContainsString('multiple times', strtolower($description));
        $this->assertStringContainsString('ingested', strtolower($description));
    }

    public function test_chat_endpoint_returns_answer_and_sources(): void
    {
        CitedRagAgent::fake([
            'The AcmeMover X1 is priced at 45,000 US dollars per unit.',
        ]);

        $rag = $this->app->make(RagChat::class);
        $rag->ingest(__DIR__.'/../fixtures/acme');

        $response = $this->postJson('/rag-chat/chat', [
            'message' => 'How much does the AcmeMover X1 cost?',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'answer',
                'sources' => [
                    ['document_id', 'title', 'source', 'score', 'excerpt'],
                ],
            ])
            ->assertJsonMissingPath('messages')
            ->assertJsonMissingPath('toolCalls');

        $this->assertStringContainsString('45,000', $response->json('answer'));
        $this->assertNotEmpty($response->json('sources'));
    }

    public function test_answer_helper_returns_plain_text_only(): void
    {
        // respond() drives the citation-aware agent, so that is the class to fake.
        // The question must not read as a proper-noun entity (e.g. "Anything")
        // or the grounding gate short-circuits before the LLM runs.
        CitedRagAgent::fake(['Plain answer text only.']);

        $text = $this->app->make(RagChat::class)->answer('hello there');

        $this->assertSame('Plain answer text only.', $text);
    }

    public function test_chat_endpoint_validates_missing_message(): void
    {
        $response = $this->postJson('/rag-chat/chat', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }
}
