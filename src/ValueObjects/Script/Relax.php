<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Script;

/**
 * Отпустить укладку: шары расходятся, палки распрямляются.
 *
 * Сколько на это уйдёт кадров, решает уже отрисовка: промежуточное состояние
 * остаётся только там, без чего рисунок по дороге пересёкся бы.
 */
final readonly class Relax implements ActionInterface
{
    public function describe(): string
    {
        return 'дать укладке разойтись';
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
