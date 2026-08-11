<?php

namespace Awais\RagChat\Rag;

use InvalidArgumentException;

class Chunker
{
    public function __construct(
        protected int $size = 1000,
        protected int $overlap = 200,
    ) {
        if ($this->size <= 0) {
            throw new InvalidArgumentException('Chunk size must be greater than zero.');
        }

        if ($this->overlap < 0 || $this->overlap >= $this->size) {
            throw new InvalidArgumentException('Chunk overlap must be >= 0 and less than the chunk size.');
        }
    }

    /**
     * Split text into overlapping character windows, preferring to break on
     * paragraph/sentence/word boundaries near the window edge.
     *
     * @return string[]
     */
    public function chunk(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        // mb-safe length; operate on characters, not bytes.
        $length = mb_strlen($text);

        if ($length <= $this->size) {
            return [$text];
        }

        $chunks = [];
        $start = 0;
        $step = $this->size - $this->overlap;

        while ($start < $length) {
            $end = min($start + $this->size, $length);

            // Try to snap $end back to a natural boundary within the window,
            // but only if it doesn't shrink the chunk too aggressively.
            if ($end < $length) {
                $end = $this->snapToBoundary($text, $start, $end);
            }

            $piece = trim(mb_substr($text, $start, $end - $start));

            if ($piece !== '') {
                $chunks[] = $piece;
            }

            if ($end >= $length) {
                break;
            }

            // Advance; guarantee forward progress even if boundary snapping
            // pulled $end close to $start.
            $start = max($start + $step, $end - $this->overlap);
        }

        return $chunks;
    }

    /**
     * Find a natural break (paragraph > newline > sentence > space) scanning
     * backwards from $end, without going before the halfway point of the window.
     */
    protected function snapToBoundary(string $text, int $start, int $end): int
    {
        $window = mb_substr($text, $start, $end - $start);
        $floor = (int) (($end - $start) / 2); // don't snap back past the midpoint

        foreach (["\n\n", "\n", '. ', ' '] as $needle) {
            $pos = mb_strrpos($window, $needle);

            if ($pos !== false && $pos >= $floor) {
                return $start + $pos + mb_strlen($needle);
            }
        }

        return $end;
    }
}
