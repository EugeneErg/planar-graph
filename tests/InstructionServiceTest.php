<?php

declare(strict_types=1);

namespace Tests;

use EugeneErg\Graphs\Aggregates\SliceAggregate;
use EugeneErg\Graphs\Aggregates\Trace;
use EugeneErg\Graphs\Services\InstructionService;
use EugeneErg\Graphs\ValueObjects\Instruction\Contour;
use EugeneErg\Graphs\ValueObjects\Instruction\Cut;
use EugeneErg\Graphs\ValueObjects\Instruction\Instruction;
use EugeneErg\Graphs\ValueObjects\Instruction\Lock;
use EugeneErg\Graphs\ValueObjects\Instruction\Measure;
use EugeneErg\Graphs\ValueObjects\Instruction\Tie;
use EugeneErg\Graphs\ValueObjects\Instruction\Remain;
use EugeneErg\Graphs\ValueObjects\Instruction\Step;
use EugeneErg\Graphs\ValueObjects\StageKind;
use EugeneErg\Graphs\ValueObjects\ZeroSlice;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Инструкция: что и в каком порядке делают с графом.
 *
 * Проверяется двумя способами. Текст — это договор: его читает человек,
 * и он обязан совпадать с конспектом алгоритма. Факты разрезов проверяются
 * проигрыванием: каждый разрез обязан вынимать то, что есть, и раздваивать
 * то, что остаётся у обоих.
 */
