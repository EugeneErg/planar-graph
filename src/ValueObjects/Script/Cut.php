<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Script;

/**
 * Разрезать кусок по замкнутому обходу: отрезанная грань уезжает на своё место.
 *
 * Палка лежит ровно между двумя гранями, поэтому режут её дважды — по разу
 * с каждой стороны. После первого разреза у остатка остаётся своя половинка,
 * и её видно по цвету.
 */
final readonly class Cut implements ActionInterface
{
    /**
     * @param int[] $walk обход отрезаемой грани
     */
    public function __construct(public array $walk)
    {
    }

    public function describe(): string
    {
        return 'вырезать грань ' . implode('-', $this->walk);
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
        return $this->walk;
    }
}
