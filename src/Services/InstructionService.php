<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\Aggregates\Trace;
use EugeneErg\Graphs\ValueObjects\Instruction\Biconnected;
use EugeneErg\Graphs\ValueObjects\Instruction\Build;
use EugeneErg\Graphs\ValueObjects\Instruction\Contour;
use EugeneErg\Graphs\ValueObjects\Instruction\Straighten;
use EugeneErg\Graphs\ValueObjects\Instruction\Postpone;
use EugeneErg\Graphs\ValueObjects\Instruction\Cover;
use EugeneErg\Graphs\ValueObjects\Instruction\Cut;
use EugeneErg\Graphs\ValueObjects\Instruction\Fill;
use EugeneErg\Graphs\ValueObjects\Instruction\Graph;
use EugeneErg\Graphs\ValueObjects\Instruction\Glue;
use EugeneErg\Graphs\ValueObjects\Instruction\Instruction;
use EugeneErg\Graphs\ValueObjects\Instruction\Lane;
use EugeneErg\Graphs\ValueObjects\Instruction\Lock;
use EugeneErg\Graphs\ValueObjects\Instruction\Measure;
use EugeneErg\Graphs\ValueObjects\Instruction\Open;
use EugeneErg\Graphs\ValueObjects\Instruction\Relax;
use EugeneErg\Graphs\ValueObjects\Instruction\Remain;
use EugeneErg\Graphs\ValueObjects\Instruction\Sides;
use EugeneErg\Graphs\ValueObjects\Instruction\Split;
use EugeneErg\Graphs\ValueObjects\Instruction\Stage;
use EugeneErg\Graphs\ValueObjects\Instruction\Step;
use EugeneErg\Graphs\ValueObjects\Instruction\Tie;
use EugeneErg\Graphs\ValueObjects\Instruction\Twin;
use EugeneErg\Graphs\ValueObjects\Instruction\Whole;
use EugeneErg\Graphs\ValueObjects\StageKind;

/**
 * Инструкция из журнала алгоритма: что и в каком порядке.
 *
 * Здесь нет ни координат, ни кадров, ни экземпляров. Только вершины, рёбра
 * и то, что с ними делают, — так, чтобы текст читался как конспект:
 *
 * 1. куски — заливаем из незакрашенной вершины; всё, куда дошла краска,
 *    один кусок; его отрезаем на свою арену;
 * 2. ветви — у каждого куска свои, и куски разбираются одновременно:
 *    запираем точку сочленения, заливаем из соседа, отрезаем накрытое,
 *    точка сочленения раздваивается;
 * 3. поля — у каждой ветви свои, все ветви одновременно: запираем обход,
 *    заливаем висящие на нём куски, выносим наружу непомещающееся,
 *    отрезаем поле; последний обход — контур ветви.
 *
 * Дальше этапы будут добавляться по одному.
 */
