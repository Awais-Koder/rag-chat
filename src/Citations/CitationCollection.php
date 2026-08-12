<?php

namespace Awais\RagChat\Citations;

use ArrayIterator;
use Countable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * An ordered, deduplicated list of citations.
 *
 * Order is preserved from the validated response. A grouped() view is
 * available for the widget so multiple chunks from the same document can be
 * shown as a single source entry with a page range.
 */
final class CitationCollection implements Arrayable, Countable, IteratorAggregate, Jsonable, JsonSerializable
{
    /**
     * @param  list<Citation>  $citations
     */
    public function __construct(
        protected array $citations = [],
    ) {
    }

    public function count(): int
    {
        return count($this->citations);
    }

    public function isEmpty(): bool
    {
        return $this->citations === [];
    }

    /**
     * @return list<Citation>
     */
    public function all(): array
    {
        return $this->citations;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(fn (Citation $citation) => $citation->toArray(), $this->citations);
    }

    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->citations);
    }

    /**
     * Group citations by document, merging page numbers into ranges.
     *
     * @return list<array{
     *     id: int,
     *     document_id: int,
     *     document_name: string,
     *     document_type: string|null,
     *     source_url: string|null,
     *     pages: list<int>,
     *     page_label: string|null,
     * }>
     */
    public function grouped(): array
    {
        $groups = [];
        $order = [];

        foreach ($this->citations as $citation) {
            $key = $citation->documentId.'|'.$citation->documentName;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'id' => $citation->id,
                    'document_id' => $citation->documentId,
                    'document_name' => $citation->documentName,
                    'document_type' => $citation->documentType,
                    'source_url' => $citation->sourceUrl,
                    'pages' => [],
                    'page_label' => null,
                ];
                $order[] = $key;
            }

            if ($citation->page !== null) {
                $groups[$key]['pages'][] = $citation->page;
            }
        }

        return array_values(array_map(function (array $group) {
            $pages = array_values(array_unique($group['pages']));
            sort($pages);

            $group['pages'] = $pages;
            $group['page_label'] = $this->pageLabel($pages);

            return $group;
        }, array_intersect_key($groups, array_flip($order))));
    }

    /**
     * Turn a page list into a compact human label (e.g. "Page 2", "Pages 1–3").
     */
    protected function pageLabel(array $pages): ?string
    {
        if ($pages === []) {
            return null;
        }

        if (count($pages) === 1) {
            return 'Page '.$pages[0];
        }

        return 'Pages '.implode('–', [$pages[0], $pages[count($pages) - 1]]);
    }
}
