<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Instruction;

/**
 * Последний обход, когда внутренних рёбер не осталось, — контур ветви.
 * Он тоже поле, только внешнее, и остаётся на арене ветви.
 */
final readonly class Contour implements ActionInterface
{
    /**
     * @param int[] $walk
     */
    public function __construct(
        public array $walk,
        public string $arena,
    ) {
    }

    public function describe(): string
    {
        return 'остался контур ' . implode('-', $this->walk) . ' — арена ' . $this->arena;
    }
}
