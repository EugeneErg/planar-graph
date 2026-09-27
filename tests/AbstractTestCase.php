<?php

declare(strict_types=1);

namespace Tests;

use EugeneErg\Graphs\Aggregates\ArticulationVertexesAggregate;
use EugeneErg\Graphs\Aggregates\SliceAggregate;
use EugeneErg\Graphs\Exceptions\InvalidConnectionException;
use EugeneErg\Graphs\Exceptions\InvalidVertexValueException;
use EugeneErg\Graphs\Services\ArcService;
use EugeneErg\Graphs\Services\CanvasService;
use EugeneErg\Graphs\Services\CoordinateService;
use EugeneErg\Graphs\Services\EdgeService;
use EugeneErg\Graphs\Services\GeometryService;
use EugeneErg\Graphs\Services\GraphService;
use EugeneErg\Graphs\Services\IntersectionService;
use EugeneErg\Graphs\Services\PlanarService;
use EugeneErg\Graphs\Services\SvgService;
use EugeneErg\Graphs\Services\TreeService;
use EugeneErg\Graphs\Services\VertexService;
use EugeneErg\Graphs\ValueObjects\DirectionGraph;
use EugeneErg\Graphs\ValueObjects\Edge;
use EugeneErg\Graphs\ValueObjects\Graph;
use EugeneErg\Graphs\ValueObjects\GraphInterface;
use EugeneErg\Graphs\ValueObjects\Point2D;
use EugeneErg\Graphs\ValueObjects\ZeroSlice;
use Exception;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionMethod;

abstract class AbstractTestCase extends TestCase
{
    protected function getCanvasService(): CanvasService
    {
        return new CanvasService();
    }

    protected function getGraphService(?CanvasService $canvasService = null): GraphService
    {
        return new GraphService($canvasService ?? $this->getCanvasService());
    }

    protected function getTreeService(
        ?CanvasService $canvasService = null,
        ?GraphService $graphService = null,
    ): TreeService {
        $canvasService ??= $this->getCanvasService();

        return new TreeService(
            $canvasService,
            $graphService ?? $this->getGraphService($canvasService),
        );
    }

    protected function getVertexService(
        ?CanvasService $canvasService = null,
        ?GraphService $graphService = null,
        ?EdgeService $edgeService = null,
        ?IntersectionService $intersectionService = null,
    ): VertexService {
        $canvasService ??= $this->getCanvasService();
        $graphService ??= $this->getGraphService($canvasService);
        $intersectionService ??= $this->getIntersectService($canvasService);
        $edgeService ??= $this->getEdgeService($canvasService, $intersectionService, $graphService);

        return new VertexService($graphService, $edgeService);
    }

    protected function getArcService(): ArcService
    {
        return new ArcService();
    }

    protected function getGeometryService(): GeometryService
    {
        return new GeometryService();
    }

    protected function getCoordinateService(): CoordinateService
    {
        return new CoordinateService();
    }

    protected function getSvgService(): SvgService
    {
        return new SvgService();
    }

