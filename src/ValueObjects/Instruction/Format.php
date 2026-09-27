<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Instruction;

/**
 * Как вершины и рёбра пишутся в тексте инструкции.
 */
final class Format
{
    /**
     * @param int[] $vertexes
     */
    public static function vertexes(array $vertexes): string
    {
        return implode(' ', $vertexes);
    }

    /**
     * @param array<int, array{int, int}> $edges
     */
    public static function edges(array $edges): string
    {
        return implode(' ', array_map(static fn (array $edge): string => $edge[0] . '-' . $edge[1], $edges));
    }
}