final readonly class InstructionService
{
    /**
     * @param array<int, array<int, mixed>> $connections
     */
    public function build(Trace $trace, array $connections): Instruction
    {
        $stages = $trace->getStages();
        $parts = $this->getParts($stages);
        $arenas = [];

        foreach (array_keys($parts) as $number) {
            $arenas[$number] = $this->getArenaName($number);
        }

        [$branchLanes, $branches] = $this->getBranchLanes($stages, $parts, $arenas, $connections);

        $fieldLanes = $this->getFieldLanes($stages, $branches, $connections);
        $steps = [
            new Step(new Graph($this->getVertexes($connections), $this->getEdges($connections))),
            new Step(new Stage('найти куски'), [new Lane('', $this->getPartSteps($stages, $parts, $arenas, $connections))]),
            new Step(new Stage('найти ветви'), $branchLanes),
            new Step(new Stage('найти поля'), $fieldLanes),
        ];
        [$glueLanes, $faces] = $this->getGlueLanes($stages, $fieldLanes, $parts, $arenas, $connections);

        if ($glueLanes !== []) {
            $steps[] = new Step(new Stage('склеить ветви'), $glueLanes);
        }

        $buildLanes = $this->getBuildLanes($stages, $faces, $parts, $arenas, $connections);

        if ($buildLanes !== []) {
            $steps[] = new Step(new Stage('собрать'), $buildLanes);
        }

        return new Instruction($steps);
    }

    /**
     * Этап четвёртый: односвязный кусок склеивается в двусвязный
     * (`VertexService::mergeTree`). В каждой точке сочленения берутся поле
     * корня и поле ветви, оба проходят через неё; склеенные, они
     * раскрываются в круг, от точки отмеряют по половине каждого, концы
     * связывают, и круг разрезается по связке на два новых поля.
     *
     * Склейки одного куска идут по очереди: следующая может взять поле,
     * получившееся из предыдущей. Куски склеиваются одновременно.
     *
     * @param \EugeneErg\Graphs\ValueObjects\Stage[] $stages
     * @param Lane[] $fieldLanes
     * @param array<int, int[]> $parts
     * @param array<int, string> $arenas
     * @param array<int, array<int, mixed>> $connections
     *
     * @return array{Lane[], array<string, int[]>} дорожки и поля после склейки
     */
    private function getGlueLanes(array $stages, array $fieldLanes, array $parts, array $arenas, array $connections): array
    {
        [$faces, $cycles] = $this->getFaces($fieldLanes);
        $known = [];

        foreach ($this->getEdges($connections) as [$vertexA, $vertexB]) {
            $known[$vertexA . '-' . $vertexB] = true;
        }

        $steps = [];

        foreach ($stages as $stage) {
            if ($stage->kind !== StageKind::Tie || count($stage->groups) !== 2 || count($stage->produced) !== 2) {
                continue;
            }

            $vertex = (int) ($stage->highlight[0] ?? 0);
            $part = 0;

            foreach ($parts as $number => $vertexes) {
                if (in_array($vertex, $vertexes, true)) {
                    $part = $number;
                }
            }

            $list = [];
            $arena = $this->takeFace(array_map(intval(...), $stage->groups[0]), $faces, $cycles, $list);
            $other = $this->takeFace(array_map(intval(...), $stage->groups[1]), $faces, $cycles, $list, $arena);
            $first = array_values(array_map(intval(...), $stage->produced[0]));
            $second = array_values(array_map(intval(...), $stage->produced[1]));
            [$tieA, $tieB] = $this->getTie($first, $known);
            $known[min($tieA, $tieB) . '-' . max($tieA, $tieB)] = true;
            $pathFirst = $this->getPathAround($first, $tieB, $tieA);
            $pathSecond = $this->getPathAround($second, $tieA, $tieB);
            $circle = array_merge($pathFirst, array_slice($pathSecond, 1, -1));
            $at = (int) array_search($vertex, $pathFirst, true);

            $list[] = new Step(new Glue($vertex, $arena, $other));
            $list[] = new Step(new Open($vertex, $circle, $arena, $other));
            $list[] = new Step(new Measure(
                $vertex,
                array_reverse(array_slice($pathFirst, 0, $at + 1)),
                array_slice($pathFirst, $at),
                $arena,
                $other,
            ));
            $list[] = new Step(new Tie($tieA, $tieB, $arena, $other));
            $list[] = new Step(new Split($vertex, $first, $arena, $second, $other));
            $steps[$part] = array_merge($steps[$part] ?? [], $list);
            $faces[$arena] = $first;
            $faces[$other] = $second;
        }

        $result = [];

        foreach ($steps as $part => $list) {
            $result[] = new Lane('кусок ' . $arenas[$part], $list, $arenas[$part]);
        }

        return [$result, $faces];
    }

    /**
     * Этап пятый: укладка собирается из полей так, как её собирает
     * алгоритм (`ArcService::createArcs`, `CoordinateService::getCoordinates`).
     * Внешняя грань ложится на окружность. Дальше то, что ложится легко: поле
     * прилегает к границе участком, и его свободная сторона ложится дугой.
     * Поле, прилегающее одним ребром, откладывается. Отложенные ложатся
     * вместе — когда их накрыла обычная дуга или когда укладывать больше
     * нечего и их объединяют. Потом укладка выпрямляется и расслабляется.
     * Куски собираются одновременно.
     *
     * @param \EugeneErg\Graphs\ValueObjects\Stage[] $stages
     * @param array<string, int[]> $faces арена => поле
     * @param array<int, int[]> $parts
     * @param array<int, string> $arenas
     * @param array<int, array<int, mixed>> $connections
     *
     * @return Lane[]
     */
    private function getBuildLanes(array $stages, array $faces, array $parts, array $arenas, array $connections): array
    {
        $owner = [];

        foreach ($parts as $number => $part) {
            foreach ($part as $vertex) {
                $owner[$vertex] = $number;
            }
        }

        $events = [];
        $outer = [];

        foreach ($stages as $stage) {
            $first = array_values(array_map(intval(...), $stage->groups[0] ?? []));

            if ($first === []) {
                continue;
            }

            $part = $owner[$first[0]] ?? 0;

            if ($stage->kind === StageKind::Build && ! isset($outer[$part])) {
                // Первый шаг построения — внешняя грань на окружности. Он идёт
                // после того, как дуги найдены, но ложится первым.
                $outer[$part] = ['outer', [array_values(array_unique(array_map(intval(...), $stage->groups[1] ?? [])))], []];
            } elseif (in_array($stage->kind, [StageKind::Arc, StageKind::Postpone, StageKind::Absorb, StageKind::Merge], true)) {
                $events[$part][] = [
                    $stage->kind->value,
                    array_map(static fn (array $face): array => array_values(array_map(intval(...), $face)), $stage->groups),
                    array_values(array_map(intval(...), $stage->highlight)),
                ];
            }
        }

        $result = [];

        foreach ($parts as $number => $part) {
            $inPart = array_flip($part);
            $waiting = array_filter($faces, static fn (array $walk): bool => isset($inPart[$walk[0] ?? -1]));

            if ($waiting === []) {
                continue;
            }

            $list = [];

            foreach (array_merge(isset($outer[$number]) ? [$outer[$number]] : [], $events[$number] ?? []) as [$kind, $group, $edge]) {
                $taken = [];

                foreach ($group as $face) {
                    $arena = $this->findField($face, $waiting);

                    if ($arena !== null) {
                        $taken[$arena] = $waiting[$arena];
                        unset($waiting[$arena]);
                    }
                }

                if ($taken === []) {
                    continue;
                }

                if ($kind === StageKind::Postpone->value) {
                    // Отложенное ещё не легло: оно ждёт в очереди.
                    foreach ($taken as $arena => $walk) {
                        $list[] = new Step(new Postpone((string) $arena, $walk, $edge[0] ?? 0, $edge[1] ?? 0));
                        $waiting[$arena] = $walk;
                    }

                    continue;
                }

                if (count($taken) === 1) {
                    $arena = (string) array_key_first($taken);
                    $list[] = new Step(new Build($arena, $taken[$arena]));

                    continue;
                }

                $list[] = new Step(new Cover(
                    array_combine(array_map(strval(...), array_keys($taken)), array_values($taken)),
                    $kind === StageKind::Merge->value,
                ));
            }

            // Если поле так и не попало в журнал сборки, оно всё равно ложится.
            foreach ($waiting as $arena => $walk) {
                if (! $this->isPostponed($list, (string) $arena)) {
                    $list[] = new Step(new Build((string) $arena, $walk));
                }
            }

            $list[] = new Step(new Straighten());
            $list[] = new Step(new Relax());
            $result[] = new Lane('кусок ' . $arenas[$number], $list, $arenas[$number]);
        }

        return $result;
    }

    /**
     * Поле, которое отложили и так и не уложили.
     *
     * @param Step[] $steps
     */
    private function isPostponed(array $steps, string $arena): bool
    {
        $postponed = false;

        foreach ($steps as $step) {
            $action = $step->action;

            if ($action instanceof Postpone && $action->arena === $arena) {
                $postponed = true;
            } elseif (($action instanceof Build && $action->arena === $arena)
                || ($action instanceof Cover && isset($action->fields[$arena]))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Арена, на которой лежит это поле: то же множество вершин.
     *
     * @param int[] $face
     * @param array<string, int[]> $fields
     */
    private function findField(array $face, array $fields): ?string
    {
        $sorted = array_values(array_unique($face));
        sort($sorted);

        foreach ($fields as $arena => $walk) {
            $candidate = array_values(array_unique($walk));
            sort($candidate);

            if ($candidate === $sorted) {
                return (string) $arena;
            }
        }

        return null;
    }

    /**
     * Поля после поиска: арена => её обход. Ветвь-цикл из трёх вершин —
     * это два поля на одной арене: внутри и снаружи.
     *
     * @param Lane[] $fieldLanes
     *
     * @return array{array<string, int[]>, array<string, true>}
     */
    private function getFaces(array $fieldLanes): array
    {
        $faces = [];
        $cycles = [];

        foreach ($fieldLanes as $lane) {
            foreach ($lane->steps as $step) {
                $action = $step->action;

                if ($action instanceof Whole) {
                    $faces[$lane->arena] = $action->vertexes;

                    if (count($action->vertexes) > 2) {
                        $cycles[$lane->arena] = true;
                    }
                } elseif ($action instanceof Cut && $action->walk !== null) {
                    $faces[$action->arena] = $action->walk;
                } elseif ($action instanceof Contour) {
                    $faces[$lane->arena] = $action->walk;
                }

                // Отрезанное вместе с внутренним — ещё не поле: его поля
                // найдутся, когда его разберут.
                if ($step->children !== []) {
                    [$innerFaces, $innerCycles] = $this->getFaces($step->children);
                    $faces = array_replace($faces, $innerFaces);
                    $cycles += $innerCycles;
                }
            }
        }

        return [$faces, $cycles];
    }

    /**
     * Арена, на которой лежит это поле. Если это поле-цикл, в склейку идёт
     * его копия: второе поле цикла остаётся на месте.
     *
     * @param int[] $face
     * @param array<string, int[]> $faces
     * @param array<string, true> $cycles
     * @param Step[] $steps сюда дописывается раздвоение, если оно нужно
     */
    private function takeFace(array $face, array &$faces, array &$cycles, array &$steps, ?string $except = null): string
    {
        $sorted = array_values(array_unique($face));
        sort($sorted);

        foreach ($faces as $arena => $walk) {
            $candidate = array_values(array_unique($walk));
            sort($candidate);

            if ($candidate !== $sorted || $arena === $except) {
                continue;
            }

            if (isset($cycles[$arena])) {
                unset($cycles[$arena]);
                $copy = $arena . "'";
                $steps[] = new Step(new Twin($arena, $copy));
                $faces[$copy] = $walk;

                return $copy;
            }

            return $arena;
        }

        throw new \LogicException(sprintf('Поля %s среди найденных нет.', implode('-', $face)));
    }

    /**
     * Связка: пара соседей в новом поле, которой нет ни в графе, ни среди
     * прежних связок.
     *
     * @param int[] $face
     * @param array<string, true> $known
     *
     * @return array{int, int}
     */
    private function getTie(array $face, array $known): array
    {
        $count = count($face);

        foreach ($face as $number => $vertex) {
            $next = $face[($number + 1) % $count];

            if (! isset($known[min($vertex, $next) . '-' . max($vertex, $next)])) {
                return [$vertex, $next];
            }
        }

        throw new \LogicException('В новом поле нет связки: ' . implode('-', $face));
    }

    /**
     * Обход поля без связки: от одного её конца до другого.
     *
     * @param int[] $face
     *
     * @return int[]
     */
    private function getPathAround(array $face, int $from, int $to): array
    {
        $count = count($face);

        foreach ($face as $number => $vertex) {
            $next = $face[($number + 1) % $count];

            if (($vertex === $to && $next === $from) || ($vertex === $from && $next === $to)) {
                $path = array_merge(array_slice($face, $number + 1), array_slice($face, 0, $number + 1));

                return $path[0] === $from ? $path : array_reverse($path);
            }
        }

        throw new \LogicException('Связки нет в поле: ' . implode('-', $face));
    }

    /**
     * Несвязные куски в том порядке, в каком их нашла заливка.
     *
     * @param \EugeneErg\Graphs\ValueObjects\Stage[] $stages
     *
     * @return array<int, int[]>
     */
    private function getParts(array $stages): array
    {
        foreach ($stages as $stage) {
            if ($stage->kind === StageKind::Components) {
                return array_values(array_map(
                    static fn (array $part): array => array_values(array_map(intval(...), $part)),
                    $stage->groups,
                ));
            }
        }

        return [];
    }

    /**
     * Этап первый: заливка за заливкой, и каждый найденный кусок — на свою
     * арену. Последний кусок резать не надо: он и есть остаток.
     *
     * @param \EugeneErg\Graphs\ValueObjects\Stage[] $stages
     * @param array<int, int[]> $parts
     * @param array<int, string> $arenas
     * @param array<int, array<int, mixed>> $connections
     *
     * @return Step[]
     */
    private function getPartSteps(array $stages, array $parts, array $arenas, array $connections): array
    {
        $fills = $this->getPartFills($stages);
        $result = [];
        $last = count($parts) - 1;

        foreach ($parts as $number => $part) {
            if (isset($fills[$number])) {
                $result[] = new Step($fills[$number]);
            }

            $result[] = new Step($number === $last
                ? new Remain($part, $arenas[$number])
                : new Cut($part, $this->getInducedEdges($part, $connections), [], [], $arenas[$number]));
        }

        return $result;
    }

    /**
     * Заливки первого этапа, как их записал алгоритм. Новая заливка
     * начинается с волны без притока: краске ещё неоткуда было прийти.
     *
     * @param \EugeneErg\Graphs\ValueObjects\Stage[] $stages
     *
     * @return Fill[]
     */
    private function getPartFills(array $stages): array
    {
        $result = [];
        $from = null;
        $waves = [];

        foreach ($stages as $stage) {
            if ($stage->kind === StageKind::Components) {
                break;
            }

            if ($stage->kind !== StageKind::Fill) {
                continue;
            }

            if ($stage->flows === []) {
                if ($from !== null) {
                    $result[] = new Fill($from, $waves);
                }

                $from = (int) ($stage->highlight[0] ?? 0);
                $waves = [];

                continue;
            }

            $waves[] = array_values(array_map(intval(...), $stage->highlight));
        }

        if ($from !== null) {
            $result[] = new Fill($from, $waves);
        }

        return $result;
    }

    /**
     * Этап второй: у каждого куска свои ветви, и куски разбираются
     * одновременно — по дорожке на кусок.
     *
     * Алгоритм ищет ветви рекурсией и записывает их на обратном ходу. Сам же
     * приём — цикл: заперли точку сочленения, залили из соседа, краска
     * накрыла ровно одну ветвь, её и отрезали, точка сочленения раздвоилась.
     * Ветви отрезаются с конца находок: найденное первым обычно и есть
     * основная часть куска, ей и оставаться.
     *
     * @param \EugeneErg\Graphs\ValueObjects\Stage[] $stages
     * @param array<int, int[]> $parts
     * @param array<int, string> $arenas
     * @param array<int, array<int, mixed>> $connections
     *
     * @return array{Lane[], array<string, int[]>} дорожки и получившиеся
     *         ветви: арена => её вершины
     */
    private function getBranchLanes(array $stages, array $parts, array $arenas, array $connections): array
    {
        $found = $this->getFoundBranches($stages, $parts);
        $result = [];
        $pieces = [];

        foreach ($parts as $number => $part) {
            $branches = $found[$number] ?? [];

            if ($branches === []) {
                $result[] = new Lane('арена ' . $arenas[$number], [new Step(new Biconnected($part))], $arenas[$number]);
                $pieces[$arenas[$number]] = $part;

                continue;
            }

            $steps = [];
            $rest = $part;
            $cut = 0;

            foreach (array_reverse($branches) as [$at, $branch]) {
                $left = array_values(array_diff($rest, array_diff($branch, [$at])));

                // Последняя ветвь — это то, что осталось: резать нечего.
                if ($left === [] || array_diff($rest, $branch) === []) {
                    continue;
                }

                $inside = array_values(array_diff($branch, [$at]));
                $from = $this->getNeighbourIn($at, $inside, $connections);
                $cut++;
                $steps[] = new Step(new Lock([$at]));
                $steps[] = new Step(new Fill($from, $this->getWaves($from, $inside, $connections)));
                $steps[] = new Step(new Cut(
                    $inside,
                    $this->getInducedEdges($branch, $connections),
                    [$at],
                    [],
                    $arenas[$number] . $cut,
                ));
                $pieces[$arenas[$number] . $cut] = $branch;
                $rest = $left;
            }

            $steps[] = new Step(new Remain($rest, $arenas[$number]));
            $pieces[$arenas[$number]] = $rest;
            $result[] = new Lane('арена ' . $arenas[$number], $steps, $arenas[$number]);
        }

        return [$result, $pieces];
    }

    /**
     * Этап третий: поля каждой ветви. Ищутся до склейки, ветви друг от друга
     * не зависят — поэтому все одновременно, по дорожке на ветвь.
     *
     * Круг один и тот же: запереть обход, залить из каждого соседа — каждая
     * заливка есть кусок, висящий на обходе, — вынести наружу то, что внутрь
     * не помещается, и отрезать поле. Последний обход — контур ветви.
     *
     * Что при разрезе поля вынимается, а что раздваивается, решает
     * будущее: ребро или вершина, нужные ещё какому-то полю этой ветви,
     * раздваиваются, а больше никому не нужные — вынимаются.
     *
     * @param \EugeneErg\Graphs\ValueObjects\Stage[] $stages
     * @param array<string, int[]> $branches арена ветви => её вершины
     * @param array<int, array<int, mixed>> $connections
     *
     * @return Lane[]
     */
    private function getFieldLanes(array $stages, array $branches, array $connections): array
    {
        $rounds = [];

        foreach ($this->getFieldRounds($stages) as $round) {
            foreach ($branches as $arena => $branch) {
                if (array_diff($round['walk'], $branch) === []) {
                    $rounds[$arena][] = $round;

                    break;
                }
            }
        }

        $result = [];

        foreach ($branches as $arena => $branch) {
            $list = $rounds[$arena] ?? [];

            if ($list === []) {
                $result[] = new Lane('арена ' . $arena, [new Step(new Whole($branch))], $arena);

                continue;
            }

            $edges = [];

            foreach ($this->getInducedEdges($branch, $connections) as $edge) {
                $edges[$edge[0] . '-' . $edge[1]] = $edge;
            }

            $position = 0;
            $steps = $this->getArenaFieldSteps((string) $arena, $branch, $edges, $list, $position, true);
            $result[] = new Lane('арена ' . $arena, $steps, (string) $arena);
        }

        return $result;
    }

    /**
     * Поиск полей на одной арене, круг за кругом. Круг — запертый обход,
     * заливки висящего на нём, стороны и разрез.
     *
     * Если внутри обхода что-то осталось, обход — ещё не поле: алгоритм
     * отрезает его вместе с внутренним и следующими кругами разбирает уже
     * отрезанное (`EdgeService::splitOnTreeEdges` уходит в рекурсию), а
     * к остатку возвращается потом. Здесь так же: круги, которые идут
     * следом, достаются отрезанной арене, пока она не разобрана целиком.
     *
     * @param int[] $vertexes что лежит на арене
     * @param array<string, array{int, int}> $edges рёбра на арене
     * @param array<int, array{walk: int[], fills: Fill[], outside: array<int, int[]>}> $rounds
     * @param array<string, int> $uses сколько раз ребро уже уходило с арены:
     *        ребро лежит между двумя полями, и на арене оно нужно, пока не
     *        отрезаны оба. У отрезанного края — обход, по которому резали:
     *        второе поле его рёбер осталось снаружи, в остатке
     *
     * @return Step[]
     */
    private function getArenaFieldSteps(
        string $arena,
        array $vertexes,
        array $edges,
        array $rounds,
        int &$position,
        bool $top,
        array $uses = [],
    ): array {
        $steps = [];
        $first = $top;
        $number = 0;

        while ($position < count($rounds)) {
            $round = $rounds[$position];
            $position++;
            $walkEdges = $this->getWalkEdges($round['walk']);
            $last = $top
                ? $position === count($rounds)
                : array_diff_key($edges, array_flip(array_map(static fn (array $edge): string => $edge[0] . '-' . $edge[1], $walkEdges))) === [];

            // Контур алгоритм тоже запирает, но ничего на нём уже не висит
            // и спрашивать не о чем: запирание без ответа — светофор.
            if ($last && $round['fills'] === [] && $round['outside'] === []) {
                $steps[] = new Step(new Contour($round['walk'], $arena));

                break;
            }

            $steps[] = new Step(new Lock($round['walk'], true));
            $pieces = $this->getHanging($round['walk'], $vertexes, $edges);
            $painted = [];

            foreach ($round['fills'] as $fill) {
                $steps[] = new Step($fill);
                $painted = array_merge($painted, [$fill->from], ...$fill->waves);
            }

            // Заливку из одной вершины алгоритм в журнал не пишет: она
            // ничего не нашла. Но кусок из одной вершины тоже висит на
            // обходе, его тоже заливали, и без этого он попадает на
            // сторону ниоткуда.
            foreach ($pieces as $piece) {
                if (count($piece) === 1 && ! in_array($piece[0], $painted, true)) {
                    $steps[] = new Step(new Fill($piece[0], []));
                }
            }

            // Когда на обходе ничего не висит, раскладывать по сторонам
            // нечего: поле — сам обход.
            $sides = $this->getSides($pieces, $round['outside'], $first);
            $first = false;

            if ($pieces !== []) {
                $steps[] = new Step($sides);
            }

            $inside = $sides->insideVertexes();

            if ($last && $inside === []) {
                $steps[] = new Step(new Contour($round['walk'], $arena));

                break;
            }

            $number++;
            $cut = $this->getFieldCut($round['walk'], $arena . '.' . $number, $inside, $edges, $uses);
            $steps[] = new Step($cut);
            $vertexes = array_values(array_diff($vertexes, $cut->vertexes));

            foreach ($cut->edges as [$vertexA, $vertexB]) {
                unset($edges[$vertexA . '-' . $vertexB]);
            }

            if ($inside !== []) {
                $inner = [];

                foreach (array_merge($cut->edges, $cut->twinEdges) as $edge) {
                    $inner[$edge[0] . '-' . $edge[1]] = $edge;
                }

                $steps[] = new Step(new Stage('разобрать отрезанное'), [new Lane('', $this->getArenaFieldSteps(
                    $cut->arena,
                    array_values(array_unique(array_merge($cut->vertexes, $cut->twinVertexes))),
                    $inner,
                    $rounds,
                    $position,
                    false,
                    array_fill_keys(array_map(static fn (array $edge): string => $edge[0] . '-' . $edge[1], $this->getWalkEdges($round['walk'])), 1),
                ), $cut->arena)]);
            }

            if ($last) {
                break;
            }
        }

        return $steps;
    }

    /**
     * Круги поиска полей, как их записал алгоритм: запертый обход, заливки
     * висящих на нём кусков, вынесенное наружу и само поле.
     *
     * Запирания при поиске ветвей кончаются ветвью, а не полем, — такие
     * круги отбрасываются.
     *
     * @param \EugeneErg\Graphs\ValueObjects\Stage[] $stages
     *
     * @return array<int, array{walk: int[], fills: Fill[], outside: array<int, int[]>}>
     */
    private function getFieldRounds(array $stages): array
    {
        $result = [];
        // Текущий круг: запертый обход, законченные заливки, вынесенное.
        $walk = null;
        $fills = [];
        $outside = [];
        // Заливка, которая идёт прямо сейчас.
        $from = null;
        $waves = [];
        $parted = false;

        foreach ($stages as $stage) {
            if ($stage->kind === StageKind::Components) {
                $parted = true;

                continue;
            }

            if (! $parted) {
                continue;
            }

            $highlight = array_values(array_map(intval(...), $stage->highlight));

            if ($stage->kind === StageKind::Block) {
                [$walk, $fills, $outside, $from, $waves] = [$highlight, [], [], null, []];

                continue;
            }

            // Запирание при поиске ветвей кончается ветвью, а не полем.
            if ($stage->kind === StageKind::Branch || $walk === null) {
                $walk = null;

                continue;
            }

            if ($stage->kind === StageKind::Fill && $stage->flows !== []) {
                $waves[] = $highlight;

                continue;
            }

            // Любое другое событие заканчивает идущую заливку.
            if ($from !== null) {
                $fills[] = new Fill($from, $waves);
                [$from, $waves] = [null, []];
            }

            if ($stage->kind === StageKind::Fill) {
                $from = $highlight[0] ?? null;

                continue;
            }

            if ($stage->kind === StageKind::Outside) {
                $outside[] = $highlight;

                continue;
            }

            if ($stage->kind === StageKind::Field) {
                if ($walk === $highlight) {
                    $result[] = ['walk' => $walk, 'fills' => $fills, 'outside' => $outside];
                }

                $walk = null;
            }
        }

        return $result;
    }

    /**
     * Куски, висящие на запертом обходе: то, что накроет заливка из каждого
     * его соседа, — связные части того, что на арене осталось, без обхода.
     *
     * @param int[] $walk
     * @param int[] $remaining
     * @param array<string, array{int, int}> $edges рёбра на арене
     *
     * @return array<int, int[]>
     */
    private function getHanging(array $walk, array $remaining, array $edges): array
    {
        $free = array_flip(array_values(array_diff($remaining, $walk)));
        $neighbours = [];

        foreach ($edges as [$vertexA, $vertexB]) {
            $neighbours[$vertexA][] = $vertexB;
            $neighbours[$vertexB][] = $vertexA;
        }

        $result = [];

        foreach (array_keys($free) as $start) {
            if (! isset($free[$start])) {
                continue;
            }

            unset($free[$start]);
            $piece = [$start];

            for ($position = 0; $position < count($piece); $position++) {
                foreach ($neighbours[$piece[$position]] ?? [] as $neighbour) {
                    if (isset($free[$neighbour])) {
                        unset($free[$neighbour]);
                        $piece[] = $neighbour;
                    }
                }
            }

            $result[] = $piece;
        }

        return $result;
    }

    /**
     * Стороны, как их разложил алгоритм. Снаружи — куски, которые он
     * записал внешними; внутри — остальные. На первом обходе, если всё
     * легло внутрь, всё это — другая сторона (`EdgeService::splitOnTreeEdges`).
     *
     * @param array<int, int[]> $pieces
     * @param array<int, int[]> $outside внешние куски из журнала
     */
    private function getSides(array $pieces, array $outside, bool $first): Sides
    {
        $outer = array_flip(array_merge([], ...$outside));
        $inside = [];
        $result = [];

        foreach ($pieces as $piece) {
            array_intersect_key(array_flip($piece), $outer) !== []
                ? $result[] = $piece
                : $inside[] = $piece;
        }

        if ($first && $result === []) {
            return new Sides([], $inside);
        }

        return new Sides($inside, $result);
    }

    /**
     * Разрез внутренней стороны: обход и то, что внутри. Внутреннее уходит
     * целиком — у остатка с ним ничего общего. Ребро обхода остаётся на
     * арене копией, если уходит с неё впервые: оно лежит между двумя полями,
     * и второе ещё здесь (у края арены второе — её контур). Вершина обхода
     * остаётся копией, если на арене у неё ещё есть рёбра; остальные
     * вынимаются.
     *
     * @param int[] $walk
     * @param int[] $inside
     * @param array<string, array{int, int}> $edges рёбра на арене
     * @param array<string, int> $uses сколько раз ребро уже уходило с арены
     */
    private function getFieldCut(array $walk, string $arena, array $inside, array $edges, array &$uses): Cut
    {
        $moved = [];
        $twinEdges = [];

        foreach ($this->getWalkEdges($walk) as $edge) {
            $name = $edge[0] . '-' . $edge[1];
            $uses[$name] = ($uses[$name] ?? 0) + 1;
            $uses[$name] === 1 ? $twinEdges[] = $edge : $moved[$name] = $edge;
        }

        $inner = array_flip($inside);
        $side = array_flip(array_merge($inside, $walk));

        foreach ($edges as $name => [$vertexA, $vertexB]) {
            if ((isset($inner[$vertexA]) || isset($inner[$vertexB])) && isset($side[$vertexA], $side[$vertexB])) {
                $moved[$name] = [$vertexA, $vertexB];
            }
        }

        $left = [];

        foreach (array_diff_key($edges, $moved) as [$vertexA, $vertexB]) {
            $left[$vertexA] = true;
            $left[$vertexB] = true;
        }

        $vertexes = $inside;
        $twinVertexes = [];

        foreach (array_unique($walk) as $vertex) {
            isset($left[$vertex]) ? $twinVertexes[] = $vertex : $vertexes[] = $vertex;
        }

        return new Cut($vertexes, array_values($moved), $twinVertexes, $twinEdges, $arena, $walk);
    }

    /**
     * Рёбра обхода, включая замыкающее.
     *
     * @param int[] $walk
     *
     * @return array<int, array{int, int}>
     */
    private function getWalkEdges(array $walk): array
    {
        $result = [];
        $count = count($walk);

        foreach ($walk as $number => $vertex) {
            $next = $walk[($number + 1) % $count];

            if ($count > 2 || $number === 0) {
                $result[] = [min($vertex, $next), max($vertex, $next)];
            }
        }

        return $result;
    }

    /**
     * Ветви, которые нашёл алгоритм, по кускам: точка сочленения и ветвь.
     *
     * @param \EugeneErg\Graphs\ValueObjects\Stage[] $stages
     * @param array<int, int[]> $parts
     *
     * @return array<int, array<int, array{int, int[]}>>
     */
    private function getFoundBranches(array $stages, array $parts): array
    {
        $owner = [];

        foreach ($parts as $number => $part) {
            foreach ($part as $vertex) {
                $owner[$vertex] = $number;
            }
        }

        $result = [];

        foreach ($stages as $stage) {
            if ($stage->kind !== StageKind::Branch) {
                continue;
            }

            $at = (int) ($stage->produced[0][0] ?? $stage->highlight[0] ?? 0);
            $branch = array_values(array_map(intval(...), $stage->highlight));
            $number = $owner[$at] ?? null;

            if ($number !== null) {
                $result[$number][] = [$at, $branch];
            }
        }

        return $result;
    }

    /**
     * Волны заливки от вершины по этим вершинам — обходом в ширину, ровно
     * как её и делает алгоритм. Первая вершина в волны не входит: с неё
     * заливка начинается.
     *
     * @param int[] $inside
     * @param array<int, array<int, mixed>> $connections
     *
     * @return array<int, int[]>
     */
    private function getWaves(int $from, array $inside, array $connections): array
    {
        $allowed = array_flip($inside);
        $painted = [$from => true];
        $wave = [$from];
        $result = [];

        while ($wave !== []) {
            $next = [];

            foreach ($wave as $vertex) {
                foreach (array_keys($connections[$vertex] ?? []) as $neighbour) {
                    $neighbour = (int) $neighbour;

                    if (isset($allowed[$neighbour]) && ! isset($painted[$neighbour])) {
                        $painted[$neighbour] = true;
                        $next[] = $neighbour;
                    }
                }
            }

            if ($next !== []) {
                $result[] = $next;
            }

            $wave = $next;
        }

        return $result;
    }

    /**
     * Сосед запертой вершины, с которого начинается заливка ветви.
     *
     * @param int[] $inside
     * @param array<int, array<int, mixed>> $connections
     */
    private function getNeighbourIn(int $at, array $inside, array $connections): int
    {
        $allowed = array_flip($inside);

        foreach (array_keys($connections[$at] ?? []) as $neighbour) {
            if (isset($allowed[(int) $neighbour])) {
                return (int) $neighbour;
            }
        }

        return $inside[0] ?? $at;
    }

    /**
     * Рёбра между этими вершинами.
     *
     * @param int[] $vertexes
     * @param array<int, array<int, mixed>> $connections
     *
     * @return array<int, array{int, int}>
     */
    private function getInducedEdges(array $vertexes, array $connections): array
    {
        $inside = array_flip($vertexes);
        $result = [];

        foreach ($this->getEdges($connections) as [$vertexA, $vertexB]) {
            if (isset($inside[$vertexA], $inside[$vertexB])) {
                $result[] = [$vertexA, $vertexB];
            }
        }

        return $result;
    }

    /**
     * @param array<int, array<int, mixed>> $connections
     *
     * @return int[]
     */
    private function getVertexes(array $connections): array
    {
        $result = array_map(intval(...), array_keys($connections));
        sort($result);

        return $result;
    }

    /**
     * @param array<int, array<int, mixed>> $connections
     *
     * @return array<int, array{int, int}>
     */
    private function getEdges(array $connections): array
    {
        $result = [];

        foreach ($connections as $vertexA => $connection) {
            foreach (array_keys($connection) as $vertexB) {
                if ((int) $vertexA < (int) $vertexB) {
                    $result[] = [(int) $vertexA, (int) $vertexB];
                }
            }
        }

        usort($result, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $result;
    }

    /**
     * Арены кусков называются буквами: A, B, C… — после Z идут AA, AB…
     */
    private function getArenaName(int $number): string
    {
        $result = '';

        do {
            $result = chr(65 + $number % 26) . $result;
            $number = intdiv($number, 26) - 1;
        } while ($number >= 0);

        return $result;
    }
}
