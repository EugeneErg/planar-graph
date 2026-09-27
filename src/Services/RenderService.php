<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\Aggregates\Board;
use EugeneErg\Graphs\ValueObjects\Instruction\Contour;
use EugeneErg\Graphs\ValueObjects\Instruction\Straighten;
use EugeneErg\Graphs\ValueObjects\Instruction\Postpone;
use EugeneErg\Graphs\ValueObjects\Instruction\Cover;
use EugeneErg\Graphs\ValueObjects\Instruction\Cut;
use EugeneErg\Graphs\ValueObjects\Instruction\Fill;
use EugeneErg\Graphs\ValueObjects\Instruction\Build;
use EugeneErg\Graphs\ValueObjects\Instruction\Graph;
use EugeneErg\Graphs\ValueObjects\Instruction\Twin;
use EugeneErg\Graphs\ValueObjects\Instruction\Tie;
use EugeneErg\Graphs\ValueObjects\Instruction\Split;
use EugeneErg\Graphs\ValueObjects\Instruction\Open;
use EugeneErg\Graphs\ValueObjects\Instruction\Relax;
use EugeneErg\Graphs\ValueObjects\Instruction\Measure;
use EugeneErg\Graphs\ValueObjects\Instruction\Glue;
use EugeneErg\Graphs\ValueObjects\Instruction\Lock;
use EugeneErg\Graphs\ValueObjects\Instruction\Remain;
use EugeneErg\Graphs\ValueObjects\Instruction\Sides;
use EugeneErg\Graphs\ValueObjects\Layout\Layout;
use EugeneErg\Graphs\ValueObjects\Layout\Packing;
use EugeneErg\Graphs\ValueObjects\Layout\Placement;
use EugeneErg\Graphs\ValueObjects\Point2D;
use EugeneErg\Graphs\ValueObjects\Scene;
use EugeneErg\Graphs\ValueObjects\StageKind;

/**
 * Отрисовка: сколько и когда.
 *
 * Получает результат геометрии — инструкцию, у каждого действия которой
 * известно, где после него что лежит, — и решает, когда что показывать. Дорожки одного шага
 * идут в одном темпе, круг к кругу: круг — подряд идущие вопросы (запереть,
 * залить) и ответ за ними (отрезать). Запирания всех дорожек — одним кадром,
 * заливки — волна к волне, разрезы — одним кадром.
 *
 * Сколько экземпляров, решает доска: вынимаемое переезжает тем же, раздвоенное
 * получает новый. Инструкция проигрывается дважды: первый раз — чтобы узнать
 * все экземпляры и все арены, второй — чтобы собрать кадры. Экземпляр,
 * которому ещё только предстоит родиться, лежит поверх того, от которого
 * раздвоится: разрез виден как расхождение, а не как появление.
 *
 * Где что лежит, решила геометрия: сюда приходят готовые места.
 */
final class RenderService
{
    /** Выделение держится перед действием. */
    private const float MARK_WEIGHT = 0.7;

    /** Короткая пауза перед выделением: иначе оно загорится в тот же миг, как всё тронется. */
    private const float BEAT_WEIGHT = 0.15;

    /** Действие: кусок уезжает. */
    private const float ACT_WEIGHT = 1.0;

    /** Расслабление: рисунок расходится долго и плавно, за ним интересно следить. */
    private const float RELAX_WEIGHT = 4.0;

    /** Волна заливки: за это время краска переползает по ребру. */
    private const float WAVE_WEIGHT = 1.0;

    private Board $board;

    /** @var array<string, array<int, Point2D>> арена => вершина => место сейчас */
    private array $positions = [];

    /** @var array<string, string> арена => вокруг чьей середины разложены её места */
    private array $frames = [];

    private Packing $packing;

    /** @var string[] все экземпляры вершин за весь рассказ */
    private array $instances = [];

    /** @var array<string, string> */
    private array $origins = [];

    /** @var array<string, array{string, string}> новое ребро => концы, с которыми родилось */
    private array $edgeEnds = [];

