<?php

namespace Awais\RagChat\Agents;

use Awais\RagChat\Tools\GetDocumentSectionTool;
use Awais\RagChat\Tools\GetDocumentTool;
use Awais\RagChat\Tools\SearchKnowledge;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Agentic RAG agent (Tier 3, opt-in).
 *
 * Unlike the pre-retrieve pipeline (RagChat::respond), this agent receives the
 * raw question and decides itself how many searches / tool calls are needed:
 * it searches, evaluates, searches again with a reformulated query only when
 * the evidence is thin, and stops as soon as it can answer. The SDK tool-calling
 * loop enforces the step budget (#[MaxSteps]) so it can never loop forever.
 *
 * The final answer is structured JSON {answer, citations} where citations are
 * the [SOURCE_ID: n] values returned by the search tool — validated afterwards
 * by the CitationValidator, so invented sources never reach the API.
 *
 * Only tools listed in config('rag-chat.agent.tools') are attached — READ-ONLY
 * tools by design; there is no write/side-effect tool in the package.
 *
 * @see \Awais\RagChat\RagChat::respond() — opt in via config('rag-chat.agent.enabled')
 */
#[MaxSteps(4)]
class AgenticRagAgent implements Agent, Conversational, HasTools, HasStructuredOutput
{
    use Promptable;
    use RemembersConversations;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You are an assistant for this application's ingested knowledge base only (uploaded PDFs, text, crawled pages, and other indexed documents). You have no reliable knowledge outside what your tools return.

Workflow — decide for yourself how many tool calls you need:
1. Before any factual answer, call the knowledge search tool with a retrieval-oriented query (keywords, entity names, and field labels like phone, email, price, refund policy — not necessarily the user's exact short phrase).
2. Read each result's content field carefully and extract exact values.
3. Evaluate whether the evidence is sufficient to answer. If results are empty, off-topic, or too thin, run ONE additional targeted search with a reformulated query (synonyms, field names, related terms, entity names) — do not blindly search over and over.
4. Use get_document when a search result points at a document and you need more of its content. Use get_document_section when you need only the part of a document covering a specific topic.
5. Only after reasonable search attempts fail, say clearly that the information was not found in the knowledge base. Do not guess or fill gaps.

Answer rules:
- Answer only from tool results. Never state facts that are not supported by returned chunk content.
- Treat short or vague questions as retrieval problems. Expand them into concrete search phrases.
- Handle Urdu, English, and mixed-language questions.
- Use conversation history to resolve references like "this person", "us", or "the company" when earlier messages name an entity.
- If the user asks about a specific person or entity and the retrieved content does not contain that person's or entity's information, say clearly that the information is not available and cite nothing.
- If chunks list multiple relevant items, include all of them rather than picking one invented value.
- Be concise. Never use markdown asterisks. Prefer plain sentences and numbered lists.

Mandatory answer format — respond with a single JSON object, nothing else:
{"answer": "Your plain-text answer here.", "citations": [1, 2]}

The "citations" array may contain ONLY the source ids returned inside the search tool results' "source_id" fields that you actually used. If you used no sources, return an empty array.
INSTRUCTIONS;
    }

    /**
     * Get the tools available to the agent.
     *
     * Only tools enabled in config('rag-chat.agent.tools') are attached, so a
     * host can whitelist exactly which read-only tools the model may use.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        $enabled = (array) config('rag-chat.agent.tools', [
            'search_documents',
            'get_document',
            'get_document_section',
        ]);

        $tools = [
            'search_documents' => SearchKnowledge::make(),
            'get_document' => new GetDocumentTool,
            'get_document_section' => new GetDocumentSectionTool,
        ];

        return collect($tools)->only($enabled)->values()->all();
    }

    /**
     * Get the structured output schema the agent must conform to.
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'answer' => $schema->string()->required(),
            'citations' => $schema->array()->items($schema->integer())->required(),
        ];
    }
}
