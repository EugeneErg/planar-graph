<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Instruction;

/**
 * Отрезать часть и унести на арену.
 *
 * Все факты разреза перечислены явно. Вынимаемое уходит целиком: у остатка
 * этих вершин и рёбер больше нет. Раздваиваемое остаётся у обоих: остатку
 * своя копия, отрезанному — своя. Несвязный кусок уходит без раздвоений;
 * ветвь забирает копию своей точки сочленения.
 */
final readonly class Cut implements ActionInterface
{
    /**
     * @param int[] $vertexes вершины, которые вынимаются
     * @param array<int, array{int, int}> $edges рёбра, которые вынимаются
     * @param int[] $twinVertexes вершины, которые раздваиваются
     * @param array<int, array{int, int}> $twinEdges рёбра, которые раздваиваются
     * @param ?int[] $walk обход отрезаемого поля, если режут поле: в этом
     *                     порядке поле и выглядит многоугольником
     */
    public function __construct(
        public array $vertexes,
        public array $edges,
        public array $twinVertexes,
        public array $twinEdges,
        public string $arena,
        public ?array $walk = null,
    ) {
    }

    public function describe(): string
    {
        $facts = [];

        if ($this->vertexes !== []) {
            $facts[] = 'вынуть ' . Format::vertexes($this->vertexes);
        }

        if ($this->edges !== []) {
            $facts[] = ($this->vertexes === [] ? 'вынуть рёбра ' : 'рёбра ') . Format::edges($this->edges);
        }

        $twins = [];

        if ($this->twinVertexes !== []) {
            $twins[] = 'раздвоить ' . Format::vertexes($this->twinVertexes);
        }

        if ($this->twinEdges !== []) {
            $twins[] = 'рёбра ' . Format::edges($this->twinEdges);
        }

        return ($this->walk === null ? 'отрезать: ' : 'отрезать поле ' . implode('-', $this->walk) . ': ')
            . implode('; ', array_filter([implode(', ', $facts), implode(', ', $twins)]))
            . ' → арена ' . $this->arena;
    }
}
