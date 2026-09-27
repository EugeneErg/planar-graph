<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\Aggregates\Canvas;
use EugeneErg\Graphs\Aggregates\SliceAggregate;
use EugeneErg\Graphs\Aggregates\Trace;
use EugeneErg\Graphs\ValueObjects\DirectionGraph;
use EugeneErg\Graphs\ValueObjects\Edge;
use EugeneErg\Graphs\ValueObjects\Intersection;
use EugeneErg\Graphs\ValueObjects\StageKind;
use EugeneErg\Graphs\ValueObjects\TreeEdge;
use Exception;
use LogicException;

readonly class EdgeService
{
    public function __construct(
        private CanvasService $canvasService,
        private IntersectionService $intersectionService,
        private GraphService $graphService,
    ) {
    }

    /**
     * @param int[]|null $outerEdge
     * @throws Exception
     */
    public function splitOnTreeEdges(
        DirectionGraph $branch,
        SliceAggregate $slice,
        ?array $outerEdge = null,
        ?Trace $trace = null,
    ): TreeEdge {
        if (count($branch->getVertexes()) < 4) {
            return new TreeEdge(new Edge($branch->getVertexes()));
        }

        $hasOuter = $outerEdge !== null;
        $outerEdge = $outerEdge ?? [];
        /** @var int $edgeVertexesKey */
        $edgeVertexesKey = $slice->getKey($branch->getVertexes());
        $edgeVertexes = $hasOuter ? array_flip($outerEdge) : [$branch->getVertex($edgeVertexesKey) => 0];
        $outerVertexes = array_fill_keys($hasOuter ? $outerEdge : [$branch->getVertex($edgeVertexesKey)], true);
        $resultChildren = [];
        $first = ! $hasOuter;
        $needOuter = false;
        $finish = false;

        do {
            foreach ($edgeVertexes as $vertexA => $v) {
                unset($edgeVertexes[$vertexA]);

                foreach ($branch->getConnection($vertexA) ?? [] as $vertexB => $value) {
                    if (($value !== 1 || $needOuter) && ($value !== 2 || ! $needOuter)) {
                        continue;
                    }

                    if ($needOuter) {
                        $finish = true;
                    }

                    $path = $this->findShortEdge($branch, $vertexA, $vertexB, $first || $finish);

                    if ($path === null) {
                        throw new LogicException('Graph is not planar');
                    }

                    foreach ($path as $pos => $vertex) {
                        if (
                            $branch->hasValue($vertexA, $vertex)
                            && $branch->getValue($vertexA, $vertex) === 1
                            && $pos > 1
                        ) {
                            array_splice($path, $pos + 1);

                            break;
                        }
                    }

                    $innerVertexes = $this->getInnerVertexes($branch, $path, $outerVertexes, $slice, $trace);

                    if (
                        $first && ! $hasOuter
                        && count($innerVertexes) + count($path) === count($branch->getVertexes())
                    ) {
                        $innerVertexes = [];
                    }

                    $first = false;
                    $flipPath = array_flip($path);

                    $this->traceField($trace, $path, $needOuter && ! $hasOuter);

                    if (! $needOuter || $hasOuter) {
                        if ($innerVertexes === []) {
                            $newEdge = new Edge($path);
                            $resultChildren[] = new TreeEdge($newEdge);
                        } else {
                            /** @var DirectionGraph $graph */
                            $graph = $this->graphService->createSubGraph($branch, array_merge($path, $innerVertexes));
                            $graph->replaceConnection($this->pathToConnections($path));
                            $resultChildren[] = $this->splitOnTreeEdges($graph, $slice, $path, $trace);
                        }
                    } elseif ($outerEdge === []) {
                        $outerEdge = $path;
                        $hasOuter = true;
                    } else {
                        throw new LogicException('Is not planar graph');
                    }

                    foreach ($path as $vertex) {
                        $outerVertexes[$vertex] = true;
                    }

                    $branch->replaceConnection($this->pathToOuterConnection($path, $branch));
                    $branch->replaceConnection($this->disconnectVertexes($innerVertexes, $branch));
                    $edgeVertexes = array_replace($edgeVertexes, $flipPath);

                    continue 3;
                }
            }

            $edgeVertexes = array_flip($branch->vertexes);
            $needOuter = true;
        } while (! $finish);

        if ($outerEdge === []) {
            throw new LogicException('Is not planar graph');
        }

        return new TreeEdge(new Edge($outerEdge), $resultChildren);
    }

    /**
     * Каждое вырезанное поле — отдельный шаг: видно, как ветвь
     * распадается на грани.
     *
     * @param int[] $path
     */
    private function traceField(?Trace $trace, array $path, bool $isOuter): void
    {
        $trace?->add(
            StageKind::Field,
            $isOuter
                ? sprintf('Контур ветви: %s', implode(' - ', $path))
                : sprintf('Вырезаем поле: %s', implode(' - ', $path)),
            [],
            $path,
        );
    }

    /**
     * @return int[]|null
     */
    private function findShortEdge(DirectionGraph $graph, int $vertexA, int $vertexB, bool $first = false): ?array
    {
        if ($first) {
            $graph->unsetValue($vertexB, $vertexA, true);
        } else {
            /** @var int $value */
            foreach ($graph->getConnection($vertexA) ?? [] as $vertex => $value) {
                if ($value === 1) {
                    $graph->unsetValue($vertex, $vertexA, true);
                }
            }
        }

        $result = $this->findShortPath($graph, $vertexA, $vertexB);

        /** @var int $value */
        foreach ($graph->getConnection($vertexA) ?? [] as $vertex => $value) {
            $graph->setValue($vertex, $vertexA, $value, true);
        }

        return $result;
    }

    /**
     * @return int[]|null
     */
    private function findShortPath(DirectionGraph $graph, int $vertexA, int $vertexB): ?array
    {
        $steps = [[$vertexB => null]];
        $values = [];
        $canvas = new Canvas($graph);

        for ($step = 0; $step < count($steps); $step++) {
            foreach ($steps[$step] as $currentVertex => $prevVertex) {
                /** @var bool $currentValue */
                $currentValue = ! empty($values[$currentVertex]);
                unset($values[$currentVertex]);

                if ($graph->hasValue($currentVertex, $vertexA)) {
                    $this->canvasService->setPixels($canvas, [$vertexA], 1);
                    $steps[$step + 1][$vertexA] = $currentVertex;

                    break 2;
                }

                foreach ($graph->getConnection($currentVertex) ?? [] as $nextVertex => $value) {
                    if (
                        $canvas->isPixel($nextVertex, 0)
                        && (
                            (! $currentValue && $value !== 3)
                            || ($currentValue && $value === 2)
                        )
                    ) {
                        $this->canvasService->setPixels($canvas, [$vertexA], 1);
                        $steps[$step + 1][$nextVertex] = $currentVertex;

                        if ($currentValue) {
                            $values[$currentVertex] = true;
                        }
                    }
                }
            }
        }

        if ($canvas->isPixel($vertexA, 0)) {
            return null;
        }

        $currentVertex = $vertexA;
        $result = [$currentVertex];

        for ($step = count($steps) - 1; $step > 0; $step--) {
            $currentVertex = $steps[$step][$currentVertex];
            if ($currentVertex !== null) {
                $result[] = $currentVertex;
            }
        }

        return $result;
    }

    /**
     * @param int[] $path
     * @param array<int, bool> $outerVertexes
     * @return int[]
     * @throws Exception
     */
    private function getInnerVertexes(
        DirectionGraph $branch,
        array $path,
        array $outerVertexes,
        SliceAggregate $slice,
        ?Trace $trace = null,
    ): array {
        $innerIntersections = $this->intersectionService->getInnerIntersections($branch, $path, $outerVertexes, $slice, $trace);
        $innerVertexes = array_map(fn (Intersection $intersection) => $intersection->vertexes, $innerIntersections);

        return array_merge(...$innerVertexes);
    }

    /**
     * @param int[] $path
     * @return int[][]
     */
    private function pathToConnections(array $path, int $value = 2): array
    {
        $lastKey = array_key_last($path);
        $vertexB = $path[$lastKey];
        $result = [];

        foreach ($path as $vertexA) {
            $result[$vertexA][$vertexB] = $value;
            $vertexB = $vertexA;
        }

        return $result;
    }

    /**
     * @param int[] $path
     * @return array<int|null>[]
     */
    private function pathToOuterConnection(array $path, DirectionGraph $branch): array
    {
        $lastKey = array_key_last($path);
        $vertexB = $path[$lastKey];
        $result = [];

        foreach ($path as $vertexA) {
            $value = $branch->getValue($vertexA, $vertexB);
            $result[$vertexA][$vertexB] = $value === 2 ? null : $value + 1;
            $vertexB = $vertexA;
        }

        return $result;
    }

    /**
     * @param int[] $vertexes
     * @return null[][]
     */
    private function disconnectVertexes(array $vertexes, DirectionGraph $branch): array
    {
        $result = [];

        foreach ($vertexes as $vertexA) {
            foreach ($branch->getConnection($vertexA) ?? [] as $vertexB => $value) {
                $result[$vertexA][$vertexB] = null;
            }
        }

        return $result;
    }

    /**
     * @return int[]
     */
    public function getPartEdge(Edge $edge, int $offset, ?int $count = null): array
    {
        $result = [];
        $vertexCount = count($edge->vertexes);
        $count ??= $vertexCount;

        if ($count < 0) {
            $offset += $vertexCount;

            for ($pos = 0; $pos > $count; $pos--) {
                $result[] = $edge->vertexes[($pos + $offset) % $vertexCount];
            }
        } else {
            for ($pos = 0; $pos < $count; $pos++) {
                $result[] = $edge->vertexes[($pos + $offset) % $vertexCount];
            }
        }

        return $result;
    }
}