    /** @var array<string, string> экземпляр ребра => от какого раздвоился */
    private array $edgeOrigins = [];

    /** @var string[] все экземпляры рёбер за весь рассказ */
    private array $edgeInstances = [];

    /** @var array<string, true> связки */
    private array $ties = [];

    /** @var Scene[] */
    private array $scenes = [];

    private bool $recording = false;

    /** @var array<string, int> экземпляр => цвет краски, которой отвечают на вопрос */
    private array $painted = [];

    /**
     * @var array<string, int> экземпляр => цвет его куска. Заливка исходного
     *      графа показывает, из скольких он кусков, и этот вывод держится:
     *      кусок остаётся своего цвета, пока его разбирают.
     */
    private array $parts = [];

    /** @var array<string, true> запертые экземпляры */
    private array $locked = [];

    /** Сколько цветов уже раздали заливкам исходного графа. */
    private int $colors = 0;

    /**
     * @return Scene[]
     */
    public function render(Layout $layout): array
    {
        // Первый проход: узнать все экземпляры.
        $this->run($layout, false);
        $this->origins = $this->board->getOrigins();
        $this->edgeEnds = $this->board->getEdgeEnds();
        $this->edgeOrigins = $this->board->getEdgeOrigins();
        $this->ties = $this->board->getTies();
        $this->instances = [];
        $this->edgeInstances = [];

        foreach ($this->board->getEdges() as $onArena) {
            foreach ($onArena as [$key]) {
                $this->edgeInstances[] = $key;
            }
        }

        foreach ($this->board->getVertexes() as $onArena) {
            array_push($this->instances, ...array_values($onArena));
        }

        array_push($this->instances, ...array_keys($this->origins));
        $this->instances = array_values(array_unique($this->instances));

        // Второй проход: собрать кадры.
        $this->run($layout, true);

        return $this->scenes;
    }

    private function run(Layout $layout, bool $recording): void
    {
        $this->recording = $recording;
        $this->scenes = [];
        $this->painted = [];
        $this->parts = [];
        $this->locked = [];
        $this->colors = 0;
        $this->positions = [];
        $this->frames = [];
        $this->packing = $layout->packing;
        $graph = $layout->placements[0]->action ?? null;
        $this->board = $graph instanceof Graph
            ? new Board($graph->vertexes, $graph->edges)
            : new Board([], []);

        foreach ($layout->placements as $placement) {
            $this->runPlacement($placement);
        }
    }

    private function runPlacement(Placement $placement): void
    {
        if (count($placement->children) === 1) {
            foreach ($placement->children[0]->placements as $child) {
                $this->runPlacement($child);
            }
        } elseif ($placement->children !== []) {
            $this->runLanes($placement);
        }

        $this->runRound([[$placement]]);
    }

    /**
     * Дорожки одного шага — в одном темпе, круг к кругу.
     */
    private function runLanes(Placement $placement): void
    {
        $rounds = [];

        foreach ($placement->children as $lane) {
            $rounds[] = $this->getRounds($lane->placements);
        }

        for ($number = 0; ; $number++) {
            $now = [];

            foreach ($rounds as $list) {
                if (isset($list[$number])) {
                    $now[] = $list[$number];
                }
            }

            if ($now === []) {
                return;
            }

            $this->runRound($now);
        }
    }

