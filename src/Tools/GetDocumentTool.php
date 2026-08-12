<?php

namespace Awais\RagChat\Tools;

use Awais\RagChat\Models\RagChunk;
use Awais\RagChat\Models\RagDocument;
use Awais\RagChat\RagChat;
use Awais\RagChat\Support\RagProjectScope;
use Awais\RagChat\Support\ResultAuthorizer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Read-only tool: return the full indexed text of one document by its id.
 *
 * READ tool only — it cannot modify anything. Results are scoped to the
 * active project (RagProjectScope) and passed through the host's
 * authorizeResultsUsing() filter when one is registered.
 */
class GetDocumentTool implements Tool
{
    public function name(): string
    {
        return 'get_document';
    }

    public function description(): Stringable|string
    {
        return 'Read the full indexed text of a single document by its document_id. '
            .'Use this when a search result references a document you need more detail from, '
            .'or to inspect an entire document before answering. Takes one argument: document_id (integer).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->integer()->required()->description('The id of the indexed document to read.'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $id = (int) ($request->all()['document_id'] ?? 0);

        $query = RagDocument::query()->where('id', $id);

        if ($projectId = RagProjectScope::get()) {
            $query->where('project_id', $projectId);
        }

        $document = $query->first();

        if (! $document) {
            return 'No document found with that id.';
        }

        $chunks = $this->applyFilter($document->chunks()->orderBy('position')->get());

        if ($chunks->isEmpty()) {
            return 'Document found but it is not available to this conversation.';
        }

        // Cap the tool result so a large document never blows the context window.
        $text = Str::limit(
            $chunks->pluck('content')->map(fn (string $content) => trim($content))->filter()->implode("\n\n"),
            6000,
        );

        return sprintf(
            "Document #%d: %s\nSource: %s\n\n%s",
            $document->id,
            $document->title,
            $document->source,
            $text,
        );
    }

    /**
     * Apply the host-registered authorization filter, if any.
     *
     * @param  Collection<int, RagChunk>  $chunks
     * @return Collection<int, RagChunk>
     */
    protected function applyFilter(Collection $chunks): Collection
    {
        // Guard like SearchKnowledge does: tools must not hard-require the
        // RagChat container binding when used standalone.
        $filter = app()->bound(RagChat::class)
            ? app(RagChat::class)->authorizationFilter()
            : null;

        $allowedIds = ResultAuthorizer::allowedIds($chunks, $filter);

        return $chunks
            ->filter(fn (RagChunk $chunk) => in_array((int) $chunk->id, $allowedIds, true))
            ->values();
    }
}
