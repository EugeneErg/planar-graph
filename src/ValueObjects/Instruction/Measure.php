<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Instruction;

/**
 * От точки склейки отмеряют по половине каждого из двух полей: куда
 * дошли, те вершины и свяжут.
 */
final readonly class Measure implements ActionInterface
{
    /**
     * @param int[] $first путь по первому полю, от точки склейки
     * @param int[] $second путь по второму полю, от точки склейки
     */
    public function __construct(
        public int $vertex,
        public array $first,
        public array $second,
        public string $arena,
        public string $other,
    ) {
    }

    public function describe(): string
    {
        return sprintf('отмерить от %d: %s и %s', $this->vertex, implode('-', $this->first), implode('-', $this->second));
    }
}
