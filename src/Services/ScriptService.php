<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\Aggregates\CutPlan;
use EugeneErg\Graphs\Aggregates\Trace;
use EugeneErg\Graphs\ValueObjects\Piece;
use EugeneErg\Graphs\ValueObjects\Scene;
use EugeneErg\Graphs\ValueObjects\Script\ActionInterface;
use EugeneErg\Graphs\ValueObjects\Script\Aside;
use EugeneErg\Graphs\ValueObjects\Script\Block;
use EugeneErg\Graphs\ValueObjects\Script\Build;
use EugeneErg\Graphs\ValueObjects\Script\Cut;
use EugeneErg\Graphs\ValueObjects\Script\Detach;
use EugeneErg\Graphs\ValueObjects\Script\Join;
use EugeneErg\Graphs\ValueObjects\Script\Paint;
use EugeneErg\Graphs\ValueObjects\Script\Relax;
use EugeneErg\Graphs\ValueObjects\Script\Script;
use EugeneErg\Graphs\ValueObjects\Script\Show;
use EugeneErg\Graphs\ValueObjects\Script\Step;
use EugeneErg\Graphs\ValueObjects\Script\Tie;
use EugeneErg\Graphs\ValueObjects\Script\Together;
use EugeneErg\Graphs\ValueObjects\Stage;
use EugeneErg\Graphs\ValueObjects\StageKind;

/**
 * Превращает журнал алгоритма в инструкцию: что делают с клубком, по действию
 * за раз.
 *
 * Журнал говорит, что алгоритм нашёл, — и одним шагом может найти много:
 * десяток точек сочленения, полтора десятка полей, полдюжины граней сразу.
 * Показывать это одним кадром нельзя, поэтому здесь находка разворачивается
 * в последовательность физических действий: отделить одну часть, вырезать одну
 * грань, сдвинуть две грани, связать два шара, разрезать по новой палке.
 *
 * Координат тут нет и кадров тоже. Где что нарисовать, решает `StoryService`;
 * его дело — исполнить инструкцию, а не додумать её.
 */
