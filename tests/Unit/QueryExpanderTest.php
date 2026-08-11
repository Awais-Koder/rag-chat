<?php

namespace Awais\RagChat\Tests\Unit;

use Awais\RagChat\Rag\QueryExpander;
use PHPUnit\Framework\TestCase;

class QueryExpanderTest extends TestCase
{
    public function test_contact_intent_adds_keyword_queries(): void
    {
        $queries = QueryExpander::queries('what is the contact info?');

        $this->assertSame('what is the contact info?', $queries[0]);
        $this->assertTrue(collect($queries)->contains(
            fn (string $q) => str_contains($q, 'email') && str_contains($q, 'phone')
        ));
    }

    public function test_unrelated_question_keeps_only_original_query(): void
    {
        $queries = QueryExpander::queries('Tell me about warehouse robot navigation sensors');

        $this->assertSame(['Tell me about warehouse robot navigation sensors'], $queries);
    }
}