    /**
     * Сколько пар рёбер пересекается. У плоской укладки ноль.
     *
     * @param array<int, array<int, mixed>> $connections
     * @param Point2D[] $coordinates
     */
    protected static function countCrossings(array $connections, array $coordinates): int
    {
        $geometry = new GeometryService();
        $edges = [];

        foreach ($connections as $vertexA => $connection) {
            foreach (array_keys($connection) as $vertexB) {
                if ($vertexA < $vertexB && isset($coordinates[$vertexA], $coordinates[$vertexB])) {
                    $edges[] = [$vertexA, $vertexB];
                }
            }
        }

        $count = count($edges);
        $result = 0;

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                [$a, $b] = $edges[$i];
                [$c, $d] = $edges[$j];

                if ($a === $c || $a === $d || $b === $c || $b === $d) {
                    continue;
                }

                if ($geometry->segmentsIntersect($coordinates[$a], $coordinates[$b], $coordinates[$c], $coordinates[$d])) {
                    $result++;
                }
            }
        }

        return $result;
    }

    /**
     * @param Edge[] $faces
     *
     * @return array<int, array<int, true>> связи, восстановленные по граням
     */
    protected static function facesToConnections(array $faces): array
    {
        $result = [];

        foreach ($faces as $face) {
            $vertexes = $face->vertexes;
            $count = count($vertexes);

            for ($i = 0; $i < $count; $i++) {
                $a = $vertexes[$i];
                $b = $vertexes[($i + 1) % $count];

                if ($a !== $b) {
                    $result[$a][$b] = true;
                    $result[$b][$a] = true;
                }
            }
        }

        return $result;
    }

    /**
     * @param Point2D[] $coordinates
     */
    protected static function getMinVertexDistance(array $coordinates): float
    {
        $geometry = new GeometryService();
        $points = array_values($coordinates);
        $count = count($points);
        $result = INF;

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $result = min($result, $geometry->distance($points[$i], $points[$j]));
            }
        }

        return $result === INF ? .0 : $result;
    }

    /**
     * Насколько укладка читаема: ближайшее расстояние и между вершинами,
     * и между вершиной и чужим ребром. Разнести вершины мало — вершина,
     * налезшая на чужое ребро, читается как пересечение, которого нет.
     *
     * @param Point2D[] $coordinates
     * @param true[][] $connections
     */
    protected static function getReadability(array $coordinates, array $connections): float
    {
        $geometry = new GeometryService();
        $result = self::getMinVertexDistance($coordinates);

        foreach ($coordinates as $vertex => $point) {
            foreach ($connections as $vertexA => $row) {
                foreach (array_keys($row) as $vertexB) {
                    if ($vertexA < $vertexB
                        && $vertex !== $vertexA
                        && $vertex !== $vertexB
                        && isset($coordinates[$vertexA], $coordinates[$vertexB])
                    ) {
                        $result = min($result, $geometry->distanceToSegment($point, $coordinates[$vertexA], $coordinates[$vertexB]));
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Полный конвейер до граней: связи => [внешняя грань, внутренние грани].
     *
     * @param true[][] $connections
     *
     * @return array{Edge, Edge[]}
     *
     * @throws Exception
     */
    protected function getFaces(array $connections, ?SliceAggregate $slice = null): array
    {
        $slice ??= new SliceAggregate(new ZeroSlice());
        $graphService = $this->getGraphService();
        $graph = $graphService->createFromConnections($connections);
        $subGraphs = $graphService->splitGraphOnDisconnected($graph);
        $tree = $this->getTreeService()->fromConnectionGraph(new ArticulationVertexesAggregate($subGraphs[0]));
        $edgeService = $this->getEdgeService();
        $cube = [];

        foreach ($tree->branches as $position => $branch) {
            $treeEdge = $edgeService->splitOnTreeEdges($branch, $slice);
            $list = [$treeEdge->edge];
            $parents = [$treeEdge];

            for ($i = 0; $i < count($parents); $i++) {
                foreach ($parents[$i]->children as $child) {
                    $child->children === [] ? $list[] = $child->edge : $parents[] = $child;
                }
            }

            $cube[$position] = $list;
        }

        $faces = $this->getVertexService()->mergeTree($cube, $tree->connections, $slice);
        uasort($faces, static fn (Edge $a, Edge $b): int => count($b->vertexes) <=> count($a->vertexes));
        /** @var int $outerKey */
        $outerKey = $slice->getKey($faces);
        $outerEdge = $faces[$outerKey];
        unset($faces[$outerKey]);

        return [$outerEdge, array_values($faces)];
    }

    protected function getPlanarService(
        ?CanvasService $canvasService = null,
        ?GraphService $graphService = null,
        ?TreeService $treeService = null,
        ?EdgeService $edgeService = null,
        ?IntersectionService $intersectionService = null,
        ?ArcService $arcService = null,
        ?CoordinateService $coordinateService = null,
    ): PlanarService {
        $canvasService ??= $this->getCanvasService();
        $graphService ??= $this->getGraphService($canvasService);
        $treeService ??= $this->getTreeService($canvasService, $graphService);
        $intersectionService ??= $this->getIntersectService($canvasService);
        $edgeService ??= $this->getEdgeService($canvasService, $intersectionService, $graphService);

        return new PlanarService(
            $graphService,
            $treeService,
            $edgeService,
            $this->getVertexService($canvasService, $graphService, $edgeService, $intersectionService),
            $arcService ?? $this->getArcService(),
            $coordinateService ?? new CoordinateService(),
        );
    }

    protected static function getSimpleTriangle(int $shift = 0): array
    {
        return self::shiftVertexes($shift, require __DIR__.'/Cases/Graphs/SimpleTriangle.php');
    }

    protected static function getLine(int $shift = 0): array
    {
        return self::shiftVertexes($shift, require __DIR__.'/Cases/Graphs/Line.php');
    }

    protected static function getThreeLines(int $shift = 0): array
    {
        return self::shiftVertexes($shift, require __DIR__.'/Cases/Graphs/ThreeConnectedLInes.php');
    }

    protected static function getDot(int $shift = 0): array
    {
        return self::shiftVertexes($shift, require __DIR__.'/Cases/Graphs/Dot.php');
    }

    protected static function getRectangle(int $shift = 0): array
    {
        return self::shiftVertexes($shift, require __DIR__.'/Cases/Graphs/SimpleRectangle.php');
    }

    protected static function getTriangleInTriangle(int $shift = 0): array
    {
        return self::shiftVertexes($shift, require __DIR__.'/Cases/Graphs/TriangleInTriangle.php');
    }

    protected static function getTriangleInTriangleInTriangle(int $shift = 0): array
    {
        return self::shiftVertexes($shift, require __DIR__.'/Cases/Graphs/TriangleInTriangleInTriangle.php');
    }

    protected static function getBig1(int $shift = 0): array
    {
        return self::shiftVertexes($shift, require __DIR__.'/Cases/Graphs/Big1.php');
    }

    /**
     * Большой несвязный граф с перешейками: два куска, в каждом несколько
     * двусвязных блоков, висящие деревья и точки сочленения между ними.
     *
     * @return true[][]
     */
    protected static function getBig2(int $shift = 0): array
    {
        return self::shiftVertexes($shift, require __DIR__.'/Cases/Graphs/Big2.php');
    }

    /**
     * Семь несвязных кусков, в каждом семь двусвязных: цепочка из семи
     * треугольников на общих вершинах сочленения.
     *
     * @return true[][]
     */
    protected static function getSevenBySeven(int $shift = 0): array
    {
        return self::shiftVertexes($shift, require __DIR__.'/Cases/Graphs/SevenBySeven.php');
    }

    /**
     * Три несвязных куска, в каждом три двусвязных, и каждый двусвязный —
     * колесо с семью спицами, то есть семь полей внутри и обод снаружи.
     *
     * @return true[][]
     */
    protected static function getSevenFields(int $shift = 0): array
    {
        return self::shiftVertexes($shift, require __DIR__.'/Cases/Graphs/SevenFields.php');
    }

    protected static function getSmallTree(int $shift = 0): array
    {
        return self::shiftVertexes($shift, require __DIR__.'/Cases/Graphs/SmallTree.php');
    }

    protected static function shiftVertexes(int $shift, array $connections): array
    {
        return $shift === 0
            ? $connections
            : self::setVertexes(array_map(fn (int $vertex) => $vertex + $shift, array_keys($connections)), $connections);
    }

    protected static function setVertexes(array $vertexes, array $connections): array
    {
        $result = [];
        $replayVertexes = array_combine(array_keys($connections), $vertexes);

        foreach ($connections as $vertexA => $connection) {
            foreach ($connection as $vertexB => $value) {
                $result[$replayVertexes[$vertexA]][$replayVertexes[$vertexB]] = $value;
            }
        }

        return $result;
    }

    protected static function merge(bool $randomize, array ...$allConnections): array
    {
        $vertexCount = 0;
        $shifts = [];
        $connectionByNewVertex = [];

        foreach ($allConnections as $pos => $connections) {
            $shifts[$pos] = $vertexCount;
            $countConnections = count($connections);

            for ($i = 0; $i < $countConnections; $i++) {
                $connectionByNewVertex[$vertexCount + $i] = $pos;
            }

            $vertexCount += $countConnections;
        }

        $vertexes = range(0, $vertexCount - 1);

        if ($randomize) {
            $vertexes = array_rand($vertexes, $vertexCount);
        }

        $result = [];

        foreach ($vertexes as $newVertexA) {
            $pos = $connectionByNewVertex[$newVertexA];
            $shift = $shifts[$pos];
            $oldVertexA = $newVertexA - $shift;
            $result[$newVertexA] = [];

            foreach ($allConnections[$pos][$oldVertexA] as $oldVertexB => $value) {
                $result[$newVertexA][$oldVertexB + $shift] = $value;
            }
        }

        return $result;
    }

    protected static function graphToMatrix(GraphInterface $graph): array
    {
        $sizes = [];
        $maxSize = 0;
        $row = [];

        $vertexes = $graph->getVertexes();
        sort($vertexes);

        foreach ($vertexes as $vertex) {
            $row[] = $vertex;
            $size = strlen((string) $vertex);
            $sizes[] = $size;
            $maxSize = max($size, $maxSize);
        }

        $stringRow = implode('|', $row);
        $result = [str_pad('', $maxSize) => $stringRow];

        foreach ($vertexes as $vertexA) {
            $row = [];
            $key = str_pad((string) $vertexA, $maxSize);

            foreach ($vertexes as $pos => $vertexB) {
                $row[] = str_pad($graph->hasValue($vertexA, $vertexB) ? (string) $graph->getValue($vertexA, $vertexB) : '', $sizes[$pos]);
            }

            $result[$key] = implode('|', $row);
        }

        return $result;
    }

    protected static function changeValue(array $list, callable $callback): array
    {
        array_walk($list, function (mixed &$value) use ($callback) {
            $value = $callback($value);
        });

        return $list;
    }

    protected function getIntersectService(?CanvasService $canvasService = null): IntersectionService
    {
        return new IntersectionService($canvasService ?? $this->getCanvasService());
    }

    protected function getEdgeService(
        ?CanvasService $canvasService = null,
        ?IntersectionService $intersectionService = null,
        ?GraphService $graphService = null,
    ): EdgeService {
        $canvasService ??= $this->getCanvasService();

        return new EdgeService(
            $canvasService,
            $intersectionService ?? $this->getIntersectService($canvasService),
            $graphService ?? $this->getGraphService($canvasService),
        );
    }

    protected function runPrivateMethod(array $callback, mixed &...$parameters): mixed
    {
        try {
            $method = new ReflectionMethod(...$callback);
            $method->setAccessible(true);

            return $method->invokeArgs($callback[0], $parameters);
        } catch (ReflectionException $exception) {
            throw new LogicException($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @param bool[][] $branch
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     */
    protected function arrayToDirectionGraph(array $branch): DirectionGraph
    {
        return $this->getGraphService()->graphToDirection($this->getGraphService()->createFromConnections($branch));
    }

    protected function generateRandomGraph(int $size, int $weight, bool $connected, bool $disconnected): Graph
    {
        //todo
        $connections = [];
        $outerVertexes = [];

        for ($i = 0; $i < $size; $i++) {
        }

        return new Graph($connections, range(0, $size - 1));
    }
}
