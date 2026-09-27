<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Script;

/**
 * Вынести наружу то, что не поместилось внутрь запертого обхода.
 *
 * Это и есть проверка планарности. На запертом обходе висят куски; каждый
 * должен лечь либо внутрь него, либо снаружи. Два куска, которые мешают друг
 * другу, внутрь не помещаются — один уходит наружу. Если развести их не
 * удаётся вовсе, граф не планарен.
 *
 * На столе это третий круг: обход лежит в середине, висящее на нём — вокруг,
 * а признанное внешним уезжает наружу. Тогда видно и вопрос, и ответ.
 */
final readonly class Aside implements ActionInterface
{
    /**
     * @param int[] $vertexes
     */
    public function __construct(public array $vertexes)
    {
    }

    public function describe(): string
    {
        return 'вынести наружу ' . implode(',', $this->vertexes);
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
        return $this->vertexes;
    }
}
