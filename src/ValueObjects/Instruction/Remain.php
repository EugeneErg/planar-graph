<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Instruction;

/**
 * Что осталось после всех разрезов, то и есть последняя часть: резать её
 * не нужно, она просто получает свою арену.
 */
final readonly class Remain implements ActionInterface
{
    /**
     * @param int[] $vertexes
     */
    public function __construct(
        public array $vertexes,
        public string $arena,
    ) {
    }

    public function describe(): string
    {
        return 'остаток ' . Format::vertexes($this->vertexes) . ' — арена ' . $this->arena;
    }
}
