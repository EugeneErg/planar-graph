<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\Aggregates\ArticulationVertexesAggregate;
use EugeneErg\Graphs\Aggregates\Canvas;
use EugeneErg\Graphs\Aggregates\Trace;
use EugeneErg\Graphs\ValueObjects\DirectionGraph;
use EugeneErg\Graphs\ValueObjects\StageKind;
use EugeneErg\Graphs\ValueObjects\Tree;

readonly class TreeService
{
    public function __construct(
        private CanvasService $canvasService,
        private GraphService $graphService,
    ) {
    }

    public function fromConnectionGraph(ArticulationVertexesAggregate $articulationVertexesAggregate, ?Trace $trace = null): Tree
    {
        $directionGraph = $this->graphService->graphToDirection($articulationVertexesAggregate->graph);

        if ($articulationVertexesAggregate->articulationVertexes === []) {
            $trace?->add(StageKind::ArticulationVertexes, 'Точек сочленения нет: кусок двусвязный целиком');

            return new Tree(
                $articulationVertexesAggregate->graph,
                [$directionGraph],
                new DirectionGraph([], []),
            );
        }

        $articulationVertexes = $articulationVertexesAggregate->articulationVertexes;
        $trace?->add(
            StageKind::ArticulationVertexes,
            sprintf('Точки сочленения: %s — по ним и режем', implode(', ', $articulationVertexes)),
            [],
            $articulationVertexes,
        );
        $result = [];
        $this->split($articulationVertexes, new Canvas($articulationVertexesAggregate->graph), $result, trace: $trace);
        /** @var DirectionGraph[] $branches */
        $branches = array_map(
            fn (array $vertexes) => $this->graphService->createSubGraph($directionGraph, $vertexes),
            $result,
        );
        $connections = [];
        $graphVertexes = [];

        /** @var int $branchNumber */
        foreach ($result as $branchNumber => $vertexes) {
            $graphVertexes[] = $branchNumber;

            foreach ($vertexes as $vertex) {
                $connections[$vertex][] = $branchNumber;
            }
        }

        $matrix = [];

        /** @var int $vertex */
        foreach ($connections as $vertex => $subBranches) {
            foreach ($subBranches as $branchA) {
                foreach ($subBranches as $branchB) {
                    if ($branchA !== $branchB) {
                        $matrix[$branchA][$branchB] = $vertex;
                    }
                }
            }
        }

        $trace?->add(
            StageKind::Branches,
            sprintf('Двусвязных ветвей: %d — вырезаем их по точкам сочленения', count($result)),
            $result,
            $articulationVertexesAggregate->articulationVertexes,
        );

        return new Tree($articulationVertexesAggregate->graph, $branches, new DirectionGraph($matrix, $graphVertexes));
    }

    /**
     * @param int[] $articulationVertex
     * @param int[] $result
     */
    private function split(
        array &$articulationVertex,
        Canvas $canvas,
        array &$result,
        int $maxColor = 0,
        ?Trace $trace = null,
    ): bool {
        $color = $maxColor;
        $hasResult = false;

        foreach ($articulationVertex as $pos => $vertexA) {
            if (! $canvas->isPixel($vertexA, $maxColor)) {
                continue;
            }

            unset($articulationVertex[$pos]);

            foreach ($canvas->graph->getConnection($vertexA) ?? [] as $vertexB => $value) {
                if (! $canvas->isPixel($vertexB, $maxColor)) {
                    continue;
                }

                $hasResult = true;
                $this->canvasService->setPixels($canvas, [$vertexA], ++$color);
                // Точка сочленения перекрашена — значит, заперта: краска через
                // неё не пройдёт, и заливка из соседа накроет ровно одну ветвь.
                // Без этого кадра непонятно, почему заливка вдруг встала.
                $trace?->add(
                    StageKind::Block,
                    sprintf('Запираем точку сочленения %d', $vertexA),
                    [],
                    [$vertexA],
                );
                $vertexes = $this->canvasService->fill($canvas, $vertexB, $color, $trace);
                $vertexes[] = $vertexA;

                if (! $this->split($articulationVertex, $canvas, $result, $color, $trace)) {
                    $result[] = $vertexes;
                    $trace?->add(
                        StageKind::Branch,
                        sprintf('Ветвь на точке %d: %s', $vertexA, implode(' - ', $vertexes)),
                        [],
                        $vertexes,
                        [],
                        [[$vertexA]],
                    );
                }
            }
        }

        return $hasResult;
    }
}
