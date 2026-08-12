<?php

namespace Awais\RagChat\Tools;

use Awais\RagChat\Models\RagChunk;
use Awais\RagChat\Models\RagDocument;
use Awais\RagChat\Support\RagProjectScope;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Read-only tool: return the chunks of one document whose text matches a
 * section, heading, or topic term — so the agent can drill into a specific
 * part of a document without loading the whole file.
 *
 * READ tool only. Project-scoped like every other retrieval path.
 */
class GetDocumentSectionTool extends GetDocumentTool
{
    public function name(): string
    {
        return 'get_document_section';
    }

    public function description(): Stringable|string
    {
        return 'Read the chunks of a single document that belong to a section, heading, or topic. '
            .'Takes two arguments: document_id (integer) and section (string). '
            .'Use this when you know the document but need only the part that covers a specific topic.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->integer()->required()->description('The id of the indexed document.'),
            'section' => $schema->string()->required()->description('The section, heading, or topic term to look for inside the document.'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $id = (int) ($request->all()['document_id'] ?? 0);
        $term = mb_strtolower(trim((string) ($request->all()['section'] ?? '')));

        $query = RagDocument::query()->where('id', $id);

        if ($projectId = RagProjectScope::get()) {
            $query->where('project_id', $projectId);
        }

        $document = $query->first();

        if (! $document) {
            return 'No document found with that id.';
        }

        $chunks = $this->applyFilter($document->chunks()->orderBy('position')->get());

        $matches = $chunks->filter(function (RagChunk $chunk) use ($term) {
            if ($term === '') {
                return true;
            }

            if (str_contains(mb_strtolower($chunk->content), $term)) {
                return true;
            }

            foreach (['section', 'heading', 'title'] as $key) {
                if (str_contains(mb_strtolower((string) ($chunk->meta[$key] ?? '')), $term)) {
                    return true;
                }
            }

            return false;
        });

        if ($matches->isEmpty()) {
            return sprintf('No chunks in document #%d mention "%s".', $id, $term);
        }

        $text = Str::limit(
            $matches->pluck('content')->map(fn (string $content) => trim($content))->filter()->implode("\n\n"),
            5000,
        );

        return sprintf(
            "Document #%d: %s — section \"%s\"\n\n%s",
            $document->id,
            $document->title,
            $term === '' ? 'all' : $term,
            $text,
        );
    }
}
