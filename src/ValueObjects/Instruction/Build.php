<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Instruction;

/**
 * Поле ложится в укладку — туда, где его вершины поставил алгоритм.
 * Копии вершин, которые уже лежат в укладке, ложатся на них.
 */
final readonly class Build implements ActionInterface
{
    /**
     * @param int[] $walk
     */
    public function __construct(
        public string $arena,
        public array $walk,
    ) {
    }

    public function describe(): string
    {
        return sprintf('уложить поле %s (арена %s)', implode('-', $this->walk), $this->arena);
    }
}
