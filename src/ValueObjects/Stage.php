<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects;

/**
 * Один шаг алгоритма, записанный в журнал.
 *
 * Шаг описан по смыслу, без единой координаты: где что окажется на картинке,
 * решает уже отрисовка. Группы — это разбиение вершин (заливки, куски графа,
 * ветви, грани), выделение — вершины, о которых шаг.
 */
final readonly class Stage
{
    /**
     * @param array<int, int[]> $groups номер группы => её вершины
     * @param int[] $highlight
     * @param array<int, int> $flows вершина => от какой вершины к ней пришло;
     *                               по этим связям краска и переползает
     * @param array<int, int[]> $produced что шаг сделал из групп: обходы граней,
     *                                    получившихся на склейке
     */
    public function __construct(
        public StageKind $kind,
        public string $caption,
        public array $groups = [],
        public array $highlight = [],
        public array $flows = [],
        public array $produced = [],
    ) {
    }

    public function isMoving(): bool
    {
        return $this->kind->isMoving();
    }

    /**
     * @return array<int, int> вершина => номер её группы
     */
    public function getVertexGroups(): array
    {
        $result = [];

        foreach ($this->groups as $group => $vertexes) {
            foreach ($vertexes as $vertex) {
                $result[$vertex] ??= $group;
            }
        }

        return $result;
    }
}