final readonly class ScriptService
{
    public function __construct(private MotionService $motion = new MotionService())
    {
    }

    /**
     * Инструкция, проигранная целиком: что из чего вырезано, куда легло
     * и какими экземплярами.
     *
     * @param array<int, array<int, mixed>> $connections
     */
    public function execute(Trace $trace, array $connections, Script $script): CutPlan
    {
        $edges = $this->motion->getEdges($connections);
        $result = CutPlan::of(
            Piece::ofEdges($edges),
            $this->getCutCount($edges, $trace->getStages()),
            $script,
        );

        // Места раздаются в порядке сборки: очередь видна сразу, и уже
        // разложенные куски не переставляются на глазах. Порядок берётся тот
        // самый, в каком куски и правда лягут, — иначе сборка пойдёт скачками
        // по кольцу вместо того, чтобы обходить его подряд.
        $built = [];

        foreach ($script->steps as $step) {
            if ($step->action instanceof Build) {
                $built[] = $step->action->piece;
            }
        }

        $result->orderSlots($built);

        return $result;
    }

    /**
     * @param array<int, array<int, mixed>> $connections
     */
    public function compile(Trace $trace, array $connections): Script
    {
        $stages = $trace->getStages();
        $steps = [];
        // Что сейчас режут: сначала весь граф, потом то, что осталось после
        // каждого разреза. По нему и считается отрезаемое.
        $piece = $this->getVertexes($connections);
        $filled = [];
        // Поиск ветвей идёт рекурсией: алгоритм сначала уходит вглубь, запирая
        // точку за точкой, и только на обратном ходу записывает найденное.
        // Показывать это как есть нельзя — выйдет пять запираний подряд, а
        // потом пять разрезов подряд. Поэтому находки копятся и разворачиваются
        // в цикл «запер — залил — вырезал», по одной ветви за круг.
        $digging = false;
        // Поиски ветвей собираются заранее и разворачиваются все разом:
        // несвязные куски разбираются одновременно, а не по очереди.
        $searches = $this->getSearches($stages);
        // Куски ищут только в самом начале, до шага «кусков столько-то».
        // Дальше заливка работает уже на ветвях и полях, и отрезать по ней
        // несвязный кусок нечего.
        $parting = true;
        // Вершины каждого несвязного куска: по ним разбор полей и раскладывают
        // по дорожкам, чтобы куски разбирались одновременно.
        $parts = [];
        // После разбора на ветви шаги копятся, а не выдаются сразу: их ещё
        // предстоит сложить по кускам и пустить параллельно.
        $tail = null;

        foreach ($stages as $number => $stage) {
            if ($stage->kind === StageKind::Components) {
                $parting = false;
            }

            // Точек сочленения нет — искать ветви не в чем: кусок двусвязный
            // целиком, и дальше сразу поля.
            if ($stage->kind === StageKind::ArticulationVertexes && $stage->highlight !== []) {
                $digging = true;
            }

            // Поиск ветвей кончился, как только пошло что-то другое: тут его
            // и разворачиваем в цикл. Если ветвей не нашлось — кусок двусвязный
            // целиком, и разворачивать нечего.
            $searching = $digging && in_array($stage->kind, [
                StageKind::ArticulationVertexes,
                StageKind::Block,
                StageKind::Fill,
                StageKind::Branch,
            ], true);

            // Первый же кончившийся поиск разворачивает все: куски чужие
            // друг другу, поэтому разбираются одновременно. Дальше
            // разворачивать уже нечего.
            if ($digging && ! $searching) {
                $digging = false;

                foreach ($this->getBranchSteps($searches, $connections) as $step) {
                    $steps[] = $step;
                }

                $searches = [];
                // Дальше идёт разбор на поля. Он тоже про каждый кусок
                // отдельно, поэтому копится и разворачивается параллельно.
                $tail ??= [];
                $parts[] = $piece;
            }

            // Пока идёт поиск ветвей, сырые запирания и заливки не показываются:
            // они уже развернулись в цикл выше.
            if ($digging || $stage->kind === StageKind::Branch) {
                continue;
            }

            // Запирание показывают только тогда, когда следом и правда
            // заливают: перезапирать уже запертое — светофор, за которым
            // ничего не происходит.
            if ($stage->kind === StageKind::Block && ($stages[$number + 1]->kind ?? null) !== StageKind::Fill) {
                continue;
            }

            // Новая заливка начинается с волны без притока: краске ещё
            // неоткуда было прийти. Значит, прошлая встала — и то, что она
            // накрыла, пора отрезать, не дожидаясь остальных.
            if ($parting && $stage->kind === StageKind::Fill && $stage->flows === [] && $filled !== []) {
                foreach ($this->getPartStep($piece, $filled) as $step) {
                    $steps[] = $step;
                    /** @var Detach $action */
                    $action = $step->action;
                    $parts[] = $action->part;
                    $piece = $action->rest;
                }

                $filled = [];
            }

            if ($stage->kind === StageKind::Fill) {
                $filled = array_merge($filled, $stage->highlight);
            }

            foreach ($this->getSteps($stage) as $step) {
                if ($tail === null) {
                    $steps[] = $step;

                    continue;
                }

                $tail[] = $step;
            }

            if (! $parting
                || $stage->kind !== StageKind::Fill
                || ($stages[$number + 1]->kind ?? null) === StageKind::Fill
            ) {
                continue;
            }

            foreach ($this->getPartStep($piece, $filled) as $step) {
                $steps[] = $step;
                /** @var Detach $action */
                $action = $step->action;
                $parts[] = $action->part;
                $piece = $action->rest;
            }

            $filled = [];
        }

        foreach ($this->getTogetherSteps($tail ?? [], $parts) as $step) {
            $steps[] = $step;
        }

        // Очередь сборки видна только после того, как весь разрез проигран:
        // пока куски не нарезаны, укладывать нечего. Поэтому инструкция
        // достраивается в два приёма — сначала резать, потом складывать.
        $edges = $this->motion->getEdges($connections);
        $plan = CutPlan::of(Piece::ofEdges($edges), $this->getCutCount($edges, $stages), new Script($steps));

        foreach ($this->getAssembly($plan, $stages) as $piece) {
            $steps[] = new Step(new Build($piece), kind: StageKind::Build);
        }

        $steps[] = new Step(new Relax(), kind: StageKind::Relax);

        return new Script($steps);
    }

    /**
     * Во что разворачивается один шаг журнала.
     *
     * Шаги, которым нечего показать, действий не получают: отдельного кадра
     * «нашли точки сочленения» не бывает — кольцо загорается на той точке,
     * по которой режут прямо сейчас, и показывается вместе с разрезом.
     *
     * @return Step[]
     */
    private function getSteps(Stage $stage): array
    {
        return match ($stage->kind) {
            StageKind::Graph => [new Step(new Show('граф'), kind: StageKind::Graph)],
            // Сюда доходят только запирания поиска полей: запирания поиска
            // ветвей разворачиваются в цикл отдельно (`getBranchSteps`).
            // А поле ищут, заперев весь обход.
            StageKind::Block => [new Step(
                new Block($stage->highlight, walk: true),
                $stage->highlight,
                StageKind::Block,
            )],
            StageKind::Fill => [new Step(
                new Paint($stage->getVertexGroups(), $stage->flows),
                kind: StageKind::Fill,
            )],
            // Нашли — тут же и отрезали. Сводные шаги «кусков столько-то»
            // и «ветвей столько-то» своих действий не получают: пока их
            // разворачивали в разрезы, рассказ выходил «нашли все, потом
            // отрезали все».

            // Проверка: этот кусок внутрь запертого обхода не помещается,
            // значит, он снаружи — туда и уезжает.
            StageKind::Outside => [new Step(
                new Aside($stage->highlight),
                $stage->highlight,
                StageKind::Outside,
            )],
            StageKind::Field => [new Step(
                new Cut($stage->highlight),
                $stage->highlight,
                StageKind::Field,
            )],
            StageKind::Tie => $this->getGlueSteps($stage),
            StageKind::Faces => [new Step(new Show('грани'), kind: StageKind::Faces)],
            StageKind::OuterFace => [new Step(
                new Show('внешнюю грань', $stage->highlight),
                $stage->highlight,
                StageKind::OuterFace,
            )],
            default => [],
        };
    }

    /**
     * Отделить кусок, который накрыла остановившаяся заливка.
     *
     * Запирать тут нечего: краска сама упирается в край графа, и накрытое ею
     * и есть несвязный кусок. Он ни за что не держался, поэтому уезжает
     * на свой стол.
     *
     * @param int[] $piece что режут
     * @param int[] $filled куда дошла краска
     *
     * @return Step[]
     */
    private function getPartStep(array $piece, array $filled): array
    {
        $part = array_values(array_intersect($piece, $filled));
        $rest = array_values(array_diff($piece, $filled));

        // Краска накрыла всё: резать нечего — кусок и так один.
        if ($part === [] || $rest === []) {
            return [];
        }

        return [new Step(new Detach($part, $rest, true), [], StageKind::Components)];
    }

    /**
     * Разбор на поля — у всех кусков одновременно.
     *
     * Куски чужие друг другу, поэтому их поля ищутся независимо. Пока это
     * шло по очереди, рассказ вырастал во столько раз, сколько кусков, а на
     * картинке работал один стол, и остальные просто стояли: «ничего
     * не происходит» — и правда ничего.
     *
     * Ветви одного куска — другое дело, и они по-прежнему по очереди: на этом
     * этапе их склеивают друг с другом, значит, независимыми они не являются.
     *
     * Круг разбора один и тот же на всех уровнях: заперли — залили —
     * отрезали. Поэтому шаги и складываются в круги: подряд идущие вопросы
     * и ответ, ради которого спрашивали. Круги кусков потом идут ноздря
     * в ноздрю: запирания всех кусков в один кадр, заливки волна к волне,
     * разрезы в один кадр.
     *
     * @param Step[] $tail
     * @param array<int, int[]> $parts вершины каждого несвязного куска
     *
     * @return Step[]
     */
    private function getTogetherSteps(array $tail, array $parts): array
    {
        if (count($parts) < 2 || $tail === []) {
            return $tail;
        }

        $tracks = [];
        $owner = 0;

        foreach ($tail as $step) {
            $owner = $this->getOwner($step, $parts) ?? $owner;
            $tracks[$owner][] = $step;
        }

        $rounds = array_map($this->getStepRounds(...), $tracks);
        $result = [];

        for ($round = 0; ; $round++) {
            $now = [];

            foreach ($rounds as $track => $list) {
                if (isset($list[$round])) {
                    $now[$track] = $list[$round];
                }
            }

            if ($now === []) {
                return $result;
            }

            // Склейка — три действия подряд: сдвинуть, связать, разрезать.
            // Разорвать их нельзя, поэтому круг со склейкой идёт как есть,
            // кусок за куском. Параллельность возобновляется со следующего.
            if ($this->isGluing($now)) {
                foreach ($now as $steps) {
                    foreach ($steps as $step) {
                        $result[] = $step;
                    }
                }

                continue;
            }

            foreach ($this->getRoundSteps($now) as $step) {
                $result[] = $step;
            }
        }
    }

    /**
     * Есть ли в круге склейка.
     *
     * @param array<int, Step[]> $rounds
     */
    private function isGluing(array $rounds): bool
    {
        foreach ($rounds as $round) {
            foreach ($round as $step) {
                if ($step->action instanceof Join || $step->action instanceof Tie) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Чей это шаг: кусок, которому принадлежит больше всего его шаров.
     *
     * Больше всего, а не «хоть один»: в точке сочленения шар общий, и по
     * одному совпадению шаг ушёл бы не туда.
     *
     * @param array<int, int[]> $parts
     */
    private function getOwner(Step $step, array $parts): ?int
    {
        $best = null;
        $most = 0;

        foreach ($parts as $number => $part) {
            $count = count(array_intersect($step->action->vertexes(), $part));

            if ($count > $most) {
                $most = $count;
                $best = $number;
            }
        }

        return $best;
    }

    /**
     * Круги одной дорожки: подряд идущие вопросы и ответ за ними.
     *
     * @param Step[] $steps
     *
     * @return array<int, Step[]>
     */
    private function getStepRounds(array $steps): array
    {
        $result = [];
        $round = [];

        foreach ($steps as $step) {
            $round[] = $step;

            // Склейка — это сдвинуть, связать и только потом разрезать:
            // три ответа подряд и один круг. Разорви его — и разрез склейки
            // уедет от своей склейки к чужому кругу.
            if (! $step->action->isQuestion()
                && ! $step->action instanceof Join
                && ! $step->action instanceof Tie
            ) {
                $result[] = $round;
                $round = [];
            }
        }

        if ($round !== []) {
            $result[] = $round;
        }

        return $result;
    }

    /**
     * Один круг всех кусков разом.
     *
     * Внутри круга порядок свой у каждого куска, но он всегда один и тот же:
     * заперли, залили, вынесли наружу, отрезали. Поэтому шаги складываются
     * по видам, а не по порядковому номеру: запирания к запираниям, заливки
     * к заливкам. Иначе у куска, которому хватило трёх волн, четвёртая волна
     * пришлась бы на чужой вынос.
     *
     * @param array<int, Step[]> $rounds круг каждого куска
     *
     * @return Step[]
     */
    private function getRoundSteps(array $rounds): array
    {
        // Каждая кучка копит и свои действия, и своё выделение: обводят
        // ровно то, с чем это действие и работает. Свалить выделения в одну
        // кучу нельзя — шаг стал бы обводить чужое.
        $piles = [];
        $waves = [];

        foreach ($rounds as $track => $round) {
            foreach ($round as $step) {
                if ($step->action instanceof Paint) {
                    $waves[$track][] = $step->action;

                    continue;
                }

                // Ответы складываются по видам: «сдвинуть» и «связать» —
                // разные дела, и в одном кадре им делать нечего, даже если
                // они пришлись на один круг у разных кусков.
                $pile = match (true) {
                    $step->action instanceof Block => 'block',
                    $step->action instanceof Aside => 'aside',
                    $step->action->isQuestion() => 'show',
                    default => 'ответ ' . ($step->kind?->value ?? ''),
                };
                $piles[$pile]['actions'][] = $step->action;
                $piles[$pile]['marks'] = array_merge($piles[$pile]['marks'] ?? [], $step->marks);
                $piles[$pile]['kind'] ??= $step->kind;
                $piles[$pile]['marked'] ??= $step->marked;
            }
        }

        $result = [];

        foreach (['block', 'show'] as $pile) {
            if (isset($piles[$pile])) {
                $result[] = $this->getPileStep($piles[$pile]);
            }
        }

        foreach ($this->getMergedPaints($waves) as $step) {
            $result[] = $step;
        }

        if (isset($piles['aside'])) {
            $result[] = $this->getPileStep($piles['aside']);
        }

        foreach ($piles as $name => $pile) {
            if (str_starts_with($name, 'ответ ')) {
                $result[] = $this->getPileStep($pile);
            }
        }

        return $result;
    }

    /**
     * Кучка одинаковых действий — одним шагом.
     *
     * @param array{actions: ActionInterface[], marks?: int[], kind?: ?StageKind, marked?: ?StageKind} $pile
     */
    private function getPileStep(array $pile): Step
    {
        return new Step(
            $this->getTogether($pile['actions']),
            array_values(array_unique($pile['marks'] ?? [])),
            $pile['kind'] ?? null,
            $pile['marked'] ?? null,
        );
    }

    /**
     * Одно действие остаётся собой: `Together` заводится только там, где
     * действий и правда несколько.
     *
     * @param ActionInterface[] $actions
     */
    private function getTogether(array $actions): ActionInterface
    {
        return count($actions) === 1 ? $actions[0] : new Together(...$actions);
    }

    /**
     * Заливки нескольких кусков, сшитые волна к волне.
     *
     * @param array<int, Paint[]> $waves
     *
     * @return Step[]
     */
    private function getMergedPaints(array $waves): array
    {
        $length = 0;

        foreach ($waves as $list) {
            $length = max($length, count($list));
        }

        $painted = [];
        $result = [];

        for ($number = 0; $number < $length; $number++) {
            $groups = [];
            $flows = [];

            foreach ($waves as $track => $list) {
                if (isset($list[$number])) {
                    $painted[$track] = $list[$number]->groups;
                    $flows += $list[$number]->flows;
                }

                $groups += $painted[$track] ?? [];
            }

            $result[] = new Step(new Paint($groups, $flows), kind: StageKind::Fill);
        }

        return $result;
    }

    /**
     * Поиски ветвей: по одному на несвязный кусок.
     *
     * Алгоритм ищет их рекурсией: уходит вглубь, запирая точку за точкой,
     * и записывает найденное только на обратном ходу. Здесь журнал просто
     * прочитан: что нашлось и в каком куске. Во что это развернуть, решает
     * `getBranchSteps`.
     *
     * @param Stage[] $stages
     *
     * @return array<int, array<int, array{?int, int[]}>>
     */
    private function getSearches(array $stages): array
    {
        $result = [];
        $digging = false;
        $found = [];

        foreach ($stages as $stage) {
            if ($stage->kind === StageKind::ArticulationVertexes && $stage->highlight !== []) {
                $digging = true;
                $found = [];
            }

            if ($digging && $stage->kind === StageKind::Branch) {
                $found[] = [$stage->produced[0][0] ?? null, $stage->highlight];
            }

            $searching = $digging && in_array($stage->kind, [
                StageKind::ArticulationVertexes,
                StageKind::Block,
                StageKind::Fill,
                StageKind::Branch,
            ], true);

            if ($digging && ! $searching) {
                $digging = false;

                if ($found !== []) {
                    $result[] = $found;
                }

                $found = [];
            }
        }

        if ($found !== []) {
            $result[] = $found;
        }

        return $result;
    }

    /**
     * Цикл разбора на двусвязные ветви: запер — залил — вырезал, и так по
     * ветви за круг. Все куски разом.
     *
     * Сам приём — цикл: заперли точку сочленения, залили из соседа, краска
     * накрыла ровно одну ветвь, её и отрезали. Разворачивать журнал как есть
     * нельзя: выйдет пять запираний подряд, а потом пять разрезов подряд.
     *
     * А по кускам цикл идёт одновременно. Несвязные куски друг о друге ничего
     * не знают: пока один разбирают, второй разбирается тем же приёмом и в то
     * же время. Показывать их по очереди — и врать про алгоритм, и растягивать
     * рассказ во столько раз, сколько кусков; на семи кусках это семь одинаковых
     * кругов подряд, на которые смотреть невозможно.
     *
     * Внутри куска по-прежнему одна ветвь за круг: `Together` не пропустит
     * две ветви одного куска — они делят остаток.
     *
     * Ветви отрезаются с конца находок: то, что нашлось первым, обычно и есть
     * основная часть куска, и ей правильно остаться на столе, а мелким стеблям
     * уехать вокруг.
     *
     * @param array<int, array<int, array{?int, int[]}>> $searches
     * @param array<int, array<int, mixed>> $connections
     *
     * @return Step[]
     */
    private function getBranchSteps(array $searches, array $connections): array
    {
        $rounds = array_map($this->getRounds(...), $searches);
        $result = [];

        for ($round = 0; ; $round++) {
            $marks = [];
            $detaches = [];
            $waves = [];

            foreach ($rounds as $number => $list) {
                if (! isset($list[$round])) {
                    continue;
                }

                [$at, $branch, $rest] = $list[$round];
                $marks[] = $at;
                $detaches[] = new Detach($branch, $rest, false);
                $waves[$number] = $this->getWaveSteps($branch, $at, $connections);
            }

            if ($detaches === []) {
                return $result;
            }

            $result[] = new Step(new Block($marks), $marks, StageKind::Block);

            foreach ($this->getMergedPaints(array_map(
                static fn (array $steps): array => array_map(
                    static fn (Step $step): Paint => $step->action instanceof Paint ? $step->action : new Paint([], []),
                    $steps,
                ),
                $waves,
            )) as $step) {
                $result[] = $step;
            }

            $result[] = new Step(
                count($detaches) === 1 ? $detaches[0] : new Together(...$detaches),
                $marks,
                StageKind::Branches,
                // Обводят точки сочленения — те самые, по которым сейчас
                // и разрежут. Потому шаг «нашли точки сочленения» своего кадра
                // и не получает: он показывается вместе с разрезом.
                StageKind::ArticulationVertexes,
            );
        }
    }

    /**
     * Круги одного куска: что запирают, что отрезают и что остаётся.
     *
     * @param array<int, array{?int, int[]}> $branches
     *
     * @return array<int, array{int, int[], int[]}>
     */
    private function getRounds(array $branches): array
    {
        // Режут тот кусок, из которого эти ветви и вышли: у каждого несвязного
        // куска свои ветви и свой стол.
        $piece = [];

        foreach ($branches as [$at, $branch]) {
            foreach ($branch as $vertex) {
                $piece[$vertex] = $vertex;
            }
        }

        $piece = array_values($piece);
        $result = [];

        foreach (array_reverse($branches) as [$at, $branch]) {
            $rest = array_values(array_diff($piece, array_diff($branch, [$at])));

            // Последняя ветвь — это то, что осталось: резать нечего.
            if ($at === null || $rest === [] || array_diff($piece, $branch) === []) {
                continue;
            }

            $result[] = [$at, $branch, $rest];
            $piece = $rest;
        }

        return $result;
    }

    /**
     * Заливка ветви от запертой точки: волна за волной, как её и делает
     * алгоритм. Краска идёт по самой ветви и упирается в запертую точку —
     * потому ровно её и накрывает.
     *
     * @param int[] $branch
     * @param array<int, array<int, mixed>> $connections
     *
     * @return Step[]
     */
    private function getWaveSteps(array $branch, int $at, array $connections): array
    {
        $inside = array_flip(array_diff($branch, [$at]));
        $wave = [];

        foreach (array_keys($connections[$at] ?? []) as $neighbour) {
            if (isset($inside[$neighbour])) {
                $wave[] = (int) $neighbour;

                break;
            }
        }

        $painted = [];
        $result = [];
        $flows = [];

        while ($wave !== []) {
            foreach ($wave as $vertex) {
                $painted[$vertex] = 1;
            }

            // Краска ползёт по тем связям, по которым в этот кадр и пришла:
            // от волны, закрашенной в прошлом кадре, к этой.
            $result[] = new Step(new Paint($painted, $flows), kind: StageKind::Fill);
            $flows = [];

            foreach ($wave as $vertex) {
                foreach (array_keys($connections[$vertex] ?? []) as $neighbour) {
                    if (isset($inside[$neighbour]) && ! isset($painted[$neighbour]) && ! isset($flows[$neighbour])) {
                        $flows[(int) $neighbour] = $vertex;
                    }
                }
            }

            $wave = array_keys($flows);
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
        return array_values(array_map(intval(...), array_keys($connections)));
    }

    /**
     * Склейка: сдвинуть, связать, разрезать — три действия, три кадра.
     *
     * Разом подменить две грани ветвей двумя гранями склеенного графа нельзя:
     * в кадре грани просто обменяются шарами, и не видно ни что с чем склеили,
     * ни откуда взялась новая палка. Поэтому руками: сначала две грани сходятся
     * в одну, потом между её краями протягивается палка, и только потом по этой
     * палке режут.
     *
     * @return Step[]
     */
    private function getGlueSteps(Stage $stage): array
    {
        $first = $stage->groups[0] ?? [];
        $second = $stage->groups[1] ?? [];
        $tie = $this->getTieEdge([$first, $second], $stage->produced);

        if ($first === [] || $second === [] || $tie === null || count($stage->produced) < 2) {
            return [];
        }

        $walk = array_values($stage->produced)[0];
        $at = $stage->highlight[0] ?? -1;

        return [
            new Step(new Join($first, $second), array_merge($first, $second), StageKind::Tie),
            new Step(
                new Tie($tie, array_values($stage->produced), $at, $this->getTiePaths([$first, $second], $at, $tie)),
                $tie,
                StageKind::Tie,
            ),
            new Step(new Cut($walk), $walk, StageKind::Tie),
        ];
    }

    /**
     * Откуда куда мерили, прежде чем связать.
     *
     * От точки сочленения идут по грани до того её шара, с которым свяжут, —
     * это ровно половина обхода. Путь нужен картинке: по нему видно, почему
     * палку тянут именно сюда, а не в случайное место.
     *
     * @param array<int, int[]> $walks
     * @param array{int, int} $tie
     *
     * @return array<int, int[]>
     */
    private function getTiePaths(array $walks, int $at, array $tie): array
    {
        $result = [];

        foreach ($walks as $walk) {
            foreach ($tie as $end) {
                $path = $this->getPath($walk, $at, $end);

                if ($path !== null) {
                    $result[] = $path;

                    break;
                }
            }
        }

        return count($result) === 2 ? $result : [];
    }

    /**
     * Короткий путь по замкнутому обходу от одного шара до другого.
     *
     * @param int[] $walk
     *
     * @return ?int[]
     */
    private function getPath(array $walk, int $from, int $to): ?array
    {
        $ring = [];

        foreach ($walk as $vertex) {
            if (($ring[count($ring) - 1] ?? null) !== $vertex) {
                $ring[] = $vertex;
            }
        }

        if ($ring !== [] && $ring[0] === $ring[count($ring) - 1]) {
            array_pop($ring);
        }

        $start = array_search($from, $ring, true);
        $end = array_search($to, $ring, true);

        if ($start === false || $end === false || $start === $end) {
            return null;
        }

        $count = count($ring);
        $forward = [];
        $back = [];

        for ($step = 0; ; $step++) {
            $forward[] = $ring[($start + $step) % $count];

            if ($ring[($start + $step) % $count] === $to) {
                break;
            }
        }

        for ($step = 0; ; $step++) {
            $back[] = $ring[($start - $step + $count * $count) % $count];

            if ($ring[($start - $step + $count * $count) % $count] === $to) {
                break;
            }
        }

        return count($forward) <= count($back) ? $forward : $back;
    }

    /**
     * Какую палку добавила склейка: ту, что есть в получившихся гранях,
     * но которой не было ни в одной из сшиваемых.
     *
     * @param array<int, int[]> $sources
     * @param array<int, int[]> $produced
     *
     * @return ?array{int, int}
     */
    private function getTieEdge(array $sources, array $produced): ?array
    {
        $had = [];

        foreach ($sources as $walk) {
            foreach (Piece::ofWalk($walk)->edges as [$vertexA, $vertexB]) {
                $had[Scene::edgeName($vertexA, $vertexB)] = true;
            }
        }

        foreach ($produced as $walk) {
            foreach (Piece::ofWalk($walk)->edges as [$vertexA, $vertexB]) {
                if (! isset($had[Scene::edgeName($vertexA, $vertexB)])) {
                    return [$vertexA, $vertexB];
                }
            }
        }

        return null;
    }

    /**
     * Сколько раз каждое ребро ещё предстоит отрезать.
     *
     * Ребро лежит ровно между двумя полями, поэтому его режут дважды:
     * по разу с каждой стороны.
     *
     * @param array<int, array{int, int}> $edges
     * @param Stage[] $stages
     *
     * @return array<string, int>
     */
    private function getCutCount(array $edges, array $stages): array
    {
        $result = [];

        foreach ($edges as [$vertexA, $vertexB]) {
            $result[Scene::edgeName($vertexA, $vertexB)] = 0;
        }

        foreach ($stages as $stage) {
            if ($stage->kind !== StageKind::Field) {
                continue;
            }

            foreach (Piece::ofWalk($stage->highlight)->edges as [$vertexA, $vertexB]) {
                $name = Scene::edgeName($vertexA, $vertexB);
                $result[$name] = ($result[$name] ?? 0) + 1;
            }
        }

        return $result;
    }

    /**
     * Очередь сборки: куски в том порядке, в каком они лягут в укладку.
     *
     * Поле готово, когда алгоритм проложил последнее его ребро: до этого его
     * попросту нечем нарисовать. Считается именно по рёбрам, а не по вершинам:
     * вершины поля бывают уложены задолго до того, как алгоритм доберётся
     * до самого поля, — и тогда порядок получается не тот, в каком укладывали.
     *
     * Готовности мало: укладка растёт от края внутрь, кусок к куску, поэтому
     * следующим берётся тот, который держится за уже уложенное. Иначе первой
     * ляжет перемычка, оба конца которой оказались на внешней грани, —
     * одинокое ребро посреди пустоты. С перемычки не начинают: стол открывает
     * грань, а перемычки пристраиваются к ней.
     *
     * @param Stage[] $stages
     *
     * @return int[]
     */
    private function getAssembly(CutPlan $plan, array $stages): array
    {
        $steps = $this->getEdgeSteps($stages);
        $order = $this->getBuildOrder($stages);
        $last = max(count(array_filter($stages, static fn (Stage $item): bool => $item->kind === StageKind::Build)) - 1, 0);
        $waiting = [];

        foreach ($plan->getState($plan->getLastState())['live'] as $id) {
            $piece = $plan->getCutout($id)->piece;
            $rank = 0;

            foreach ($piece->edges as [$vertexA, $vertexB]) {
                $rank = max($rank, $steps[Scene::edgeName($vertexA, $vertexB)] ?? $plan->getRank($id, $order));
            }

            $waiting[$id] = [
                'rank' => min($piece->edges === [] ? $plan->getRank($id, $order) : $rank, $last),
                'size' => count($piece->vertexes),
                'place' => $plan->getPlace($id),
                'table' => $plan->getTable($id),
                'vertexes' => $piece->vertexes,
            ];
        }

        uasort($waiting, static fn (array $a, array $b): int => [$a['rank'], -$a['size'], $a['place']]
            <=> [$b['rank'], -$b['size'], $b['place']]);
        $result = [];
        $built = [];

        for ($step = 0; $step <= $last; $step++) {
            while (($id = $this->getNextPiece($waiting, $built, $step)) !== null) {
                $result[] = $id;

                foreach ($waiting[$id]['vertexes'] as $vertex) {
                    $built[$waiting[$id]['table']][$vertex] = true;
                }

                unset($waiting[$id]);
            }
        }

        // Если кусок так ни за что и не зацепился, он всё равно должен лечь.
        return array_merge($result, array_keys($waiting));
    }

    /**
     * Какой кусок ложится следующим: тот, что уже готов и держится за
     * уложенное. Пустой стол открывает грань — кусок хотя бы с тремя
     * вершинами, чтобы построение начиналось с края, а не с перемычки.
     *
     * @param array<int, array{rank: int, size: int, place: int, table: int, vertexes: int[]}> $waiting
     * @param array<int, array<int, true>> $built стол => уложенные вершины
     */
    private function getNextPiece(array $waiting, array $built, int $step): ?int
    {
        foreach ($waiting as $id => $piece) {
            if ($piece['rank'] > $step) {
                continue;
            }

            if (! isset($built[$piece['table']])) {
                if ($piece['size'] > 2) {
                    return $id;
                }

                continue;
            }

            foreach ($piece['vertexes'] as $vertex) {
                if (isset($built[$piece['table']][$vertex])) {
                    return $id;
                }
            }
        }

        return null;
    }

    /**
     * Каким по счёту шагом построения ляжет каждое ребро.
     *
     * Шаг построения прокладывает обход — внешнюю грань или дугу от одной
     * уложенной вершины до другой. Рёбра этого обхода и ложатся на этом шаге;
     * связки, добавленные при достройке до двусвязного, в графе не значатся
     * и в счёт не идут.
     *
     * @param Stage[] $stages
     *
     * @return array<string, int>
     */
    private function getEdgeSteps(array $stages): array
    {
        $result = [];
        $step = 0;

        foreach ($stages as $stage) {
            if ($stage->kind !== StageKind::Build) {
                continue;
            }

            $walk = $stage->groups[1] ?? [];
            $count = count($walk);

            for ($number = 1; $number < $count; $number++) {
                $result[Scene::edgeName($walk[$number - 1], $walk[$number])] ??= $step;
            }

            $step++;
        }

        return $result;
    }

    /**
     * Каким по счёту шагом построения ляжет каждая вершина.
     *
     * @param Stage[] $stages
     *
     * @return array<int, int>
     */
    private function getBuildOrder(array $stages): array
    {
        $result = [];
        $step = 0;

        foreach ($stages as $stage) {
            if ($stage->kind !== StageKind::Build) {
                continue;
            }

            foreach ($stage->groups[0] ?? [] as $vertex) {
                $result[$vertex] ??= $step;
            }

            $step++;
        }

        return $result;
    }
}
