<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Instruction;

/**
 * Точек сочленения нет: кусок двусвязен целиком, резать его на ветви нечего.
 */
final readonly class Biconnected implements ActionInterface
{
    /**
     * @param int[] $vertexes
     */
    public function __construct(public array $vertexes)
    {
    }

    public function describe(): string
    {
        return 'точек сочленения нет — двусвязный целиком';
    }
}
