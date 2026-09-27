<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Script;

/**
 * Уложить один кусок в укладку.
 *
 * Один — потому что в кадре должно быть видно, что именно встало на место
 * и к чему пристроилось. На одном шаге алгоритма кусков сходится несколько,
 * и каждому нужно своё действие.
 */
final readonly class Build implements ActionInterface
{
    public function __construct(public int $piece)
    {
    }

    public function describe(): string
    {
        return 'уложить кусок ' . $this->piece;
    }

    public function isQuestion(): bool
    {
        return false;
    }

    /**
     * @return int[]
     */
    public function vertexes(): array
    {
        return [];
    }
}
