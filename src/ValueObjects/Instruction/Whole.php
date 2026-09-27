<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Instruction;

/**
 * Ветвь меньше чем из четырёх вершин сама себе поле: искать в ней нечего.
 */
final readonly class Whole implements ActionInterface
{
    /**
     * @param int[] $vertexes
     */
    public function __construct(public array $vertexes)
    {
    }

    public function describe(): string
    {
        return 'меньше четырёх вершин — поле целиком';
    }
}
