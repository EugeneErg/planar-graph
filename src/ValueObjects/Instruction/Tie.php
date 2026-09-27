<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Instruction;

/**
 * Новая связь — единственное, что в графе прибавляется. Её нет в исходном
 * графе, она нужна, чтобы односвязный граф стал двусвязным.
 */
final readonly class Tie implements ActionInterface
{
    public function __construct(
        public int $vertexA,
        public int $vertexB,
        public string $arena,
        public string $other,
    ) {
    }

    public function describe(): string
    {
        return sprintf('связать %d-%d', $this->vertexA, $this->vertexB);
    }
}
