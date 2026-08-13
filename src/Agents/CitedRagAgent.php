<?php

namespace Awais\RagChat\Agents;

use Awais\RagChat\Tools\SearchKnowledge;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Citation-aware RAG agent.
 *
 * Same knowledge-base grounding as RagAgent, but the final answer must be
 * emitted as structured JSON:
 *
 *     {"answer": "...", "citations": [1, 2]}
 *
 * The `citations` array may ONLY contain [SOURCE_ID: n] identifiers that were
 * present in the provided context. Anything else is rejected later by the
 * CitationValidator, so fabricated sources can never reach the API.
 */
class CitedRagAgent implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable;
    use RemembersConversations;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You are an assistant for this application's ingested knowledge base only (uploaded PDFs, text, and other indexed documents). You have no reliable knowledge outside the retrieved context.

The user message contains a Context section with numbered excerpts. Each excerpt starts with a line like:

[SOURCE_ID: 1]
Document: <name>
Page: <n>

That SOURCE_ID is the ONLY identifier you may use to cite that excerpt.

Mandatory answer format — respond with a single JSON object, nothing else:
{"answer": "Your plain-text answer here.", "citations": [1, 2]}

Rules:
1. Answer ONLY from the provided excerpts. Never state facts that are not supported by excerpt content.
2. The "answer" field must be plain text: no JSON escaping of newlines beyond normal string values, no markdown asterisks, no markdown formatting.
3. The "citations" array must contain ONLY the SOURCE_ID values of excerpts you actually used to write the answer.
4. If the user asks about a specific person or entity and the retrieved excerpts do not contain that person's or entity's information, say clearly that the information is not available in the knowledge base and return an empty citations array.
5. If the question is general conversation (greeting, small talk) and no excerpt supports the answer, still answer briefly but return an empty citations array.
6. Never invent SOURCE_IDs, document names, page numbers, or URLs. Never cite an excerpt you did not use.
7. When excerpts list multiple relevant items (contacts, prices, dates), include all of them rather than picking one invented value.
8. If two excerpts disagree, say so briefly and report what each source contains.
9. If the retrieved context contains no relevant documents at all, answer that the information is not available in the knowledge base.
INSTRUCTIONS;
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [
            SearchKnowledge::make(),
        ];
    }

    /**
     * Get the structured output schema the agent must conform to.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'answer' => $schema->string()->required(),
            'citations' => $schema->array()->items($schema->integer())->required(),
        ];
    }
}
