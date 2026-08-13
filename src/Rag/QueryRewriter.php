<?php

namespace Awais\RagChat\Rag;

use Awais\RagChat\Agents\RagAgent;
use Awais\RagChat\Citations\EntityPrioritizer;

/**
 * Conversation-aware query rewriting (Tier 4, off by default).
 *
 * Turns the latest user question into a standalone retrieval query so
 * pronouns and references ("he", "the company", "it") resolve against earlier
 * turns before anything is searched.
 *
 * Modes (rag-chat.conversation.rewrite):
 *  - heuristic (default): no LLM call. If the question is pronoun-led and an
 *    earlier user message named an entity, the entity is prepended.
 *  - llm: one small prompt through the SDK RagAgent to produce a standalone
 *    query. Optional — costs a generation call per rewritten turn.
 *  - disabled: the question passes through untouched.
 *
 * History shape: list of {role: 'user'|'assistant', content: string}.
 */
class QueryRewriter
{
    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    public function rewrite(string $question, array $history): string
    {
        if (! (bool) config('rag-chat.conversation.enabled', false) || $history === []) {
            return $question;
        }

        // Never rewrite a question that already names its own entity — "What
        // is the IT policy?" or "Tell me about his Acme project" must not be
        // polluted with an entity pulled from history.
        if (! $this->needsRewrite($question)) {
            return $question;
        }

        return match ((string) config('rag-chat.conversation.rewrite', 'heuristic')) {
            'llm' => $this->rewriteWithLlm($question, $history),
            'disabled' => $question,
            default => $this->rewriteHeuristically($question, $history),
        };
    }

    /**
     * Only pronoun-led questions without their own explicit entity need
     * rewriting — everything else passes through untouched (no LLM cost).
     */
    protected function needsRewrite(string $question): bool
    {
        if (! $this->looksPronounLed($question)) {
            return false;
        }

        return (new EntityPrioritizer)->entityTokens($question) === [];
    }

    /**
     * If the question is pronoun-led, resolve it against the most recent
     * user message that names an entity.
     *
     * @param  list<array{role: string, content: string}>  $history
     */
    protected function rewriteHeuristically(string $question, array $history): string
    {
        if (! $this->looksPronounLed($question)) {
            return $question;
        }

        $entity = $this->entityFromHistory($history);

        if ($entity === null) {
            return $question;
        }

        // Title-case the entity but keep the connective lowercase so the
        // entity gate (which extracts capitalized words) does not mistake the
        // prefix for a proper noun.
        return 'regarding '.mb_convert_case($entity, MB_CASE_TITLE).", {$question}";
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    protected function rewriteWithLlm(string $question, array $history): string
    {
        $turns = array_slice($history, -(int) config('rag-chat.conversation.history_turns', 4));

        $conversation = collect($turns)
            ->map(fn (array $turn) => strtoupper((string) $turn['role']).': '.(string) $turn['content'])
            ->implode("\n");

        try {
            $response = (new RagAgent)->prompt(
                "Rewrite the user's last question into a standalone search query that resolves pronouns and references using the conversation. "
                ."Reply with ONLY the rewritten query, nothing else.\n\n"
                ."Conversation:\n{$conversation}\n"
                ."Question: {$question}"
            );

            $rewritten = trim((string) ($response->text ?? $response));

            return $rewritten !== '' ? $rewritten : $question;
        } catch (\Throwable $exception) {
            report($exception);

            return $question;
        }
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    protected function entityFromHistory(array $history): ?string
    {
        // Newest user message first — the entity is usually named there.
        foreach (array_reverse($history) as $turn) {
            if (($turn['role'] ?? '') !== 'user') {
                continue;
            }

            $tokens = (new EntityPrioritizer)->entityTokens((string) ($turn['content'] ?? ''));

            if ($tokens !== []) {
                return implode(' ', $tokens);
            }
        }

        return null;
    }

    protected function looksPronounLed(string $question): bool
    {
        return (bool) preg_match('/^\s*(what|who|where|when|why|how|is|are|does|do|can|could|would|tell|give)\s+(he|she|it|they|him|her|his|its|their|them|this|that)\b/i', $question)
            || (bool) preg_match('/\b(he|she|it|they|him|her|his|its|their|them)\b/i', $question);
    }
}