    /**
     * Круги дорожки: подряд идущие вопросы и ответ за ними.
     *
     * @param Placement[] $placements
     *
     * @return array<int, Placement[]>
     */
    private function getRounds(array $placements): array
    {
        $result = [];
        $round = [];

        foreach ($this->getFlat($placements) as $placement) {
            $round[] = $placement;

            if (! $placement->action instanceof Lock
                && ! $placement->action instanceof Fill
                && ! $placement->action instanceof Sides
                && ! $placement->action instanceof Measure
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
     * Дорожка без вложенных: разбор отрезанного идёт в той же дорожке,
     * следом за разрезом, в том же темпе.
     *
     * @param Placement[] $placements
     *
     * @return Placement[]
     */
    private function getFlat(array $placements): array
    {
        $result = [];

        foreach ($placements as $placement) {
            if (count($placement->children) === 1) {
                array_push($result, ...$this->getFlat($placement->children[0]->placements));

                continue;
            }

            $result[] = $placement;
        }

        return $result;
    }

    /**
     * Один круг всех дорожек разом.
     *
     * @param array<int, Placement[]> $rounds круг каждой дорожки
     */
    private function runRound(array $rounds): void
    {
        $locks = [];
        $fills = [];
        $sides = [];
        $measures = [];
        $answers = [];

        foreach ($rounds as $round) {
            foreach ($round as $placement) {
                match (true) {
                    $placement->action instanceof Lock => $locks[] = $placement,
                    $placement->action instanceof Fill => $fills[] = $placement,
                    $placement->action instanceof Sides => $sides[] = $placement,
                    $placement->action instanceof Measure => $measures[] = $placement,
                    default => $answers[] = $placement,
                };
            }
        }

        if ($locks !== []) {
            $this->runLocks($locks);
        }

        if ($fills !== []) {
            $this->runFills($fills);
        }

        // Проверка решила: эти куски внутрь не помещаются — они уезжают
        // на внешний круг.
        if ($sides !== []) {
            $before = $this->positions;
            array_walk($sides, $this->move(...));

            // Внутреннее въезжает в многоугольник. Если внутри пусто, ехать
            // нечему: всё висящее и так лежит снаружи, как остаток.
            if ($before != $this->positions) {
                $this->record(StageKind::Outside, self::ACT_WEIGHT);
            }
        }

        // Что осталось — контур: ложится многоугольником по своему обходу.
        // Это тоже ответ, и если в этом круге режут, он ложится тем же
        // кадром, что и разрез, а не вклинивается между выделением и ним.
        $contours = array_values(array_filter(
            $answers,
            static fn (Placement $placement): bool => $placement->action instanceof Contour,
        ));

        foreach ($answers as $placement) {
            if ($placement->action instanceof Graph) {
                $this->move($placement);
                $this->record(StageKind::Graph, self::ACT_WEIGHT);
            }
        }

        $cuts = array_values(array_filter(
            $answers,
            static fn (Placement $placement): bool => $placement->action instanceof Cut,
        ));

        if ($cuts !== []) {
            $this->runCuts($cuts, $contours);
        } elseif ($contours !== []) {
            array_walk($contours, $this->move(...));
            $this->record(StageKind::Field, self::ACT_WEIGHT);
        }

        $this->runGlue($answers, $measures);

        $postpones = array_values(array_filter(
            $answers,
            static fn (Placement $placement): bool => $placement->action instanceof Postpone,
        ));

        if ($postpones !== []) {
            $this->runBuilds($postpones, StageKind::Postpone);
        }

        $builds = array_values(array_filter(
            $answers,
            static fn (Placement $placement): bool => $placement->action instanceof Build || $placement->action instanceof Cover,
        ));

        if ($builds !== []) {
            $this->runBuilds($builds, StageKind::Build);
        }

        $straightens = array_values(array_filter(
            $answers,
            static fn (Placement $placement): bool => $placement->action instanceof Straighten,
        ));

        if ($straightens !== []) {
            array_walk($straightens, $this->move(...));
            $this->record(StageKind::Relax, self::RELAX_WEIGHT);
        }

        $relaxes = array_values(array_filter(
            $answers,
            static fn (Placement $placement): bool => $placement->action instanceof Relax,
        ));

        if ($relaxes !== []) {
            array_walk($relaxes, $this->move(...));
            $this->record(StageKind::Relax, self::RELAX_WEIGHT);
        }

        foreach ($answers as $placement) {
            if ($placement->action instanceof Remain) {
                $this->board->rename($placement->arena, $placement->action->arena);
                unset($this->positions[$placement->arena], $this->frames[$placement->arena]);
                $this->move($placement);
            }
        }
    }

    /**
     * Склейка, действие за действием; дорожки — в одном темпе.
     *
     * Сначала видно, кого сшивают: кольцо загорается на всех копиях точки
     * сочленения — это та же точка, по которой ветвь когда-то отрезали.
     * Потом — какие поля: два поля с этой точкой, по одному с каждой
     * стороны, загораются целиком. И сразу за этим они съезжаются.
     *
     * @param Placement[] $answers
     * @param Placement[] $measures
     */
    private function runGlue(array $answers, array $measures): void
    {
        $of = static fn (string $class): array => array_values(array_filter(
            $answers,
            static fn (Placement $placement): bool => $placement->action instanceof $class,
        ));

        $twins = $of(Twin::class);

        foreach ($twins as $placement) {
            if ($placement->action instanceof Twin) {
                $this->board->twin($placement->action->arena, $placement->action->copy);
                $this->move($placement);
            }
        }

        if ($twins !== []) {
            $this->record(StageKind::Tie, self::ACT_WEIGHT);
        }

        $glues = $of(Glue::class);

        if ($glues !== []) {
            $rings = [];
            $fields = [];

            foreach ($glues as $placement) {
                $glue = $placement->action;

                if (! $glue instanceof Glue) {
                    continue;
                }

                foreach ($this->board->getVertexes() as $onArena) {
                    if (isset($onArena[$glue->vertex])) {
                        $rings[$onArena[$glue->vertex]] = true;
                    }
                }

                foreach ([$glue->arena, $glue->other] as $arena) {
                    foreach ($this->board->getVertexes()[$arena] ?? [] as $key) {
                        $fields[$key] = true;
                    }

                    foreach ($this->board->getEdges()[$arena] ?? [] as [$key]) {
                        $fields[$key] = true;
                    }
                }
            }

            $this->record(StageKind::Tie, self::BEAT_WEIGHT, $rings);
            $this->record(StageKind::Tie, self::MARK_WEIGHT, $rings);
            $this->record(StageKind::Tie, self::MARK_WEIGHT, $fields);
            array_walk($glues, $this->move(...));
            $this->record(StageKind::Tie, self::ACT_WEIGHT);
        }

        $opens = $of(Open::class);

        foreach ($opens as $placement) {
            if ($placement->action instanceof Open) {
                $open = $placement->action;
                $this->board->open($open->vertex, $open->walk, $open->arena, $open->other);
                $this->move($placement);
            }
        }

        if ($opens !== []) {
            $this->record(StageKind::Tie, self::ACT_WEIGHT);
        }

        if ($measures !== []) {
            $this->runMeasures($measures);
        }

        $ties = $of(Tie::class);

        foreach ($ties as $placement) {
            if ($placement->action instanceof Tie) {
                $tie = $placement->action;
                $this->board->tie($tie->vertexA, $tie->vertexB, $tie->arena, $tie->other);
            }
        }

        if ($ties !== []) {
            $this->record(StageKind::Tie, self::ACT_WEIGHT);
        }

        $splits = $of(Split::class);

        foreach ($splits as $placement) {
            if ($placement->action instanceof Split) {
                $split = $placement->action;
                $this->board->split($split->vertex, $split->first, $split->arena, $split->second, $split->other);
                $this->move($placement);
            }
        }

        if ($splits !== []) {
            $this->painted = [];
            $this->record(StageKind::Tie, self::ACT_WEIGHT);
        }
    }

    /**
     * Сборка: поле загорается целиком и ложится в укладку — или, если лечь
     * не может, отходит ждать к краю. Несколько полей, легших вместе,
     * загораются вместе.
     *
     * @param Placement[] $builds
     */
    private function runBuilds(array $builds, StageKind $kind): void
    {
        $fields = [];

        foreach ($builds as $placement) {
            $action = $placement->action;
            $arenas = match (true) {
                $action instanceof Build, $action instanceof Postpone => [$action->arena],
                $action instanceof Cover => array_map(strval(...), array_keys($action->fields)),
                default => [],
            };

            foreach ($arenas as $arena) {
                foreach ($this->board->getVertexes()[$arena] ?? [] as $key) {
                    $fields[$key] = true;
                }

                foreach ($this->board->getEdges()[$arena] ?? [] as [$key]) {
                    $fields[$key] = true;
                }
            }
        }

        $this->record($kind, self::BEAT_WEIGHT, $fields);
        $this->record($kind, self::MARK_WEIGHT, $fields);
        array_walk($builds, $this->move(...));
        $this->record($kind, self::ACT_WEIGHT);
    }

    /**
     * Отмер: краска ползёт от точки склейки по обоим полям, по вершине за
     * волну, и останавливается там, где ляжет связка.
     *
     * @param Placement[] $measures
     */
    private function runMeasures(array $measures): void
    {
        $length = 0;

        foreach ($measures as $placement) {
            if ($placement->action instanceof Measure) {
                $length = max($length, count($placement->action->first), count($placement->action->second));
            }
        }

        $this->painted = [];

        for ($wave = 0; $wave < $length; $wave++) {
            $flows = [];

            foreach ($measures as $placement) {
                $measure = $placement->action;

                if (! $measure instanceof Measure) {
                    continue;
                }

                $keys = ($this->board->getVertexes()[$measure->other] ?? []) + ($this->board->getVertexes()[$measure->arena] ?? []);
                $keys[$measure->vertex] = $this->board->getVertexes()[$measure->arena][$measure->vertex] ?? '';
                $lines = [];

                foreach ([$measure->arena, $measure->other] as $arena) {
                    foreach ($this->board->getEdges()[$arena] ?? [] as [$key, $keyA, $keyB]) {
                        $lines[$keyA . '|' . $keyB] = $key;
                        $lines[$keyB . '|' . $keyA] = $key;
                    }
                }

                foreach ([$measure->first, $measure->second] as $path) {
                    if (! isset($path[$wave], $keys[$path[$wave]])) {
                        continue;
                    }

                    $this->painted[$keys[$path[$wave]]] = 1;

                    if ($wave > 0 && isset($keys[$path[$wave - 1]])) {
                        $line = $lines[$keys[$path[$wave - 1]] . '|' . $keys[$path[$wave]]] ?? null;

                        if ($line !== null) {
                            $flows[$line] = [$keys[$path[$wave - 1]], 1];
                        }
                    }
                }
            }

            $this->record(StageKind::Tie, self::WAVE_WEIGHT, flows: $flows);
        }
    }

    /**
     * Принять места, которые геометрия дала этому действию.
     */
    private function move(Placement $placement): void
    {
        foreach ($placement->places as $arena => $places) {
            $this->positions[$arena] = $places;
            $this->frames[$arena] = $placement->frames[$arena] ?? (string) $arena;
        }
    }

    /**
     * Запереть: краска через эти шары не пройдёт.
     *
     * @param Placement[] $locks
     */
    private function runLocks(array $locks): void
    {
        // Новое запирание — новый вопрос: прежняя краска к нему отношения
        // не имеет.
        $this->painted = [];
        $this->locked = [];

        foreach ($locks as $placement) {
            $lock = $placement->action;

            if (! $lock instanceof Lock) {
                continue;
            }

            foreach ($lock->vertexes as $vertex) {
                $key = $this->board->getVertexes()[$placement->arena][$vertex] ?? null;

                if ($key !== null) {
                    $this->locked[$key] = true;
                }
            }

            $this->move($placement);
        }

        $this->record(StageKind::Block, self::MARK_WEIGHT);
    }

    /**
     * Заливки всех дорожек — волна к волне. Краска переползает по ребру
     * от закрашенной вершины к следующей.
     *
     * @param Placement[] $fills
     */
    private function runFills(array $fills): void
    {
        $colors = [];
        $length = 0;
        $items = [];

        foreach ($fills as $placement) {
            if ($placement->action instanceof Fill) {
                $items[] = [$placement->arena, $placement->action];
            }
        }

        foreach ($items as $number => [$arena, $fill]) {
            // Куски исходного графа красятся каждый своим цветом: по нему
            // и видно, что их несколько. Дальше заливка отвечает на вопрос
            // про запертое, и цвет у неё один.
            // Первый цвет — краске вопроса, куски получают следующие: иначе
            // ответ на вопрос сольётся с цветом первого куска.
            $colors[$number] = $arena === Board::ROOT ? ++$this->colors + 1 : 1;
            $length = max($length, count($fill->waves) + 1);
        }

        for ($wave = 0; $wave < $length; $wave++) {
            $flows = [];

            foreach ($items as $number => [$arena, $fill]) {
                $keys = $this->board->getVertexes()[$arena] ?? [];
                $now = $wave === 0 ? [$fill->from] : ($fill->waves[$wave - 1] ?? []);
                $before = $wave === 0 ? [] : ($wave === 1 ? [$fill->from] : ($fill->waves[$wave - 2] ?? []));

                foreach ($now as $vertex) {
                    if (! isset($keys[$vertex])) {
                        continue;
                    }

                    $this->painted[$keys[$vertex]] = $colors[$number];

                    if ($arena === Board::ROOT) {
                        $this->parts[$keys[$vertex]] = $colors[$number];
                    }

                    foreach ($before as $source) {
                        $edge = $this->board->getEdges()[$arena][Scene::edgeName($vertex, $source)] ?? null;

                        if ($edge !== null && isset($keys[$source])) {
                            $flows[$edge[0]] = [$keys[$source], $colors[$number]];

                            break;
                        }
                    }
                }
            }

            $this->record(StageKind::Fill, self::WAVE_WEIGHT, flows: $flows);
        }
    }

    /**
     * Отрезать: сначала выделяется то, что уедет, потом оно уезжает.
     *
     * @param Placement[] $cuts
     * @param Placement[] $along другие ответы этого круга: ложатся тем же кадром
     */
    private function runCuts(array $cuts, array $along): void
    {
        $highlight = [];
        $kind = StageKind::Components;

        foreach ($cuts as $placement) {
            $cut = $placement->action;

            if (! $cut instanceof Cut) {
                continue;
            }

            $keys = $this->board->getVertexes()[$placement->arena] ?? [];

            foreach (array_merge($cut->vertexes, $cut->twinVertexes) as $vertex) {
                if (isset($keys[$vertex])) {
                    $highlight[$keys[$vertex]] = true;
                }
            }

            $kind = match (true) {
                $cut->walk !== null => StageKind::Field,
                $cut->twinVertexes !== [] => StageKind::Branches,
                default => StageKind::Components,
            };
        }

        $this->record($kind, self::BEAT_WEIGHT, $highlight);
        $this->record($kind, self::MARK_WEIGHT, $highlight);

        foreach ($cuts as $placement) {
            if ($placement->action instanceof Cut) {
                $this->board->cut($placement->arena, $placement->action);
                $this->move($placement);
            }
        }

        array_walk($along, $this->move(...));

        // Разрез снимает запор и краску вопроса: вопрос, ради которого
        // запирали и заливали, решён.
        $this->locked = [];
        $this->painted = [];
        $this->record($kind, self::ACT_WEIGHT);
    }

    /**
     * Насколько каждая середина съехала: кольца подгоняются под то, что
     * на досках лежит прямо сейчас (где что лежит, решает геометрия — здесь
     * только известно, что лежит в этот момент).
     *
     * @return array<string, array{float, float}>
     */
    private function getShifts(): array
    {
        $extents = [];
        $centers = $this->packing->centers;

        foreach ($this->board->getVertexes() as $arena => $onArena) {
            $frame = $this->frames[$arena] ?? (string) $arena;
            $center = $centers[$frame] ?? new Point2D();
            $key = $this->packing->resolve($frame);

            foreach (array_keys($onArena) as $vertex) {
                if (isset($this->positions[$arena][$vertex])) {
                    $point = $this->positions[$arena][$vertex];
                    $extents[$key] = max($extents[$key] ?? .0, hypot($point->x - $center->x, $point->y - $center->y));
                }
            }
        }

        $placed = $this->packing->place($extents);
        $result = [];

        foreach ($this->board->getVertexes() as $arena => $onArena) {
            $frame = $this->frames[$arena] ?? (string) $arena;
            $center = $centers[$frame] ?? new Point2D();
            $point = $placed[$this->packing->resolve($frame)] ?? $center;
            $result[(string) $arena] = [$point->x - $center->x, $point->y - $center->y];
        }


        return $result;
    }

    /**
     * Снять кадр с доски.
     *
     * @param array<string, true> $highlight
     * @param array<string, array{string, int}> $flows
     */
    private function record(StageKind $kind, float $weight, array $highlight = [], array $flows = []): void
    {
        if (! $this->recording) {
            return;
        }

        $vertexes = [];
        $shifts = $this->getShifts();

        foreach ($this->board->getVertexes() as $arena => $onArena) {
            [$dx, $dy] = $shifts[(string) $arena] ?? [.0, .0];

            foreach ($onArena as $vertex => $key) {
                if (isset($this->positions[$arena][$vertex])) {
                    $point = $this->positions[$arena][$vertex];
                    $vertexes[$key] = new Point2D($point->x + $dx, $point->y + $dy);
                }
            }
        }

        // Ещё не родившиеся экземпляры лежат поверх тех, от кого раздвоятся.
        foreach ($this->instances as $key) {
            $origin = $key;

            while (! isset($vertexes[$origin]) && isset($this->origins[$origin])) {
                $origin = $this->origins[$origin];
            }

            if (isset($vertexes[$origin])) {
                $vertexes[$key] ??= $vertexes[$origin];
            }
        }

        // Рёбра — экземпляр и экземпляры концов. Ещё не родившееся ребро
        // лежит поверх того, от которого раздвоится; связка до своего
        // рождения схлопнута в точку на своём конце.
        $lines = [];

        foreach ($this->board->getEdges() as $onArena) {
            foreach ($onArena as [$key, $keyA, $keyB]) {
                $lines[$key] = [$keyA, $keyB];
            }
        }

        $live = $lines;

        foreach ($this->edgeInstances as $key) {
            $origin = $key;

            while (! isset($live[$origin]) && ! isset($this->edgeEnds[$origin]) && isset($this->edgeOrigins[$origin])) {
                $origin = $this->edgeOrigins[$origin];
            }

            $line = $live[$origin] ?? $this->edgeEnds[$origin] ?? null;

            if ($line !== null) {
                $lines[$key] ??= $line;
            }
        }

        $edges = [];

        foreach ($lines as $key => [$keyA, $keyB]) {
            if (isset($vertexes[$keyA], $vertexes[$keyB])) {
                $edges[$key] = [$vertexes[$keyA], $vertexes[$keyB]];
            }
        }

        // Цвет куска снизу, краска вопроса поверх. Раздвоенный шар — того же
        // куска, что и шар, от которого он раздвоился.
        $colors = [];

        foreach (array_keys($vertexes) as $key) {
            $origin = $key;

            while (! isset($this->parts[$origin]) && isset($this->origins[$origin])) {
                $origin = $this->origins[$origin];
            }

            if (isset($this->parts[$origin])) {
                $colors[$key] = $this->parts[$origin];
            }
        }

        $colors = $this->painted + $colors;
        $groups = $colors;

        // Ребро красится вместе с концами: по нему краска и прошла.
        foreach ($lines as $key => [$keyA, $keyB]) {
            $colorA = $colors[$keyA] ?? null;

            if ($colorA !== null && $colorA === ($colors[$keyB] ?? null)) {
                $groups[$key] = $colorA;
            }
        }

        $this->scenes[] = new Scene(
            vertexes: $vertexes,
            edges: $edges,
            groups: $groups,
            highlight: $highlight,
            weight: $weight,
            kind: $kind,
            flows: $flows,
            ties: $this->ties,
            blocked: $this->locked,
        );
    }
}
