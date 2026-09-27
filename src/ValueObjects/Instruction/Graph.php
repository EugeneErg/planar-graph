<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Instruction;

/**
 * С чего всё начинается: вершины и рёбра.
 */
final readonly class Graph implements ActionInterface
{
    /**
     * @param int[] $vertexes
     * @param array<int, array{int, int}> $edges
     */
    public function __construct(
        public array $vertexes,
        public array $edges,
    ) {
    }

    public function describe(): string
    {
        return 'граф  вершины: ' . Format::vertexes($this->vertexes)
            . "\n      рёбра:   " . Format::edges($this->edges);
    }
}
