<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\Aggregates\ArticulationVertexesAggregate;
use EugeneErg\Graphs\Aggregates\SliceAggregate;
use EugeneErg\Graphs\Aggregates\Trace;
use EugeneErg\Graphs\Exceptions\InvalidConnectionException;
use EugeneErg\Graphs\Exceptions\InvalidVertexValueException;
use EugeneErg\Graphs\ValueObjects\Edge;
use EugeneErg\Graphs\ValueObjects\Graph;
use EugeneErg\Graphs\ValueObjects\Point2D;
use EugeneErg\Graphs\ValueObjects\StageKind;
use EugeneErg\Graphs\ValueObjects\Topology;
use EugeneErg\Graphs\ValueObjects\Tree;
use EugeneErg\Graphs\ValueObjects\TreeEdge;
use Exception;

/**
 * Собирает плоскую укладку графа: от списка связей до готовой картинки.
 *
 * Граф разбивается на связные куски, каждый кусок — на двусвязные ветви,
 * ветви — на грани. Грани склеиваются в единую плоскую укладку, по ней
 * расставляются координаты, и укладка расслабляется до читаемого вида.
 */
readonly class PlanarService
{
    /** На этом шаге вершины едут, поэтому к нему привязаны кадры укладки. */
    public const string RELAX_CAPTION = 'Расслабление: вершины расходятся, не теряя планарности';

    public function __construct(
        private GraphService $graphService,
        private TreeService $treeService,
        private EdgeService $edgeService,
        private VertexService $vertexService,
        private ArcService $arcService,
        private CoordinateService $coordinateService,
        private SvgService $svgService = new SvgService(),
        private StoryService $storyService = new StoryService(),
        private GeometryService $geometry = new GeometryService(),
    ) {
    }

    /**
     * Анимированная картинка: клубок вершин на окружности распутывается
     * в плоскую укладку и расходится до читаемого вида.
     *
     * @param true[][] $connections
     *
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     * @throws Exception
     */
    public function connectionsToSvg(array $connections, SliceAggregate $sliceAggregate, float $radius = 100.0): string
    {
        $trace = new Trace();
        $frames = $this->connectionsToFrames($connections, $sliceAggregate, $radius, $trace);

        return $this->svgService->animate($this->storyService->build($trace, $frames, $connections));
    }

    /**
     * Итоговая укладка: вершина => координата.
     *
     * @param true[][] $connections
     *
     * @return Point2D[]
     *
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     * @throws Exception
     */
    public function connectionsToCoordinates(array $connections, SliceAggregate $sliceAggregate, float $radius = 100.0): array
    {
        $frames = $this->connectionsToFrames($connections, $sliceAggregate, $radius);

        return $frames[count($frames) - 1];
    }

    /**
     * Все состояния укладки по порядку. Нулевой кадр — исходный клубок:
     * все вершины на одной окружности в порядке номеров.
     *
     * @param true[][] $connections
     *
     * @return Point2D[][]
     *
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     * @throws Exception
     */
    public function connectionsToFrames(
        array $connections,
        SliceAggregate $sliceAggregate,
        float $radius = 100.0,
        ?Trace $trace = null,
    ): array {
        $graph = $this->graphService->createFromConnections($connections);
        $trace?->add(StageKind::Graph, sprintf('Граф: вершин — %d, рёбер — %d', count($connections), $this->countEdges($connections)));

        // Сжимать двусвязные группы вершин не нужно: дальше между ними ищется расстояние.

        // Сначала разбираем весь граф на поля, и только потом строим: иначе
        // рассказ скачет между разбором одного куска и сборкой другого.
        $parts = [];

        foreach ($this->graphService->splitGraphOnDisconnected($graph, $trace) as $subGraph) {
            $parts[] = $this->getComponentFaces($subGraph, $sliceAggregate, $trace);
        }

        $components = [];

        foreach ($parts as $part) {
            $components[] = $part === null
                ? [[]]
                : $this->getComponentFrames($part[0], $part[1], $radius, $trace);
        }

        // Вершина без связей не попадает ни в один кусок, но на картинке быть обязана.
        foreach ($this->getPlacedVertexes($components) as $vertex => $placed) {
            unset($connections[$vertex]);
        }

        foreach (array_keys($connections) as $vertex) {
            $components[] = [[$vertex => new Point2D()]];
        }

        if ($components === []) {
            return [[]];
        }

        $trace?->add(StageKind::Relax, self::RELAX_CAPTION);

        $frames = $this->mergeComponents($this->shiftComponents($components, $radius));
        $tangled = $this->coordinateService->getTangledCoordinates(
            array_keys($frames[0]),
            $this->getTangledRadius($frames[count($frames) - 1], $radius),
        );

        array_unshift($frames, $tangled);

        return $frames;
    }

    /**
     * @param array<int, array<int, mixed>> $connections
     */
    private function countEdges(array $connections): int
    {
        $result = 0;

        foreach ($connections as $vertexA => $connection) {
            foreach (array_keys($connection) as $vertexB) {
                if ($vertexA < $vertexB) {
                    $result++;
                }
            }
        }

        return $result;
    }

    /**
     * @param Point2D[][][] $components
     *
     * @return array<int, true>
     */
    private function getPlacedVertexes(array $components): array
    {
        $result = [];

        foreach ($components as $frames) {
            foreach ($frames as $frame) {
                foreach (array_keys($frame) as $vertex) {
                    $result[$vertex] = true;
                }
            }
        }

        return $result;
    }

    /**
     * Разбор одного связного куска: ветви, поля, внешняя грань.
     *
     * @return array{Edge, Edge[]}|null null, если граней не нашлось
     *
     * @throws Exception
     */
    private function getComponentFaces(Graph $subGraph, SliceAggregate $sliceAggregate, ?Trace $trace = null): ?array
    {
        $tree = $this->treeService->fromConnectionGraph(new ArticulationVertexesAggregate($subGraph), $trace);
        $faces = $this->treeToFaces($tree, $sliceAggregate, $trace);

        // Срез выбирает внешнюю грань по позиции, поэтому кандидаты ставятся
        // в порядке предпочтения: чем грань крупнее, тем больше места внутри
        // остаётся остальным вершинам.
        uasort($faces, static fn (Edge $a, Edge $b): int => count($b->vertexes) <=> count($a->vertexes));

        $outerKey = $sliceAggregate->getKey($faces);

        if ($outerKey === null) {
            return null;
        }

        $outerEdge = $faces[$outerKey];
        unset($faces[$outerKey]);

        $trace?->add(
            StageKind::Faces,
            sprintf('Граней плоской укладки: %d', count($faces) + 1),
            array_map(static fn (Edge $face): array => array_values(array_unique($face->vertexes)), array_values($faces)),
        );
        $trace?->add(
            StageKind::OuterFace,
            sprintf('Внешняя грань: %s', implode(' - ', $outerEdge->vertexes)),
            [],
            $outerEdge->vertexes,
        );

        return [$outerEdge, $faces];
    }

    /**
     * Кадры укладки одного связного куска: построение по полям и расслабление.
     *
     * @param Edge[] $faces
     *
     * @return Point2D[][]
     */
    private function getComponentFrames(Edge $outerEdge, array $faces, float $radius, ?Trace $trace = null): array
    {
        $arcs = $this->arcService->createArcs($faces, $outerEdge, $trace);
        $coordinates = $this->coordinateService->getCoordinates(new Topology($outerEdge, $arcs), $radius, trace: $trace);
        $barycentric = $this->coordinateService->getBarycentricCoordinates($outerEdge, $faces, $coordinates);

        return array_merge(
            [$coordinates],
            $this->coordinateService->relaxSteps($outerEdge, $faces, $barycentric),
        );
    }

    /**
     * Разносит связные куски по горизонтали, чтобы они не наезжали друг на друга.
     *
     * @param Point2D[][][] $components
     *
     * @return Point2D[][][]
     */
    private function shiftComponents(array $components, float $radius): array
    {
        if (count($components) < 2) {
            return $components;
        }

        $gap = $radius / 2;
        $offset = .0;
        $result = [];

        foreach ($components as $frames) {
            [$minX, $maxX] = $this->getHorizontalBounds($frames);
            $shift = $offset - $minX;
            $result[] = array_map(
                static fn (array $frame): array => array_map(
                    static fn (Point2D $point): Point2D => new Point2D($point->x + $shift, $point->y),
                    $frame,
                ),
                $frames,
            );
            $offset += $maxX - $minX + $gap;
        }

        return $result;
    }

    /**
     * @param Point2D[][] $frames
     *
     * @return array{float, float}
     */
    private function getHorizontalBounds(array $frames): array
    {
        $min = INF;
        $max = -INF;

        foreach ($frames as $frame) {
            foreach ($frame as $point) {
                $min = min($min, $point->x);
                $max = max($max, $point->x);
            }
        }

        return $min === INF ? [.0, .0] : [$min, $max];
    }

    /**
     * Куски расслабляются одновременно, поэтому короткие последовательности
     * кадров продлеваются последним состоянием.
     *
     * @param Point2D[][][] $components
     *
     * @return Point2D[][]
     */
    private function mergeComponents(array $components): array
    {
        $length = max(array_map(count(...), $components));
        $result = [];

        for ($i = 0; $i < $length; $i++) {
            $frame = [];

            foreach ($components as $frames) {
                $frame += $frames[min($i, count($frames) - 1)];
            }

            ksort($frame);
            $result[] = $frame;
        }

        return $result;
    }

    /**
     * Радиус исходного клубка.
     *
     * Вершины на окружности должны стоять не теснее, чем в итоговой укладке,
     * иначе клубок слипается в кашу и разглядеть в нём нечего.
     *
     * @param Point2D[] $frame итоговая укладка
     */
    private function getTangledRadius(array $frame, float $radius): float
    {
        $count = count($frame);

        if ($count < 2) {
            return $radius;
        }

        $spacing = max($this->getMinVertexDistance($frame), $radius / 4);

        return max($radius, $spacing / (2 * sin(M_PI / $count)));
    }

    /**
     * @param Point2D[] $frame
     */
    private function getMinVertexDistance(array $frame): float
    {
        $points = array_values($frame);
        $count = count($points);
        $result = INF;

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $result = min($result, $this->geometry->distance($points[$i], $points[$j]));
            }
        }

        return $result === INF ? .0 : $result;
    }

    /**
     * @return Edge[]
     */
    private function edgeToList(TreeEdge $tree): array
    {
        $parents = [$tree];
        $result = [$tree->edge];

        for ($i = 0; $i < count($parents); $i++) {
            $parent = $parents[$i];

            foreach ($parent->children as $child) {
                $child->children === [] ? $result[] = $child->edge : $parents[] = $child;
            }
        }

        return $result;
    }

    /**
     * @return Edge[]
     *
     * @throws Exception
     */
    private function treeToFaces(Tree $tree, SliceAggregate $sliceAggregate, ?Trace $trace = null): array
    {
        /** @var Edge[][] $edgesCube */
        $edgesCube = [];

        foreach ($tree->branches as $branchPos => $branch) {
            $edgesCube[$branchPos] = $this->edgeToList(
                $this->edgeService->splitOnTreeEdges($branch, $sliceAggregate, trace: $trace),
            );
        }

        return $this->vertexService->mergeTree($edgesCube, $tree->connections, $sliceAggregate, $trace);
    }
}
