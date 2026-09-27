<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Script;

/**
 * Одна волна заливки: краска переползает по палкам к соседним шарам.
 *
 * Шар закрашен, дальше от него по палке ползёт полоса того же цвета и доходит
 * до соседа ровно к концу действия — тогда сосед и становится закрашенным.
 * Без этого видно только, что шары меняют цвет, а кто кого закрасил — нет.
 */
final readonly class Paint implements ActionInterface
{
    /**
     * @param array<int, int> $groups шар => каким цветом он закрашен
     * @param array<int, int> $flows шар => от какого шара к нему пришла краска
     */
    public function __construct(public array $groups, public array $flows)
    {
    }

    public function describe(): string
    {
        return 'закрасить ' . implode(',', array_keys($this->flows));
    }

    public function isQuestion(): bool
    {
        return true;
    }

    /**
     * @return int[]
     */
    public function vertexes(): array
    {
        return array_map(intval(...), array_keys($this->groups));
    }
}