final class InstructionServiceTest extends AbstractTestCase
{
    /**
     * Шесть несвязных кусков: треугольник, квадрат, цепочка, одинокое ребро,
     * пятиугольник с перемычкой и два треугольника на общей вершине.
     */
    public function testInstructionReadsLikeTheAlgorithm(): void
    {
        $connections = require __DIR__ . '/Cases/Graphs/ManyParts.php';

        self::assertSame(<<<'TEXT'
            граф  вершины: 0 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21
                  рёбра:   0-1 0-2 1-2 3-4 3-6 4-5 5-6 7-8 8-9 10-11 12-13 12-14 12-16 13-14 14-15 15-16 17-18 17-19 18-19 19-20 19-21 20-21

            найти куски
              залить от 0: 1 2
              отрезать: вынуть 0 1 2, рёбра 0-1 0-2 1-2 → арена A
              залить от 3: 4 6 → 5
              отрезать: вынуть 3 4 6 5, рёбра 3-4 3-6 4-5 5-6 → арена B
              залить от 7: 8 → 9
              отрезать: вынуть 7 8 9, рёбра 7-8 8-9 → арена C
              залить от 10: 11
              отрезать: вынуть 10 11, рёбра 10-11 → арена D
              залить от 12: 13 16 14 → 15
              отрезать: вынуть 12 13 16 14 15, рёбра 12-13 12-14 12-16 13-14 14-15 15-16 → арена E
              залить от 17: 18 19 → 20 21
              остаток 17 18 19 20 21 — арена F

            найти ветви
            ├ арена A
            │   точек сочленения нет — двусвязный целиком
            ├ арена B
            │   точек сочленения нет — двусвязный целиком
            ├ арена C
            │   запереть 8
            │   залить от 9: —
            │   отрезать: вынуть 9, рёбра 8-9; раздвоить 8 → арена C1
            │   остаток 7 8 — арена C
            ├ арена D
            │   точек сочленения нет — двусвязный целиком
            ├ арена E
            │   точек сочленения нет — двусвязный целиком
            └ арена F
                запереть 19
                залить от 20: 21
                отрезать: вынуть 20 21, рёбра 19-20 19-21 20-21; раздвоить 19 → арена F1
                остаток 17 18 19 — арена F

            найти поля
            ├ арена A
            │   меньше четырёх вершин — поле целиком
            ├ арена B
            │   запереть обход 3-6-5-4
            │   отрезать поле 3-6-5-4: раздвоить 3 6 5 4, рёбра 3-6 5-6 4-5 3-4 → арена B.1
            │   остался контур 3-6-5-4 — арена B
            ├ арена C1
            │   меньше четырёх вершин — поле целиком
            ├ арена C
            │   меньше четырёх вершин — поле целиком
            ├ арена D
            │   меньше четырёх вершин — поле целиком
            ├ арена E
            │   запереть обход 12-14-13
            │   залить от 16: 15
            │   стороны: внутри —; снаружи 16 15
            │   отрезать поле 12-14-13: раздвоить 12 14 13, рёбра 12-14 13-14 12-13 → арена E.1
            │   запереть обход 12-14-15-16
            │   залить от 13: —
            │   стороны: внутри —; снаружи 13
            │   отрезать поле 12-14-15-16: вынуть рёбра 12-14; раздвоить 12 14 15 16, рёбра 14-15 15-16 12-16 → арена E.2
            │   остался контур 12-16-15-14-13 — арена E
            ├ арена F1
            │   меньше четырёх вершин — поле целиком
            └ арена F
                меньше четырёх вершин — поле целиком

            склеить ветви
            ├ кусок C
            │   склеить в точке 8 поля арен C и C1
            │   раскрыть в круг 7-8-9-8
            │   отмерить от 8: 8-7 и 8-9
            │   связать 9-7
            │   разрезать по связке: 8-9-7 → арена C, 8-9-7 → арена C1
            └ кусок F
                раздвоить поле-цикл арены F → арена F'
                раздвоить поле-цикл арены F1 → арена F1'
                склеить в точке 19 поля арен F' и F1'
                раскрыть в круг 17-19-20-21-19-18
                отмерить от 19: 19-17 и 19-20
                связать 20-17
                разрезать по связке: 19-20-17 → арена F', 19-21-20-17-18 → арена F1'

            собрать
            ├ кусок A
            │   уложить поле 0-1-2 (арена A)
            │   выпрямить
            │   расслабить
            ├ кусок B
            │   уложить поле 3-6-5-4 (арена B.1)
            │   уложить поле 3-6-5-4 (арена B)
            │   выпрямить
            │   расслабить
            ├ кусок C
            │   уложить поле 8-9-7 (арена C1)
            │   уложить поле 8-9-7 (арена C)
            │   выпрямить
            │   расслабить
            ├ кусок D
            │   уложить поле 10-11 (арена D)
            │   выпрямить
            │   расслабить
            ├ кусок E
            │   уложить поле 12-16-15-14-13 (арена E)
            │   уложить поле 12-14-15-16 (арена E.2)
            │   уложить поле 12-14-13 (арена E.1)
            │   выпрямить
            │   расслабить
            └ кусок F
                уложить поле 19-21-20-17-18 (арена F1')
                уложить поле 17-18-19 (арена F)
                уложить поле 20-21-19 (арена F1)
                уложить поле 19-20-17 (арена F')
                выпрямить
                расслабить

            TEXT, $this->build($connections)->describe());
    }

    /**
     * Разрезы физически возможны: вынимают то, что есть, раздваивают то,
     * что остаётся у обоих, и в итоге остаются ровно те ветви, которые
     * нашёл алгоритм.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testCutsAreRealAndEndOnTheAlgorithmsBranches(array $connections): void
    {
        [$instruction, $trace] = $this->buildWithTrace($connections);
        $pieces = [];

        foreach ($this->getAllSteps($instruction) as $step) {
            $action = $step->action;

            // Здесь только куски и ветви: разрезы полей проверяются отдельно.
            if ($action instanceof Cut && $action->walk === null) {
                $whole = array_merge($action->vertexes, $action->twinVertexes);
                $host = $this->findHost($pieces, $whole);

                if ($host === null) {
                    // Первый этап режет исходный граф: куска-хозяина ещё нет.
                    $pieces[$action->arena] = $this->sorted($action->vertexes);

                    continue;
                }

                self::assertSame(
                    [],
                    array_values(array_diff($action->twinVertexes, $pieces[$host])),
                    'Раздваивают то, чего у куска нет: ' . $action->describe(),
                );
                $pieces[$host] = $this->sorted(array_diff($pieces[$host], $action->vertexes));
                $pieces[$action->arena] = $this->sorted($whole);
            }

            if ($action instanceof Remain) {
                $pieces[$action->arena] = $this->sorted($action->vertexes);
            }
        }

        self::assertNotSame([], $pieces, 'Разрезов не нашлось вовсе.');

        $found = [];

        foreach ($trace->getStages() as $stage) {
            if ($stage->kind === StageKind::Branches) {
                foreach ($stage->groups as $branch) {
                    $found[] = $this->sorted($branch);
                }
            }
        }

        foreach ($found as $branch) {
            self::assertContains($branch, $pieces, 'Ветвь алгоритма потерялась: ' . implode(' ', $branch));
        }
    }

    /**
     * Куски уходят без раздвоений — им нечего делить. Ветвь раздваивает
     * ровно одну вершину: ту точку сочленения, которую перед этим заперли.
     * Поля — отдельный разговор, у них своя проверка.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testOnlyTheLockedVertexIsTwinned(array $connections): void
    {
        $locked = null;
        // Связный граф без точек сочленения не режется вовсе.
        $this->addToAssertionCount(1);

        foreach ($this->getAllSteps($this->build($connections)) as $step) {
            if ($step->action instanceof Lock) {
                $locked = $step->action->vertexes;
            }

            // Поля раздваивают по-своему — всё, что нужно ещё какому-то полю.
            if (! $step->action instanceof Cut || $step->action->walk !== null) {
                continue;
            }

            self::assertSame([], $step->action->twinEdges, 'Куски и ветви рёбер не раздваивают.');
            self::assertSame(
                $locked ?? [],
                $step->action->twinVertexes,
                'Раздвоилось не то, что заперли: ' . $step->action->describe(),
            );
            $locked = null;
        }
    }

    /**
     * Разрез поля делит обход без остатка: каждая его вершина и каждое
     * ребро либо вынимается, либо раздваивается.
     *
     * И за всю ветвь каждое ребро вынимается ровно один раз — последним
     * полем, которому оно было нужно, — либо остаётся в контуре. Иначе
     * ребро пропало бы или раздвоилось лишний раз.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testFieldCutsShareEdgesHonestly(array $connections): void
    {
        $removed = [];
        $contours = [];
        // У графа из одних треугольников полей нет: все ветви меньше четырёх
        // вершин. Тогда проверять нечего — и это тоже результат.
        $this->addToAssertionCount(1);

        foreach ($this->getAllSteps($this->build($connections)) as $step) {
            $action = $step->action;

            if ($action instanceof Contour) {
                foreach ($this->walkEdges($action->walk) as $edge) {
                    $contours[$action->arena][$edge] = true;
                }

                continue;
            }

            if (! $action instanceof Cut || $action->walk === null) {
                continue;
            }

            $vertexes = array_merge($action->vertexes, $action->twinVertexes);
            sort($vertexes);
            $walk = array_values(array_unique($action->walk));
            sort($walk);
            self::assertSame($walk, $vertexes, 'Вершины разреза не совпадают с обходом: ' . $action->describe());

            $edges = array_map(
                static fn (array $edge): string => $edge[0] . '-' . $edge[1],
                array_merge($action->edges, $action->twinEdges),
            );
            sort($edges);
            $expected = $this->walkEdges($action->walk);
            sort($expected);
            self::assertSame($expected, $edges, 'Рёбра разреза не совпадают с обходом: ' . $action->describe());

            $branch = substr($action->arena, 0, (int) strrpos($action->arena, '.'));

            foreach ($action->edges as $edge) {
                $name = $edge[0] . '-' . $edge[1];
                self::assertArrayNotHasKey($name, $removed[$branch] ?? [], 'Ребро вынули дважды: ' . $name);
                $removed[$branch][$name] = true;
            }
        }

        foreach ($removed as $branch => $edges) {
            self::assertSame(
                [],
                array_keys(array_intersect_key($edges, $contours[$branch] ?? [])),
                'Ребро вынули, а оно осталось в контуре ветви ' . $branch,
            );
        }
    }

    /**
     * У связки видна причина: её концы — ровно там, где остановился отмер
     * от точки склейки по обоим полям.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testTieEndsWhereMeasureStopped(array $connections): void
    {
        $steps = $this->getAllSteps($this->build($connections));
        $this->addToAssertionCount(1);

        foreach ($steps as $number => $step) {
            if (! $step->action instanceof Measure) {
                continue;
            }

            $first = $step->action->first;
            $second = $step->action->second;

            $tie = $steps[$number + 1]->action ?? null;
            self::assertInstanceOf(Tie::class, $tie);
            $ends = [$first[count($first) - 1], $second[count($second) - 1]];
            sort($ends);
            $tied = [$tie->vertexA, $tie->vertexB];
            sort($tied);
            self::assertSame($ends, $tied);
            self::assertSame($step->action->vertex, $step->action->first[0]);
            self::assertSame($step->action->vertex, $step->action->second[0]);
        }
    }

    /**
     * @return array<string, array{true[][]}>
     */
    public static function getGraphs(): array
    {
        return [
            'много кусков' => [require __DIR__ . '/Cases/Graphs/ManyParts.php'],
            'дерево с циклом' => [self::getSmallTree()],
            'большой с перешейками' => [self::getBig2()],
            'семь на семь' => [self::getSevenBySeven()],
            'колёса' => [self::getSevenFields()],
            'треугольник в треугольнике' => [self::getTriangleInTriangle()],
        ];
    }

    /**
     * @param true[][] $connections
     */
    private function build(array $connections): Instruction
    {
        return $this->buildWithTrace($connections)[0];
    }

    /**
     * @param true[][] $connections
     *
     * @return array{Instruction, Trace}
     */
    private function buildWithTrace(array $connections): array
    {
        $trace = new Trace();
        $this->getPlanarService()->connectionsToFrames($connections, new SliceAggregate(new ZeroSlice()), 100.0, $trace);

        return [(new InstructionService())->build($trace, $connections), $trace];
    }

    /**
     * Все шаги по порядку, с дочерними дорожками.
     *
     * @return Step[]
     */
    private function getAllSteps(Instruction $instruction): array
    {
        $result = [];
        $walk = static function (array $steps) use (&$walk, &$result): void {
            foreach ($steps as $step) {
                foreach ($step->children as $lane) {
                    $walk($lane->steps);
                }

                $result[] = $step;
            }
        };
        $walk($instruction->steps);

        return $result;
    }

    /**
     * @param int[] $walk
     *
     * @return string[]
     */
    private function walkEdges(array $walk): array
    {
        $result = [];
        $count = count($walk);

        foreach ($walk as $number => $vertex) {
            $next = $walk[($number + 1) % $count];

            if ($count > 2 || $number === 0) {
                $result[] = min($vertex, $next) . '-' . max($vertex, $next);
            }
        }

        return $result;
    }

    /**
     * На какой арене лежит кусок, из которого режут.
     *
     * @param array<string, int[]> $pieces
     * @param int[] $vertexes
     */
    private function findHost(array $pieces, array $vertexes): ?string
    {
        foreach ($pieces as $arena => $piece) {
            if (array_diff($vertexes, $piece) === []) {
                return (string) $arena;
            }
        }

        return null;
    }

    /**
     * @param iterable<int|string> $vertexes
     *
     * @return int[]
     */
    private function sorted(iterable $vertexes): array
    {
        $result = [];

        foreach ($vertexes as $vertex) {
            $result[] = (int) $vertex;
        }

        sort($result);

        return array_values(array_unique($result));
    }
}
