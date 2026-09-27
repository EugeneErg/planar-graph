<?php

namespace EugeneErg\Graphs\Services;

use Closure;
use EugeneErg\Graphs\Aggregates\Trace;
use EugeneErg\Graphs\ValueObjects\Arc;
use EugeneErg\Graphs\ValueObjects\Edge;
use EugeneErg\Graphs\ValueObjects\GravityVertexes;
use EugeneErg\Graphs\ValueObjects\Replacement;
use EugeneErg\Graphs\ValueObjects\Solution;
use EugeneErg\Graphs\ValueObjects\SolutionType;
use EugeneErg\Graphs\ValueObjects\StageKind;
use EugeneErg\Graphs\ValueObjects\SubGraph;
use EugeneErg\Graphs\ValueObjects\Trouble;
use EugeneErg\Graphs\ValueObjects\TroubleTree;
use LogicException;

final readonly class ArcService
{
    /**
     * @param Edge[] $edges
     * @param Edge $outerEdge
     * @return Arc[]
     */
    public function createArcs(array $edges, Edge $outerEdge, ?Trace $trace = null): array
    {
        //var_dump($outerEdge, $edges);die;
        /** @var Arc[] $arcs */
        $arcs = [];
        /** @var Trouble[] $troubleVertexes */
        $troubleVertexes = [];
        /** @var Trouble[][] $troubles */
        $troubles = [];
        $graphs = [new SubGraph($outerEdge, $edges)];

        while ($graphs !== []) {
            $graph = array_shift($graphs);

            do {
                $found = 0;
                /** @var Edge[] $nextEdges */
                $nextEdges = [];

                while ($graph->edges !== []) {
                    $edge = array_shift($graph->edges);
                    $replacement = $this->getReplacement($graph->counter, $edge);

                    if ($replacement === null) {
                        $nextEdges[] = $edge;

                        continue;
                    }

                    $found++;
                    $replaced = $graph->counter->getVertexes($replacement->start, $replacement->length);
                    $fromVertex = $replacement->firstVertex;
                    $toVertex = $replacement->lastVertex;
                    $prevCounter = $graph->counter;
                    $graph->counter = $graph->counter->replace(
                        $replacement->vertexes,
                        $replacement->start,
                        $replacement->length
                    );

                    if ($troubles !== []) {
                        /** @var Trouble[] $selectTroubles */
                        $selectTroubles = [];

                        foreach ($replaced as $vertex) {
                            if (isset($troubleVertexes[$vertex])) {
                                $selectTroubles[$troubleVertexes[$vertex]->fromVertex] = $troubleVertexes[$vertex];
                            }
                        }

                        /** @var Solution[][] $decisions */
                        $decisions = [];

                        foreach ($selectTroubles as $trouble) {
                            $decisionObject = new Solution($trouble, $fromVertex, $toVertex);
                            $decisions[$decisionObject->type->value][] = $decisionObject;
                        }

                        if ($this->isCircle($decisions)) {
                            $graph->counter = $prevCounter;
                            $found--;
                            $nextEdges[] = $edge;

                            continue;
                        }

                        if (isset($decisions[SolutionType::Embedding->value])) {
                            $trouble = $decisions[SolutionType::Embedding->value][0]->trouble;

                            for ($i = 1; $i < count($replaced) - 1; $i++) {
                                unset($troubleVertexes[$replaced[$i]]);
                            }

                            for ($i = 1; $i < count($replacement->vertexes) - 1; $i++) {
                                $troubleVertexes[$replacement->vertexes[$i]] = $trouble;
                            }

                            $this->embedded($trouble, $edge, $replacement);
                            $this->tracePostpone($trace, $edge, $trouble);

                            continue;
                        }

                        if (isset($decisions[SolutionType::Absorption->value])) {
                            foreach ($decisions[SolutionType::Absorption->value] as $decision) {
                                foreach ($decision->trouble->vertexes as $vertex) {
                                    unset($troubleVertexes[$vertex]);
                                }

                                unset($troubles[$decision->trouble->fromVertex][$decision->trouble->toVertex]);

                                if ($troubles[$decision->trouble->fromVertex] === []) {
                                    unset($troubles[$decision->trouble->fromVertex]);
                                }
                            }

                            $before = count($arcs);
                            $graphs = array_merge($graphs, $this->applySolution(
                                $decisions[SolutionType::Absorption->value],
                                $replacement->vertexes,
                                $arcs,
                                $graph
                            ));
                            $this->traceArcs(
                                $trace,
                                StageKind::Absorb,
                                [$edge, ...array_merge(...array_map(
                                    static fn (Solution $solution): array => $solution->trouble->edges,
                                    $decisions[SolutionType::Absorption->value],
                                ))],
                                $before,
                                $arcs,
                            );

                            continue;
                        }
                    }

                    //var_dump($replacement);die;

                    if ($replacement->length === 2 || isset($troubles[$fromVertex][$toVertex])) {
                        if (isset($troubles[$fromVertex][$toVertex])) {
                            for ($i = 1; $i < count($replaced) - 1; $i++) {
                                unset($troubleVertexes[$replaced[$i]]);
                            }
                        } else {
                            $troubles[$fromVertex][$toVertex] = new Trouble(
                                $replaced,
                                $fromVertex,
                                $toVertex
                            );
                        }

                        $this->embedded($troubles[$fromVertex][$toVertex], $edge, $replacement);
                        $this->tracePostpone($trace, $edge, $troubles[$fromVertex][$toVertex]);

                        for ($i = 1; $i < count($replacement->vertexes) - 1; $i++) {
                            $troubleVertexes[$replacement->vertexes[$i]] = $troubles[$fromVertex][$toVertex];
                        }
                    } else {
                        //var_dump('p1', $replacement->vertexes);die;

                        $arcs[] = new Arc(
                            new GravityVertexes(...array_slice($replaced, count($replaced) >> 1, 1)),
                            [$replacement->vertexes],
                        );
                        $this->traceArcs($trace, StageKind::Arc, [$edge], count($arcs) - 1, $arcs);
                    }
                }

                if ($found === 0) {
                    /** @var Trouble[] $decisions */
                    $decisions = [];

                    foreach ($graph->counter->vertexes as $vertex) {
                        if (
                            isset($troubleVertexes[$vertex])
                            && ! isset($decisions[$troubleVertexes[$vertex]->fromVertex])
                        ) {
                            $trouble = $troubleVertexes[$vertex];
                            $decisions[$trouble->fromVertex] = $trouble;
                            unset($troubles[$trouble->fromVertex][$trouble->toVertex]);

                            if ($troubles[$trouble->fromVertex] === []) {
                                unset($troubles[$trouble->fromVertex]);
                            }
                        }
                    }

                    if ($decisions !== []) {
                        $mainGravityVertexes = [];

                        foreach ($graph->counter->vertexes as $vertex) {
                            if (isset($troubleVertexes[$vertex])) {
                                unset($troubleVertexes[$vertex]);
                            } else {
                                $mainGravityVertexes[] = $vertex;
                            }
                        }

                        $before = count($arcs);
                        $graphs = array_merge($graphs, $this->solutionTroubles(
                            $decisions,
                            $arcs,
                            $mainGravityVertexes
                        ));
                        $this->traceArcs(
                            $trace,
                            StageKind::Merge,
                            array_merge(...array_map(static fn (Trouble $trouble): array => $trouble->edges, array_values($decisions))),
                            $before,
                            $arcs,
                        );
                    } elseif (count($nextEdges) > 2) {
                        throw new LogicException();
                    }
                }

                $graph->edges = array_merge($graph->edges, $nextEdges);
            } while ($found !== 0);
        }

        $trace?->add(
            StageKind::Order,
            sprintf('Порядок полей определён: дуг — %d', count($arcs)),
        );

        return $arcs;
    }

    /**
     * Журнал: поле отложено над ребром границы. Логики не меняет.
     */
    private function tracePostpone(?Trace $trace, Edge $face, Trouble $trouble): void
    {
        $trace?->add(
            StageKind::Postpone,
            sprintf('Откладываем поле %s над ребром %d-%d', implode(' - ', $face->vertexes), $trouble->fromVertex, $trouble->toVertex),
            [$face->vertexes],
            [$trouble->fromVertex, $trouble->toVertex],
        );
    }

    /**
     * Журнал: какие поля легли какими дугами. Логики не меняет.
     *
     * @param Edge[] $faces
     * @param Arc[] $arcs
     */
    private function traceArcs(?Trace $trace, StageKind $kind, array $faces, int $from, array $arcs): void
    {
        if ($trace === null) {
            return;
        }

        $numbers = range($from, count($arcs) - 1);
        $trace->add(
            $kind,
            sprintf('Дуги %s: %s', implode(', ', $numbers), implode(' + ', array_map(
                static fn (Edge $face): string => implode(' - ', $face->vertexes),
                $faces,
            ))),
            array_map(static fn (Edge $face): array => $face->vertexes, $faces),
            $numbers,
            [],
            array_map(static fn (int $number): array => array_merge(...$arcs[$number]->vertexes), $numbers),
        );
    }

    private function getReplacement(Edge $edgeA, Edge $edgeB): ?Replacement
    {
        $intersectA = array_intersect($edgeA->vertexes, $edgeB->vertexes);
        $intersectCount = count($intersectA);

        if ($intersectCount < 2) {
            return null;
        }

        $edgeCountA = count($edgeA->vertexes);
        $edgeCountB = count($edgeB->vertexes);

        if ($edgeCountA === $intersectCount && $edgeCountB === $intersectCount) {
            return null;
        }

        if ($edgeCountA === $intersectCount) {
            $intersectB = array_intersect($edgeB->vertexes, $intersectA);
            $shiftsAndDirection = $this->getShiftsAndDirection($intersectB, $edgeCountB, $edgeA, $edgeB);

            if ($shiftsAndDirection === null) {
                return null;
            }

            ['shiftA' => $shiftA, 'shiftB' => $shiftB, 'isRightDirection' => $isRightDirection] = $shiftsAndDirection;

            if (! $isRightDirection) {
                $shiftA++;
                $shiftB = $edgeB->findVertexPosition($edgeA->getVertex($shiftA));

                if ($shiftB === null) {
                    return null;
                }
            }
        } else {
            $shiftsAndDirection = $this->getShiftsAndDirection($intersectA, $edgeCountA, $edgeB, $edgeA);

            if ($shiftsAndDirection === null) {
                return null;
            }

            ['shiftB' => $shiftA, 'shiftA' => $shiftB, 'isRightDirection' => $isRightDirection] = $shiftsAndDirection;
        }

        for ($i = 0; $i < $intersectCount; $i++) {
            $vertex = $edgeA->getVertex($shiftA + $i);

            if ($vertex !== $edgeB->getVertex($shiftB + ($isRightDirection ? $i : -$i))) {
                return null;
            }
        }

        return new Replacement(
            $edgeB->getVertexes(
                $shiftB,
                $isRightDirection ? $intersectCount - $edgeCountB - 2 : $edgeCountB - $intersectCount + 2
            ),
            $shiftA,
            $intersectCount
        );
    }

    /**
     * @param int[] $intersect
     * @return array{shiftA: int, shiftB: int, isRightDirection: bool}|null
     */
    private function getShiftsAndDirection(array $intersect, int $edgeCount, Edge $edgeA, Edge $edgeB): ?array
    {
        $shiftB = $this->getShift($intersect, $edgeCount);

        if ($shiftB === null) {
            return null;
        }

        $shiftA = $edgeA->findVertexPosition($edgeB->vertexes[$shiftB]);

        if ($shiftA === null) {
            return null;
        }

        $isRightDirection = $edgeA->getVertex($shiftA + 1) === $edgeB->getVertex($shiftB + 1);

        return ['shiftA' => $shiftA, 'shiftB' => $shiftB, 'isRightDirection' => $isRightDirection];
    }

    /**
     * @param int[] $vertexes
     */
    private function getShift(array $vertexes, int $maxCount): ?int
    {
        $shift = isset($vertexes[0]) && ! isset($vertexes[$maxCount - 1]);
        $prevKey = 0;
        $result = array_key_first($vertexes);

        foreach ($vertexes as $key => $value) {
            if ($key !== $prevKey) {
                $result = $key;

                if ($shift) {
                    return null;
                }

                $shift = true;
            }

            $prevKey = $key + 1;
        }

        /** @var int|null $result */
        return $result;
    }

    /**
     * @param Solution[][] $decisions
     */
    private function isCircle(array $decisions): bool
    {
        if ($decisions === []) {
            return false;
        }

        if (isset($decisions[SolutionType::Circle->value])) {
            return true;
        }

        $from = null;
        $to = null;

        foreach ($decisions[SolutionType::Absorption->value] ?? [] as $decision) {
            if ($decision->fromPosition !== null) {
                $from = $decision;
            } elseif ($decision->toPosition !== null) {
                $to = $decision;
            }
        }

        return $from !== null && $to !== null && $from->trouble->fromVertex === $to->trouble->toVertex;
    }

    public function embedded(Trouble $trouble, Edge $edge, Replacement $replacement): void
    {
        $trouble->edges[] = $edge;

        if ($replacement->firstVertex === $trouble->fromVertex && $replacement->lastVertex === $trouble->toVertex) {
            $trouble->firstVertexes = $trouble->vertexes;
            $trouble->vertexes = $replacement->vertexes;
            $tree = new TroubleTree($edge, $replacement->vertexes);
            $trouble->trees = array_fill_keys($replacement->vertexes, $tree);
            $trouble->mainTree = $tree;
        } else {
            $fromPosition = $trouble->getVertexPosition($replacement->firstVertex);
            array_splice($trouble->vertexes, $fromPosition, $replacement->length, $replacement->vertexes);

            if (! isset($trouble->trees[$replacement->firstVertex], $trouble->trees[$replacement->lastVertex])) {
                throw new LogicException();
            }

            $parents = $this->getParentTrees($trouble, $replacement->firstVertex, $replacement->lastVertex);

            foreach ($parents as $parent) {
                /** @var TroubleTree $troubleTree */
                $troubleTree = $parent->rightChild;
                $troubleTree->removeLeftParent($parent);
            }

            $tree = new TroubleTree(
                $edge,
                $replacement->vertexes,
                $this->getParentEdges($parents),
                $trouble->trees[$replacement->firstVertex],
                $trouble->trees[$replacement->lastVertex],
            );

            for ($i = 1; $i < count($replacement->vertexes) - 1; $i++) {
                $trouble->trees[$replacement->vertexes[$i]] = $tree;
                //V 0 throw new \Exception('new test keys!');
            }
        }
    }

    /**
     * @return TroubleTree[]
     */
    private function getParentTrees(Trouble $trouble, int $leftVertex, int $rightVertex): array
    {
        /** @var TroubleTree[][] $parents */
        $parents = [];
        $this->mapTree(
            $trouble,
            $leftVertex,
            $rightVertex,
            static function (
                array $vertexes,
                TroubleTree $tree,
                ?bool $onRight,
                ?TroubleTree $leftParentTree = null
            ) use ($leftVertex, &$parents): void {
                $parentVertex = $leftParentTree === null ? null
                    : $leftParentTree->vertexes[count($leftParentTree->vertexes) - 1];
                /** @var int|null $parentPosition */
                $parentPosition = $leftParentTree === null
                    ? null
                    : array_search($leftParentTree, $tree->leftParents[$parentVertex], true);

                foreach ($vertexes as $vertex) {
                    if ($vertex === $leftVertex) {
                        continue;
                    }

                    if ($vertex === $parentVertex) {
                        //V 4 throw new \Exception('new test keys!');
                        $parents[] = array_slice($tree->leftParents[$vertex], $parentPosition + 1);
                    } elseif (isset($tree->leftParents[$vertex])) {
                        //V 4 throw new \Exception('new test keys!');
                        $parents[] = $tree->leftParents[$vertex];
                    }
                }
            }
        );

        return array_merge(...$parents);
    }

    private function mapTree(Trouble $trouble, int $leftVertex, int $rightVertex, Closure $closure): void
    {
        $leftTree = $trouble->trees[$leftVertex];
        $rightTree = $trouble->trees[$rightVertex];
        $parentLeftTree = null;

        while ($leftTree !== $rightTree) {
            //V 0 throw new \Exception('new test keys!');
            if ($leftTree->level > $rightTree->level) {
                //V 4 throw new \Exception('new test keys!');
                $pos = $leftTree->getVertexPosition($leftVertex);
                $closure(array_slice($leftTree->vertexes, $pos, -1), $leftTree, false, $parentLeftTree);
                $leftVertex = $leftTree->vertexes[count($leftTree->vertexes) - 1];
                $parentLeftTree = $leftTree;
                /** @var TroubleTree $leftTree */
                $leftTree = $leftTree->rightChild;
            } else {
                $pos = $rightTree->getVertexPosition($rightVertex);
                $closure(array_slice($rightTree->vertexes, 1, $pos), $rightTree, true, null);
                $rightVertex = $rightTree->vertexes[0];
                /** @var TroubleTree $rightTree */
                $rightTree = $rightTree->leftChild;
            }
        }

        /** @var int $leftPos */
        $leftPos = $leftTree->getVertexPosition($leftVertex);
        /** @var int $rightPos */
        $rightPos = $rightTree->getVertexPosition($rightVertex);
        $closure(
            array_slice($leftTree->vertexes, $leftPos, $rightPos - $leftPos + 1),
            $rightTree,
            null,
            $parentLeftTree
        );
    }

    /**
     * @param TroubleTree[] $parents
     * @return Edge[]
     */
    private function getParentEdges(array $parents): array
    {
        $result = [];

        while ($parent = array_shift($parents)) {
            //V 4 throw new \Exception('new test keys!');
            $result[] = $parent->edges;
            $result[][] = $parent->edge;

            if ($parent->leftParents !== []) {
                array_push($parents, ...array_merge(...$parent->leftParents));
            }
        }

        $result = array_merge(...$result);

        if (count(array_unique($result, SORT_REGULAR)) !== count($result)) {
            throw new LogicException();
        }

        return $result;
    }

    /**
     * @param Solution[] $solutions
     * @param int[] $vertexes
     * @param Arc[] $arcs
     * @return SubGraph[]
     */
    private function applySolution(array $solutions, array $vertexes, array &$arcs, SubGraph $subGraph): array
    {
        //var_dump($solutions, $vertexes, $arcs, $subGraph);die();

        $mainVertexes = [$vertexes];
        /** @var int[] $mainGravityVertexes */
        $mainGravityVertexes = [];
        /** @var SubGraph[] $graphs */
        $graphs = [];
        $newArcs = [&$mainVertexes];

        foreach ($solutions as $solution) {
            $mainGravityVertexes[$solution->trouble->fromVertex] = $solution->trouble->fromVertex;
            $mainGravityVertexes[$solution->trouble->toVertex] = $solution->trouble->toVertex;

            if ($solution->fromPosition !== null && $solution->fromVertex !== $solution->trouble->fromVertex) {
                $tree = $solution->trouble->trees[$solution->fromVertex];
                $leftPart = $tree->findPath($solution->fromVertex, false);
                $innerEdges = $this->getInnerEdges($solution->trouble, $solution->trouble->fromVertex, $solution->fromVertex);
                $subGraph->edges = array_merge($subGraph->edges, $innerEdges);
                $pos = $subGraph->counter->getVertexPosition($solution->trouble->fromVertex);
                $pos2 = $subGraph->counter->getVertexPosition($solution->fromVertex);
                $length = $subGraph->counter->getNormalVertexNumber($pos2 - $pos);
                $subGraph->counter = $subGraph->counter->replace($leftPart, $pos, $length);
                $pos = $solution->trouble->getVertexPosition($solution->fromVertex);
                $leftArc = array_slice($solution->trouble->vertexes, $pos);
                $newArcs[] = [&$leftArc];
                $leftPart1 = $leftPart;
                $leftPart1[] = array_shift($mainVertexes[0]);
                $mainVertexes = array_merge([$leftPart1], $mainVertexes);
                $innerEdges = $this->excludeEdges($solution->trouble->edges, $innerEdges);
                $graphs[] = new SubGraph(new Edge(array_merge($leftPart, $leftArc)), $innerEdges);
            } elseif ($solution->toPosition !== null && $solution->toVertex !== $solution->trouble->toVertex) {
                $tree = $solution->trouble->trees[$solution->toVertex];
                $rightPath = $tree->findPath($solution->toVertex, true);
                $innerEdges = $this->getInnerEdges($solution->trouble, $solution->toVertex, $solution->trouble->toVertex);
                $subGraph->edges = array_merge($subGraph->edges, $innerEdges);
                $pos = $subGraph->counter->getVertexPosition($solution->toVertex);
                $pos2 = $subGraph->counter->getVertexPosition($solution->trouble->toVertex);
                $length = $subGraph->counter->getNormalVertexNumber($pos2 - $pos);
                $subGraph->counter = $subGraph->counter->replace($rightPath, $pos + 1, $length);
                $pos = $solution->trouble->getVertexPosition($solution->toVertex);
                $rightArc = array_slice($solution->trouble->vertexes, 0, $pos + 1);
                $newArcs[] = [&$rightArc];
                $rightPart1 = $rightPath;
                array_unshift($rightPart1, array_pop($mainVertexes[count($mainVertexes) - 1]));
                $mainVertexes = array_merge($mainVertexes, [$rightPart1]);
                $innerEdges = $this->excludeEdges($solution->trouble->edges, $innerEdges);
                $graphs[] = new SubGraph(new Edge(array_merge($rightArc, $rightPath)), $innerEdges);
            } else {
                $newArcs[] = [$solution->trouble->vertexes];
                $graphs[] = new SubGraph(new Edge($solution->trouble->vertexes), $solution->trouble->edges);
            }
        }

        $mainGravityVertexes[$mainVertexes[0][0]] = $mainVertexes[0][0];
        /** @var int $lastKey */
        $lastKey = array_key_last($mainVertexes);
        $subMainVertexes = $mainVertexes[$lastKey];
        /** @var int $lastKey */
        $lastKey = array_key_last($subMainVertexes);
        /** @var int $last */
        $last = $subMainVertexes[$lastKey];
        /** @var int[] $mainGravityVertexes */
        $mainGravityVertexes[$last] = $last;

        foreach ($newArcs as $arc) {
            //var_dump('p2', array_filter($arc, fn (array $subArc) => $subArc !== []));die;
            $arcs[] = new Arc(
                new GravityVertexes(...$mainGravityVertexes),
                array_values(array_filter($arc, fn (array $subArc) => $subArc !== [])),
            );
        }

        return $graphs;
    }

    /**
     * @return Edge[]
     */
    public function getInnerEdges(Trouble $trouble, int $leftVertex, int $rightVertex): array
    {
        return $this->getParentEdges($this->getParentTrees($trouble, $leftVertex, $rightVertex));
    }

    /**
     * Рёбра — объекты, поэтому вычитаем их по тождеству экземпляров:
     * array_diff приводит элементы к строке и падает на объектах.
     *
     * @param Edge[] $edges
     * @param Edge[] $excluded
     *
     * @return Edge[]
     */
    private function excludeEdges(array $edges, array $excluded): array
    {
        return array_values(array_filter(
            $edges,
            static fn (Edge $edge): bool => !in_array($edge, $excluded, true),
        ));
    }

    /**
     * @param Trouble[] $troubles
     * @param Arc[] $arcs
     * @param int[] $mainGravityVertexes
     * @return SubGraph[]
     */
    private function solutionTroubles(
        array $troubles,
        array &$arcs,
        array $mainGravityVertexes
    ): array {
        $result = [];

        foreach ($troubles as $trouble) {
            //var_dump('p2', $trouble->vertexes);die;
            $arcs[] = new Arc(new GravityVertexes(...$mainGravityVertexes), [$trouble->vertexes]);
            $result[] = new SubGraph(new Edge($trouble->vertexes), $trouble->edges);
        }

        return $result;
    }
}
