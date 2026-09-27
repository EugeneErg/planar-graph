<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Instruction;

/**
 * Запереть вершины цветом: краска через них не пройдёт.
 */
final readonly class Lock implements ActionInterface
{
    /**
     * @param int[] $vertexes
     * @param bool $walk заперт целый обход — кандидат в поле, а не точка
     *                   сочленения
     */
    public function __construct(public array $vertexes, public bool $walk = false)
    {
    }

    public function describe(): string
    {
        return $this->walk
            ? 'запереть обход ' . implode('-', $this->vertexes)
            : 'запереть ' . Format::vertexes($this->vertexes);
    }
}
