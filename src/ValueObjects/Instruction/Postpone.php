<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Instruction;

/**
 * Поле прилегает к границе укладки одним ребром: дугу между соседними
 * вершинами не натянуть. Оно откладывается над этим ребром и ждёт.
 */
final readonly class Postpone implements ActionInterface
{
    /**
     * @param int[] $walk
     */
    public function __construct(
        public string $arena,
        public array $walk,
        public int $vertexA,
        public int $vertexB,
    ) {
    }

    public function describe(): string
    {
        return sprintf('отложить поле %s (арена %s) над ребром %d-%d', implode('-', $this->walk), $this->arena, $this->vertexA, $this->vertexB);
    }
}
