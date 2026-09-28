<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\ValueObjects\Instruction\ActionInterface;
use EugeneErg\Graphs\ValueObjects\Instruction\Contour;
use EugeneErg\Graphs\ValueObjects\Instruction\Straighten;
use EugeneErg\Graphs\ValueObjects\Instruction\Postpone;
use EugeneErg\Graphs\ValueObjects\Instruction\Cover;
use EugeneErg\Graphs\ValueObjects\Instruction\Cut;
use EugeneErg\Graphs\ValueObjects\Instruction\Lock;
use EugeneErg\Graphs\ValueObjects\Instruction\Build;
use EugeneErg\Graphs\ValueObjects\Instruction\Graph;
use EugeneErg\Graphs\ValueObjects\Instruction\Twin;
use EugeneErg\Graphs\ValueObjects\Instruction\Split;
use EugeneErg\Graphs\ValueObjects\Instruction\Open;
use EugeneErg\Graphs\ValueObjects\Instruction\Relax;
use EugeneErg\Graphs\ValueObjects\Instruction\Glue;
use EugeneErg\Graphs\ValueObjects\Instruction\Instruction;
use EugeneErg\Graphs\ValueObjects\Instruction\Remain;
use EugeneErg\Graphs\ValueObjects\Instruction\Sides;
use EugeneErg\Graphs\ValueObjects\Instruction\Step;
use EugeneErg\Graphs\ValueObjects\Layout\Layout;
use EugeneErg\Graphs\ValueObjects\Layout\Packing;
use EugeneErg\Graphs\ValueObjects\Layout\Placement;
use EugeneErg\Graphs\ValueObjects\Layout\PlacementLane;
use EugeneErg\Graphs\ValueObjects\Point2D;

/**
 * Геометрия: где что лежит. Больше ничего.
 *
 * Арена — место, на котором лежит кусок графа. Отрезанное уходит на свою
 * арену, и арены детей стоят кольцом вокруг арены родителя: несвязные куски
 * вокруг исходного графа, ветви вокруг своего куска, поля вокруг своей ветви.
 * Кольцо подбирается так, чтобы поместились все дети вместе со своими
 * собственными кольцами, а порядок на кольце — тот, в каком их вершины
 * лежали у родителя: так дети разъезжаются наружу и не пересекают друг друга.
 *
 * Внутри арены вершины лежат ровным кругом в порядке номеров — это тот же
 * порядок, что у клубка, — а круг считается по тому, что на арене сейчас
 * осталось: после каждого разреза оставшиеся расходятся равномерно. Поле
 * лежит в порядке своего обхода — тогда оно и выглядит многоугольником.
 *
 * Пока ищут поле, запертый обход лежит многоугольником посередине, а всё
 * висящее — вокруг него, за теми сторонами, которые не лежат на контуре
 * ветви. Когда алгоритм разложил куски по сторонам, внутренние въезжают
 * в многоугольник, и отрезается он вместе с ними: одна сторона уходит,
 * другая остаётся.
 *
 * Единица длины — расстояние между соседями на круге. Настоящий масштаб
 * выберет отрисовка.
 *
 * Читает инструкцию и выдаёт её же, но у каждого действия — где после него
 * что лежит. Сколько экземпляров и когда что показывать — уже не здесь.
 */
final class ArenaGeometry
{
    /** Зазор между детьми на кольце, в расстояниях между соседями. */
    /** Шаг между соседями, когда кусок лежит кругом: в нём меряется всё остальное. */
    public const float UNIT = 1.0;

    private const float GAP = 1.5;

    /** Место, куда отходит поле с середины куска, пока собирается укладка. */
    private const string ASIDE = '~';

    /** Корень: арена исходного графа. */
    private const string ROOT = '';

    /**
     * Просвет между кругами, пока ищут поле: от того, что внутри, до сторон
     * запертого многоугольника и от запертого обхода до вынесенного.
     */
    private const float CLEARANCE = 0.75;

    /** @var array<string, int[]> арена => что на ней сейчас лежит */
    private array $arenas = [];

    /** @var array<string, string> арена => откуда её отрезали */
    private array $parents = [];

    /** @var array<string, int> арена => сколько вершин на ней бывало разом */
    private array $sizes = [];

    /** @var array<string, int[]> арена => с чем она родилась */
    private array $born = [];

    /** @var array<string, Point2D> */
    private array $centers = [];

    /** @var array<string, float> арена => как далеко от середины на ней что-то бывало */
    private array $reach = [];

    /**
     * @var array<string, float> арена => как далеко от середины на ней что-то
     *      бывало с тех пор, как от неё отрезали первого ребёнка
     */
    private array $reachAfterCut = [];

    /** @var array<string, true> арены, от которых уже что-то отрезали */
    private array $hasChild = [];

    /** @var array<string, array<int, Point2D>> арена => где её вершины лежат сейчас */
    private array $last = [];

    /** @var array<int, Point2D> укладка, которую построил алгоритм */
    private array $drawing = [];

    /** @var array<int, Point2D> та же укладка, выпрямленная */
    private array $straight = [];

    /** @var array<int, Point2D> та же укладка, расслабленная */
    private array $relaxed = [];

    /** @var array<string, string[]> кусок => арены его полей */
    private array $fields = [];

    /** @var array<string, array<string, float>> арена => ребёнок => направление от родителя */
    private array $rings = [];

    /** @var array<string, string> прежнее имя арены => новое */
    private array $aliases = [];

    /** @var array<string, float> кусок => во сколько раз укладка алгоритма уменьшена на столе */
    private array $fitted = [];

    /** @var array<string, string> кусок => поле, с которого начинается сборка */
    private array $firsts = [];

    /** @var array<string, int[]> кусок => его внешняя грань */
    private array $firstWalks = [];

    /** @var array<string, array{Point2D, float}> кусок => середина его укладки у алгоритма и масштаб */
    private array $frames = [];

    /** @var array<string, int[]> арена => обход, которым лежат её вершины */
    private array $orders = [];

    /** @var array<string, int[]> арена => запертый обход, пока ищут поле */
    private array $locks = [];

    /** @var array<string, Sides> арена => по какую сторону запертого обхода что легло */
    private array $sides = [];

    /** @var array<string, array<string, array{int, int}>> арена => её рёбра */
    private array $edges = [];

    /**
     * @var array<string, array<string, true>> арена ветви => рёбра её контура.
     *      За ними ничего нет: контур — граница всей ветви.
     */
    private array $closed = [];

    /** @var array<string, int[]|null> арена => обход, который запрут на ней следующим */
    private array $upcoming = [];

    /** @var array<string, int[]> арена => её контур по порядку */
    private array $contours = [];

    /** @var array<string, array<int, Point2D>|null> уже найденные раскладки висящего */
    private array $layered = [];

    public function __construct(
        private readonly PlaceService $place = new PlaceService(),
        private readonly GeometryService $geometry = new GeometryService(),
    ) {
    }

    /**
     * Инструкция с местами.
     *
     * Проходится дважды: первый раз — чтобы узнать все арены и их размеры
     * (места на кольце раздаются один раз и больше не меняются), второй —
     * чтобы после каждого действия записать, где что лежит.
     */
    /**
     * @param array<int, Point2D> $drawing укладка алгоритма из дуг: где он
     *                                     поставил каждую вершину; в неё и собирают
     * @param array<int, Point2D> $straight она же выпрямленная
     * @param array<int, Point2D> $relaxed она же после расслабления
     */
    public function layout(Instruction $instruction, array $drawing = [], array $straight = [], array $relaxed = []): Layout
    {
        $this->drawing = $drawing;
        $this->straight = $straight === [] ? $drawing : $straight;
        $this->relaxed = $relaxed === [] ? $this->straight : $relaxed;
        $this->fields = [];
        $this->firsts = [];
        $this->firstWalks = [];
        $this->fitted = [];
        $this->frames = [];
        $this->closed = [];
        $this->contours = [];
        $this->layered = [];
        foreach ($instruction->steps as $step) {
            $this->collectContours($step);
            $this->collectFrames($step);
        }

        $this->walk($instruction);

        // Середина куска нужна под сборку. Если там лежит поле, которое ляжет
        // не первым, ему заранее отводится место на кольце: сборка
        // начинается — оно отходит, чтобы укладка росла не поверх него.
        foreach ($this->firsts as $part => $first) {
            if ($first !== $part && isset($this->arenas[$part])) {
                $this->parents[$part . self::ASIDE] = $part;
                $this->sizes[$part . self::ASIDE] = count($this->arenas[$part]);
                $this->born[$part . self::ASIDE] = $this->arenas[$part];
            }
        }

        $this->rings = [];
        $this->centers = $this->getCenters($this->parents, $this->sizes, $this->born);

        return new Layout($this->walk($instruction), new Packing($this->centers, $this->rings, self::GAP, $this->aliases));
    }

    /**
     * @return Placement[]
     */
    private function walk(Instruction $instruction): array
    {
        $graph = $instruction->steps[0]->action ?? null;
        $this->arenas = [self::ROOT => $graph instanceof Graph ? $graph->vertexes : []];
        $this->parents = [];
        $this->sizes = [self::ROOT => count($this->arenas[self::ROOT])];
        $this->born = [self::ROOT => $this->arenas[self::ROOT]];
        $this->orders = [];
        $this->locks = [];
        $this->sides = [];
        $this->edges = [self::ROOT => []];

        foreach ($graph instanceof Graph ? $graph->edges : [] as $edge) {
            $this->edges[self::ROOT][self::edgeName(...$edge)] = $edge;
        }

        return array_map(fn (Step $step): Placement => $this->placeStep($step, self::ROOT), $instruction->steps);
    }

    private function placeStep(Step $step, string $arena): Placement
    {
        $lanes = [];

        foreach ($step->children as $lane) {
            $placed = [];

            foreach ($lane->steps as $number => $child) {
                $this->upcoming[$lane->arena] = $this->getUpcomingLock(array_slice($lane->steps, $number + 1));
                $placed[] = $this->placeStep($child, $lane->arena);
            }

            $lanes[] = new PlacementLane($lane->arena, $placed);
        }

        $action = $step->action;
        $touched = [$arena];

        if ($action instanceof Lock && $action->walk) {
            $this->locks[$arena] = $this->orient($action->vertexes);
            unset($this->sides[$arena]);
        }

        if ($action instanceof Sides) {
            $this->sides[$arena] = $action;
        }

        if ($action instanceof Contour) {
            $this->orders[$arena] = $this->orient($action->walk);
        }

        if ($action instanceof Cut) {
            // Ответ получен: этот вопрос снят. Если следом на той же арене
            // будет следующий, остаток сразу ложится под него: одни
            // запертые вершины сменяются другими, а не «домой и обратно» —
            // иначе арена гармошкой сжимается и снова разжимается.
            unset($this->locks[$arena], $this->sides[$arena]);

            if (($this->upcoming[$arena] ?? null) !== null) {
                $this->locks[$arena] = $this->orient($this->upcoming[$arena]);
            }

            if ($action->walk !== null) {
                $this->orders[$action->arena] = $this->orient($action->walk);
            }

            $this->arenas[$arena] = array_values(array_diff($this->arenas[$arena] ?? [], $action->vertexes));
            $this->arenas[$action->arena] = array_values(array_unique(array_merge($action->vertexes, $action->twinVertexes)));
            $this->parents[$action->arena] = $arena;
            $this->hasChild[$arena] = true;
            $this->born[$action->arena] = $this->arenas[$action->arena];
            $this->sizes[$action->arena] = count($this->arenas[$action->arena]);
            $this->edges[$action->arena] = [];

            foreach ($action->edges as $edge) {
                unset($this->edges[$arena][self::edgeName(...$edge)]);
                $this->edges[$action->arena][self::edgeName(...$edge)] = $edge;
            }

            foreach ($action->twinEdges as $edge) {
                $this->edges[$action->arena][self::edgeName(...$edge)] = $edge;
            }

            $touched[] = $action->arena;
        }

        if ($action instanceof Remain && $action->arena !== $arena) {
            $this->rename($arena, $action->arena);
            $touched = [$action->arena];
        }

        if ($action instanceof Twin) {
            $this->arenas[$action->copy] = $this->arenas[$action->arena] ?? [];
            $this->parents[$action->copy] = $action->arena;
            $this->hasChild[$action->arena] = true;
            $this->born[$action->copy] = $this->arenas[$action->copy];
            $this->sizes[$action->copy] = count($this->arenas[$action->copy]);
            $this->edges[$action->copy] = $this->edges[$action->arena] ?? [];

            if (isset($this->orders[$action->arena])) {
                $this->orders[$action->copy] = $this->orders[$action->arena];
            }

            $touched = [$action->copy];
        }

        if ($action instanceof Split) {
            $this->arenas[$action->arena] = array_values(array_unique($action->first));
            $this->arenas[$action->other] = array_values(array_unique($action->second));
            $this->orders[$action->arena] = $this->orient($action->first);
            $this->orders[$action->other] = $this->orient($action->second);
            $touched = [$action->arena, $action->other];
        }

        $places = match (true) {
            $action instanceof Glue => $this->getGluePlaces($action),
            $action instanceof Open => $this->getOpenPlaces($action),
            $action instanceof Build => $this->getBuildPlaces($action, $arena),
            $action instanceof Relax => $this->getFramePlaces($arena, $this->relaxed),
            $action instanceof Straighten => $this->getFramePlaces($arena, $this->straight),
            $action instanceof Cover => $this->getCoverPlaces($action, $arena),
            // Отложенное поле никуда не едет: оно просто выделяется там, где
            // лежит, — в буфере.
            $action instanceof Postpone => [],
            default => null,
        };

        if ($places === null) {
            $places = [];

            foreach ($touched as $name) {
                $places[$name] = $this->getArenaPlaces($name);
            }
        }

        foreach ($places as $name => $points) {
            $this->last[$name] = $points;
        }

        return new Placement($action, $arena, $places, $lanes, $this->getFrames($action, $arena, $places));
    }

    /**
     * Вокруг чьей середины разложены места, если не вокруг своей.
     *
     * @param array<string, array<int, Point2D>> $places
     *
     * @return array<string, string>
     */
    private function getFrames(ActionInterface $action, string $arena, array $places): array
    {
        $result = [];

        foreach (array_keys($places) as $name) {
            $frame = match (true) {
                $action instanceof Build => (string) $name === $action->arena ? $arena : $arena . self::ASIDE,
                $action instanceof Relax,
                $action instanceof Straighten,
                $action instanceof Cover => $arena,
                $action instanceof Glue => $action->arena,
                $action instanceof Open => $action->arena,
                default => (string) $name,
            };

            if ($frame !== (string) $name) {
                $result[(string) $name] = $frame;
            }
        }

        return $result;
    }

    /**
     * Обход, который запрут следующим, если до него на арене ничего, кроме
     * разбора отрезанного, не происходит.
     *
     * @param Step[] $steps что идёт на дорожке дальше
     *
     * @return int[]|null
     */
    private function getUpcomingLock(array $steps): ?array
    {
        foreach ($steps as $step) {
            if ($step->action instanceof Lock && $step->action->walk) {
                return $step->action->vertexes;
            }

            if ($step->children === []) {
                return null;
            }
        }

        return null;
    }

    /**
     * Остаток становится последней частью: у арены просто новое имя.
     */
    private function rename(string $from, string $to): void
    {
        // Клубок, от которого отделились несвязные куски, — не кусок, а стол.
        // Последний кусок получает своё имя и ложится на стол рядом с
        // остальными, а не становится их родителем: иначе чужие куски
        // лежали бы на одном кольце с его собственными полями и ветвями.
        if ($from === self::ROOT) {
            $this->arenas[$to] = $this->arenas[$from];
            $this->sizes[$to] = $this->sizes[$from];
            $this->born[$to] = $this->born[$from];
            $this->edges[$to] = $this->edges[$from] ?? [];
            $this->parents[$to] = $from;
            $this->arenas[$from] = [];

            return;
        }

        $this->aliases[$from] = $to;
        $this->arenas[$to] = $this->arenas[$from];
        $this->sizes[$to] = $this->sizes[$from];
        $this->born[$to] = $this->born[$from];
        $this->reach[$to] = max($this->reach[$to] ?? .0, $this->reach[$from] ?? .0);

        if (isset($this->reachAfterCut[$from])) {
            $this->reachAfterCut[$to] = max($this->reachAfterCut[$to] ?? .0, $this->reachAfterCut[$from]);
        }

        if (isset($this->hasChild[$from])) {
            $this->hasChild[$to] = true;
        }
        $this->edges[$to] = $this->edges[$from] ?? [];

        if (isset($this->parents[$from])) {
            $this->parents[$to] = $this->parents[$from];
        }

        foreach ($this->parents as $arena => $parent) {
            if ($parent === $from) {
                $this->parents[$arena] = $to;
            }
        }

        unset($this->arenas[$from], $this->sizes[$from], $this->born[$from], $this->parents[$from], $this->reach[$from], $this->edges[$from]);
    }

    /**
     * Вершины арены ровным кругом, в порядке номеров, по тому, что на ней
     * сейчас лежит. Пока ищут поле — тремя кругами.
     *
     * @return array<int, Point2D>
     */
    private function getArenaPlaces(string $arena): array
    {
        $present = $this->arenas[$arena] ?? [];
        $sorted = $present;
        sort($sorted);
        $order = array_values(array_unique(array_merge(
            array_values(array_intersect($this->orders[$arena] ?? [], $present)),
            $sorted,
        )));
        $center = $this->centers[$arena] ?? new Point2D();
        $walk = array_values(array_intersect($this->locks[$arena] ?? [], $present));
        $result = $walk === []
            ? $this->place->getWheel($order, $center, $this->place->getPieceRadius(count($order), self::UNIT))
            : $this->getQuestionPlaces($arena, $order, $walk, $center);

        foreach ($result as $point) {
            $distance = hypot($point->x - $center->x, $point->y - $center->y);
            $this->reach[$arena] = max($this->reach[$arena] ?? .0, $distance);

            if (isset($this->hasChild[$arena])) {
                $this->reachAfterCut[$arena] = max($this->reachAfterCut[$arena] ?? .0, $distance);
            }
        }

        return $result;
    }

    /**
     * Поиск поля. Запертый обход — многоугольником посередине: про него
     * и спрашивают. Всё, что на нём висит, — вокруг, снаружи: пока не решено
     * иначе, это остаток. Когда алгоритм разложил куски по сторонам,
     * внутренние въезжают в многоугольник — настолько тесно, чтобы лечь
     * внутри его сторон, — а внешние остаются, где были. Потом
     * многоугольник вместе с содержимым отрезают.
     *
     * @param int[] $order
     * @param int[] $walk
     *
     * @return array<int, Point2D>
     */
    private function getQuestionPlaces(string $arena, array $order, array $walk, Point2D $center): array
    {
        $home = $this->place->getPieceRadius(count($order), self::UNIT);
        $homes = $this->place->getWheel($order, $center, $home);
        $inside = array_values(array_intersect($order, isset($this->sides[$arena]) ? $this->sides[$arena]->insideVertexes() : []));
        // Три круга — три независимых правильных многоугольника, у каждого
        // свой шаг: внутреннее, запертый обход, висящее снаружи.
        $innerRadius = count($inside) < 2 ? .0 : 1 / (2 * sin(M_PI / count($inside)));
        $lockedRadius = $inside === []
            ? $home
            : max($home, ($innerRadius + self::CLEARANCE) / cos(M_PI / max(count($walk), 3)));
        $result = $this->getPolygon($inside, $homes, $center, $innerRadius);

        foreach ($this->place->getWheel($walk, $center, $lockedRadius) as $vertex => $point) {
            $result[$vertex] = $point;
        }

        $around = array_values(array_diff($order, $walk, $inside));

        foreach ($this->getAround($arena, $around, $walk, $homes, $center, $lockedRadius + 2 * self::CLEARANCE, $result) as $vertex => $point) {
            $result[$vertex] = $point;
        }

        return $result;
    }

    /**
     * Что вокруг запертого многоугольника. За сторонами, которые лежат на
     * контуре ветви, ничего быть не может: контур — граница всей ветви.
     * Поэтому висящее раскладывается только за открытыми сторонами, подряд,
     * и по порядку так, чтобы каждое легло поближе к тому, за что держится:
     * место на дуге — среднее мест соседей, вершины обхода стоят, висящее
     * подстраивается. Так рёбра от обхода к висящему не пересекают ни сторон,
     * ни друг друга.
     *
     * Если закрытых сторон нет, резать дугу негде: тогда каждое уходит
     * наружу по своему лучу.
     *
     * @param int[] $around
     * @param int[] $walk
     * @param array<int, Point2D> $homes
     * @param array<int, Point2D> $placed где уже стоят обход и внутреннее
     *
     * @return array<int, Point2D>
     */
    private function getAround(string $arena, array $around, array $walk, array $homes, Point2D $center, float $radius, array $placed): array
    {
        if ($around === []) {
            return [];
        }

        $arc = $this->getArcPlaces($arena, $around, $walk, $homes, $center, $radius, $placed);
        $key = sprintf('%s|%s|%s|%s|%.6f,%.6f,%.6f', $arena, implode(',', $walk), implode(',', $around), implode(',', array_keys($placed)), $center->x, $center->y, $radius);

        if (! array_key_exists($key, $this->layered)) {
            $this->layered[$key] = $this->getLayeredPlaces($arena, $around, $walk, $arc, $center, $radius, $placed);
        }

        return $this->layered[$key] ?? $arc;
    }

    /**
     * Висящее двумя слоями. То, что лежит на контуре ветви, — снаружи, своим
     * правильным многоугольником со своим шагом, в порядке контура: за ним
     * ничего нет. То, что не на контуре, — между ним и запертым обходом,
     * каждое в среднем своих соседей: иначе вершину, которая держится и за
     * обход, и за контур, пришлось бы ставить на одну окружность с контуром,
     * и её рёбра резали бы его.
     *
     * Многоугольник поворачивается, сдвигается и отходит, пока ни одно ребро
     * ничего не пересекает; поворот — ближайший к тому, где висящее лежало
     * дугой. Если так не лечь, null.
     *
     * @param int[] $around
     * @param int[] $walk
     * @param array<int, Point2D> $arc где висящее лежит дугой
     * @param array<int, Point2D> $placed где уже стоят обход и внутреннее
     *
     * @return array<int, Point2D>|null
     */
    private function getLayeredPlaces(string $arena, array $around, array $walk, array $arc, Point2D $center, float $radius, array $placed): ?array
    {
        $outer = $this->getContourChain($arena, $around, $walk);
        $interior = array_values(array_diff($around, $outer));

        if ($outer === []) {
            $outer = $around;
            $interior = [];
        }

        $count = count($outer);
        $ring = max($radius + ($interior === [] ? .0 : 2 * self::CLEARANCE), $count < 2 ? .0 : self::UNIT / (2 * sin(M_PI / $count)));
        $neighbours = [];

        foreach ($this->edges[$arena] ?? [] as [$vertexA, $vertexB]) {
            $neighbours[$vertexA][] = $vertexB;
            $neighbours[$vertexB][] = $vertexA;
        }

        $directions = [[.0, .0]];
        $anchor = $this->getAnchor($arena, $around, $placed);

        if ($anchor !== null) {
            $directions[] = [$anchor->x - $center->x, $anchor->y - $center->y];
        }

        $mean = $this->geometry->averagePoint(array_values(array_intersect_key($arc, array_flip($around))));
        $length = hypot($mean->x - $center->x, $mean->y - $center->y);

        if ($length > GeometryService::EPSILON) {
            $directions[] = [($mean->x - $center->x) / $length * $radius, ($mean->y - $center->y) / $length * $radius];
        }

        $first = $arc[$outer[0]] ?? $center;
        $turn = atan2($first->x - $center->x, $center->y - $first->y);
        $samples = 120;

        foreach ([1.0, 1.25, 1.5, 2.0] as $factor) {
            foreach ([.0, .5, 1.0, 1.5, 2.0, 3.0] as $offset) {
                foreach ($directions as $index => [$dx, $dy]) {
                    if (($index === 0) !== ($offset === .0)) {
                        continue;
                    }

                    $middle = new Point2D($center->x + $dx * $offset, $center->y + $dy * $offset);

                    for ($shift = 0; $shift <= $samples / 2; $shift++) {
                        foreach (array_unique([$shift, -$shift]) as $side) {
                            foreach ([1, -1] as $direction) {
                                $result = [];

                                foreach ($outer as $number => $vertex) {
                                    $result[$vertex] = $this->place->getOnAngle(
                                        $middle,
                                        $ring * $factor,
                                        $turn + 2 * M_PI * ($side / $samples + $direction * $number / $count),
                                    );
                                }

                                $result = $this->getSettled($interior, $result + $placed, $neighbours, $middle);

                                if ($result !== null && ! $this->hasCrossing($arena, $result)) {
                                    return array_intersect_key($result, array_flip($around));
                                }
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Висящее, что лежит на контуре ветви, — в порядке контура, начиная
     * сразу за запертым обходом.
     *
     * @param int[] $around
     * @param int[] $walk
     *
     * @return int[]
     */
    private function getContourChain(string $arena, array $around, array $walk): array
    {
        $contour = array_values(array_unique($this->contours[$arena] ?? []));
        $count = count($contour);

        if ($count === 0) {
            return [];
        }

        $locked = array_flip($walk);
        $start = 0;

        foreach ($contour as $number => $vertex) {
            if (isset($locked[$vertex]) && ! isset($locked[$contour[($number + 1) % $count]])) {
                $start = ($number + 1) % $count;
            }
        }

        $wanted = array_flip($around);
        $result = [];

        for ($offset = 0; $offset < $count; $offset++) {
            $vertex = $contour[($start + $offset) % $count];

            if (isset($wanted[$vertex])) {
                $result[] = $vertex;
            }
        }

        return $result;
    }

    /**
     * Вершины, которые ставятся в среднее своих соседей, пока не устоятся.
     * null — если какая-то слиплась с другой.
     *
     * @param int[] $free
     * @param array<int, Point2D> $points
     * @param array<int, int[]> $neighbours
     *
     * @return array<int, Point2D>|null
     */
    private function getSettled(array $free, array $points, array $neighbours, Point2D $start): ?array
    {
        foreach ($free as $vertex) {
            $points[$vertex] = $start;
        }

        for ($round = 0; $round < 60; $round++) {
            foreach ($free as $vertex) {
                $known = array_values(array_intersect_key($points, array_flip($neighbours[$vertex] ?? [])));

                if ($known !== []) {
                    $points[$vertex] = $this->geometry->averagePoint($known);
                }
            }
        }

        foreach ($free as $vertex) {
            foreach ($points as $other => $point) {
                if ($other !== $vertex && $this->geometry->distance($point, $points[$vertex]) < self::UNIT * .6) {
                    return null;
                }
            }
        }

        return $points;
    }

    /**
     * Висящее одним слоем: дугой за открытыми сторонами обхода или, если
     * не выходит, правильным многоугольником.
     *
     * @param int[] $around
     * @param int[] $walk
     * @param array<int, Point2D> $homes
     * @param array<int, Point2D> $placed
     *
     * @return array<int, Point2D>
     */
    private function getArcPlaces(string $arena, array $around, array $walk, array $homes, Point2D $center, float $radius, array $placed): array
    {
        $count = count($walk);
        $step = 2 * M_PI / $count;
        $closed = $this->closed[$arena] ?? [];
        $shut = [];

        foreach ($walk as $number => $vertex) {
            $shut[$number] = isset($closed[self::edgeName($vertex, $walk[($number + 1) % $count])]);
        }

        $start = array_search(true, $shut, true);

        if (! is_int($start) || ! in_array(false, $shut, true)) {
            $polygon = max($radius, count($around) < 2 ? .0 : 1 / (2 * sin(M_PI / count($around))));

            return $this->getPlanarPolygon($arena, $around, $homes, $center, $polygon, $placed)
                ?? $this->getPolygon($around, $homes, $center, $polygon);
        }

        // Открытые стороны подряд, начиная сразу за закрытой: дуга [0, длина).
        $segments = [];
        $anchors = [];
        $length = .0;

        for ($offset = 1; $offset <= $count; $offset++) {
            $number = ($start + $offset) % $count;

            if ($shut[$number]) {
                continue;
            }

            $anchors[$walk[$number]] ??= $length;
            $segments[] = [$length, $step * $number];
            $length += $step;
            $anchors[$walk[($number + 1) % $count]] = $length;
        }

        $positions = $this->getArcOrder($around, $anchors, $length, $arena);
        $radius = max($radius, count($around) / $length);
        $result = [];

        foreach ($positions as $number => $vertex) {
            $position = $length * ($number + .5) / count($positions);
            $angle = .0;

            foreach ($segments as [$from, $at]) {
                if ($position >= $from) {
                    $angle = $at + $position - $from;
                }
            }

            $result[$vertex] = $this->place->getOnAngle($center, $radius, $angle);
        }

        // Висящее — свой правильный многоугольник со своим шагом: порядок
        // берётся с дуги, поворот — ближайший к дуге из тех, при которых
        // ни одно ребро ничего не пересекает. Если такого поворота нет
        // (висящего много, а держится оно за одну вершину обхода), остаётся
        // дуга: планарность важнее.
        return $this->getPlanarPolygon(
            $arena,
            $positions,
            $result,
            $center,
            max($radius, count($positions) < 2 ? .0 : 1 / (2 * sin(M_PI / count($positions)))),
            $placed,
        ) ?? $result;
    }

    /**
     * Правильный многоугольник, как {@see getPolygon}, но из поворотов
     * берётся ближайший к лучшему из тех, при которых рёбра арены между
     * расставленными вершинами не пересекаются.
     *
     * @param int[] $vertexes
     * @param array<int, Point2D> $homes
     * @param array<int, Point2D> $placed
     *
     * @return array<int, Point2D>|null null, если без пересечений не лечь
     */
    private function getPlanarPolygon(string $arena, array $vertexes, array $homes, Point2D $center, float $radius, array $placed): ?array
    {
        $best = $this->getPolygon($vertexes, $homes, $center, $radius);

        if (count($vertexes) < 2) {
            return $best;
        }

        $first = $best[$vertexes[0]];
        $turn = atan2($first->x - $center->x, $center->y - $first->y);
        $count = count($vertexes);
        $samples = 360;

        // Если на своём радиусе не лечь, многоугольник сдвигается: к тому,
        // за что висящее держится (у колеса — к ступице: из неё рёбра
        // расходятся веером и ничего не задевают), или туда, где висящее
        // лежало дугой, — тогда запертый обход оказывается у самого разрыва
        // кольца, и рёбра к висящему расходятся от него широким веером.
        // И отходит дальше.
        $directions = [];
        $anchor = $this->getAnchor($arena, $vertexes, $placed);

        if ($anchor !== null) {
            $directions[] = [$anchor->x - $center->x, $anchor->y - $center->y];
        }

        $arc = $this->geometry->averagePoint(array_values(array_intersect_key($homes, array_flip($vertexes))));
        $length = hypot($arc->x - $center->x, $arc->y - $center->y);

        if ($length > GeometryService::EPSILON) {
            $directions[] = [($arc->x - $center->x) / $length * $radius, ($arc->y - $center->y) / $length * $radius];
        }

        $variants = [[1.0, .0, 0.0, 0.0]];

        foreach ([.5, 1.0, 1.5, 2.0, 3.0] as $offset) {
            foreach ([1.0, 1.25, 1.5, 2.0] as $factor) {
                foreach ($directions as [$dx, $dy]) {
                    $variants[] = [$factor, $offset, $dx, $dy];
                }
            }
        }

        foreach ($variants as [$factor, $offset, $dx, $dy]) {
            $middle = new Point2D($center->x + $dx * $offset, $center->y + $dy * $offset);

            for ($shift = 0; $shift <= $samples / 2; $shift++) {
                foreach (array_unique([$shift, -$shift]) as $side) {
                    $result = [];

                    foreach (array_values($vertexes) as $number => $vertex) {
                        $result[$vertex] = $this->place->getOnAngle(
                            $middle,
                            $radius * $factor,
                            $turn + 2 * M_PI * ($side / $samples + $number / $count),
                        );
                    }

                    if (! $this->hasCrossing($arena, $result + $placed)) {
                        return $result;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Среднее мест, за которые держится висящее: вершина обхода считается
     * столько раз, сколько к ней рёбер от висящего.
     *
     * @param int[] $vertexes
     * @param array<int, Point2D> $placed
     */
    private function getAnchor(string $arena, array $vertexes, array $placed): ?Point2D
    {
        $own = array_flip($vertexes);
        $points = [];

        foreach ($this->edges[$arena] ?? [] as [$vertexA, $vertexB]) {
            if (isset($own[$vertexA], $placed[$vertexB])) {
                $points[] = $placed[$vertexB];
            } elseif (isset($own[$vertexB], $placed[$vertexA])) {
                $points[] = $placed[$vertexA];
            }
        }

        return $points === [] ? null : $this->geometry->averagePoint($points);
    }

    /**
     * Пересекаются ли рёбра арены, у которых оба конца уже расставлены.
     *
     * @param array<int, Point2D> $points
     */
    private function hasCrossing(string $arena, array $points): bool
    {
        $segments = [];

        foreach ($this->edges[$arena] ?? [] as [$vertexA, $vertexB]) {
            if (isset($points[$vertexA], $points[$vertexB])) {
                $segments[] = [$vertexA, $vertexB];
            }
        }

        foreach ($segments as $i => [$a, $b]) {
            for ($j = $i + 1; $j < count($segments); $j++) {
                [$c, $d] = $segments[$j];

                if (count(array_unique([$a, $b, $c, $d])) === 4
                    && $this->geometry->segmentsIntersect($points[$a], $points[$b], $points[$c], $points[$d])
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Порядок висящего вдоль дуги: каждое — на среднем месте своих соседей,
     * пока не устоится.
     *
     * @param int[] $around
     * @param array<int, float> $anchors вершина обхода => её место на дуге
     *
     * @return int[]
     */
    private function getArcOrder(array $around, array $anchors, float $length, string $arena): array
    {
        $neighbours = [];

        foreach ($this->edges[$arena] ?? [] as [$vertexA, $vertexB]) {
            $neighbours[$vertexA][] = $vertexB;
            $neighbours[$vertexB][] = $vertexA;
        }

        $places = array_fill_keys($around, $length / 2);

        for ($round = 0; $round < 200; $round++) {
            foreach ($around as $vertex) {
                $sum = .0;
                $weight = 0;

                foreach ($neighbours[$vertex] ?? [] as $neighbour) {
                    $place = $anchors[$neighbour] ?? $places[$neighbour] ?? null;

                    if ($place !== null) {
                        $sum += $place;
                        $weight++;
                    }
                }

                if ($weight > 0) {
                    $places[$vertex] = $sum / $weight;
                }
            }
        }

        $rank = array_flip($around);
        $result = $around;
        usort($result, static fn (int $a, int $b): int => [$places[$a], $rank[$a]] <=> [$places[$b], $rank[$b]]);

        return $result;
    }

    /**
     * Правильный многоугольник со своим шагом, в том порядке, в каком
     * вершины лежали дома, и повёрнутый так, чтобы в среднем каждая
     * сдвинулась от дома как можно меньше.
     *
     * @param int[] $vertexes
     * @param array<int, Point2D> $homes
     *
     * @return array<int, Point2D>
     */
    private function getPolygon(array $vertexes, array $homes, Point2D $center, float $radius): array
    {
        $count = count($vertexes);

        if ($count === 0) {
            return [];
        }

        if ($count === 1 || $radius < GeometryService::EPSILON) {
            return array_fill_keys($vertexes, $center);
        }

        $x = .0;
        $y = .0;

        foreach (array_values($vertexes) as $number => $vertex) {
            $home = $homes[$vertex] ?? $center;
            $delta = atan2($home->x - $center->x, $center->y - $home->y) - 2 * M_PI * $number / $count;
            $x += sin($delta);
            $y += cos($delta);
        }

        $turn = atan2($x, $y);
        $result = [];

        foreach (array_values($vertexes) as $number => $vertex) {
            $result[$vertex] = $this->place->getOnAngle($center, $radius, $turn + 2 * M_PI * $number / $count);
        }

        return $result;
    }

    /**
     * Склеить: второе поле подъезжает к первому снаружи и касается его
     * точкой склейки — копия ложится на копию.
     *
     * @return array<string, array<int, Point2D>>
     */
    private function getGluePlaces(Glue $glue): array
    {
        $first = $this->getArenaPlaces($glue->arena);
        $second = $this->getArenaPlaces($glue->other);
        $center = $this->centers[$glue->arena] ?? new Point2D();
        $otherCenter = $this->centers[$glue->other] ?? new Point2D();
        $touch = $first[$glue->vertex] ?? $center;
        $radius = $this->place->getPieceRadius(count($second), self::UNIT);
        $away = [$touch->x - $center->x, $touch->y - $center->y];
        $length = hypot(...$away);
        $away = $length < GeometryService::EPSILON ? [.0, -1.0] : [$away[0] / $length, $away[1] / $length];
        $newCenter = new Point2D($touch->x + $away[0] * $radius, $touch->y + $away[1] * $radius);
        $own = $second[$glue->vertex] ?? $otherCenter;
        $turn = atan2(-$away[1], -$away[0]) - atan2($own->y - $otherCenter->y, $own->x - $otherCenter->x);
        $moved = [];

        foreach ($second as $vertex => $point) {
            [$x, $y] = [$point->x - $otherCenter->x, $point->y - $otherCenter->y];
            $moved[$vertex] = new Point2D(
                $newCenter->x + $x * cos($turn) - $y * sin($turn),
                $newCenter->y + $x * sin($turn) + $y * cos($turn),
            );
        }

        $moved[$glue->vertex] = $touch;
        $this->reachAround($glue->arena, $moved);

        return [$glue->arena => $first, $glue->other => $moved];
    }

    /**
     * Раскрыть в круг: обход круга ровно по окружности, на месте первого
     * поля. Точка склейки в обходе дважды: первый угол — копии первого
     * поля, второй — копии второго. Круг поворачивается так, чтобы вершины
     * разъехались как можно меньше.
     *
     * @return array<string, array<int, Point2D>>
     */
    private function getOpenPlaces(Open $open): array
    {
        $walk = array_values($open->walk);
        $count = count($walk);
        $center = $this->centers[$open->arena] ?? new Point2D();
        $radius = $this->place->getPieceRadius($count, self::UNIT);
        $inFirst = array_flip($this->arenas[$open->arena] ?? []);
        $seen = 0;
        $owners = [];

        foreach ($walk as $position => $vertex) {
            $owners[$position] = $vertex === $open->vertex
                ? ($seen++ === 0 ? $open->arena : $open->other)
                : (isset($inFirst[$vertex]) ? $open->arena : $open->other);
        }

        $best = null;
        $bestCost = INF;

        foreach ([1, -1] as $direction) {
            for ($shift = 0; $shift < $count; $shift++) {
                $points = [];
                $cost = .0;

                foreach ($walk as $position => $vertex) {
                    $slot = (($direction * $position + $shift) % $count + $count) % $count;
                    $point = $this->place->getOnAngle($center, $radius, 2 * M_PI * $slot / $count);
                    $points[$position] = $point;
                    $now = $this->last[$owners[$position]][$vertex] ?? $point;
                    $cost += ($point->x - $now->x) ** 2 + ($point->y - $now->y) ** 2;
                }

                if ($cost < $bestCost) {
                    [$best, $bestCost] = [$points, $cost];
                }
            }
        }

        $result = [$open->arena => [], $open->other => []];

        foreach ($walk as $position => $vertex) {
            $result[$owners[$position]][$vertex] = ($best ?? [])[$position] ?? $center;
        }

        $this->reachAround($open->arena, $result[$open->other]);

        return $result;
    }

    /**
     * @param array<int, Point2D> $points
     */
    private function reachAround(string $arena, array $points): void
    {
        $center = $this->centers[$arena] ?? new Point2D();

        foreach ($points as $point) {
            $distance = hypot($point->x - $center->x, $point->y - $center->y);
            $this->reach[$arena] = max($this->reach[$arena] ?? .0, $distance);

            if (isset($this->hasChild[$arena])) {
                $this->reachAfterCut[$arena] = max($this->reachAfterCut[$arena] ?? .0, $distance);
            }
        }
    }

    /**
     * Поле ложится в укладку куска — на середину арены куска. Укладка
     * алгоритма переносится туда целиком, в том же масштабе, что и всё на
     * столе: внешняя грань ложится кругом с расстоянием между соседями
     * в единицу.
     *
     * @return array<string, array<int, Point2D>>
     */
    private function getBuildPlaces(Build $build, string $part): array
    {
        $result = [$build->arena => $this->getDrawn($build->arena, $part, $this->drawing)];
        $aside = $part . self::ASIDE;

        if (($this->firsts[$part] ?? null) === $build->arena && isset($this->centers[$aside])) {
            $present = $this->arenas[$part] ?? [];
            $order = array_values(array_unique(array_merge(
                array_values(array_intersect($this->orders[$part] ?? [], $present)),
                $present,
            )));
            $result[$part] = $this->place->getWheel(
                $order,
                $this->centers[$aside],
                $this->place->getPieceRadius(count($order), self::UNIT),
            );
        }

        return $result;
    }

    /**
     * Все поля куска — туда, где их вершины стоят в этой укладке алгоритма:
     * выпрямленной или расслабленной. Внешняя грань при этом стоит, поэтому
     * перенос на стол тот же, что и у сборки.
     *
     * @param array<int, Point2D> $frame
     *
     * @return array<string, array<int, Point2D>>
     */
    private function getFramePlaces(string $part, array $frame): array
    {
        $result = [];

        foreach (array_unique($this->fields[$part] ?? []) as $arena) {
            $result[$arena] = $this->getDrawn($arena, $part, $frame);
        }

        return $result;
    }

    /**
     * Несколько полей ложатся вместе — каждое туда, где его вершины
     * поставил алгоритм.
     *
     * @return array<string, array<int, Point2D>>
     */
    private function getCoverPlaces(Cover $cover, string $part): array
    {
        $result = [];

        foreach (array_keys($cover->fields) as $arena) {
            $result[(string) $arena] = $this->getDrawn((string) $arena, $part, $this->drawing);
        }

        return $result;
    }

    /**
     * Перенос укладки алгоритма на стол куска: середина внешней грани — на
     * середину арены, а размер — такой, чтобы укладка поместилась в то место,
     * которое кусок занимал до сборки. Иначе середину куска с самого начала
     * держали бы пустой под будущий рисунок, и всё вокруг отодвигалось бы.
     * Прочесть готовый рисунок поможет камера: она наедет на него.
     *
     * Размер считается один раз, при первом поле, и дальше не меняется.
     *
     * @return array{Point2D, float}
     */
    private function getFrame(string $part): array
    {
        [$origin, $scale] = $this->frames[$part] ?? [new Point2D(), 1.0];

        if (! isset($this->fitted[$part])) {
            $outer = .0;

            foreach ($this->firstWalks[$part] ?? [] as $vertex) {
                $point = $this->drawing[$vertex] ?? $origin;
                $outer = max($outer, hypot($point->x - $origin->x, $point->y - $origin->y));
            }

            $room = max($this->reachAfterCut[$part] ?? $this->reach[$part] ?? .0, $this->place->getPieceRadius(count(array_unique($this->firstWalks[$part] ?? [])), self::UNIT));
            $this->fitted[$part] = $outer < GeometryService::EPSILON ? $scale : $room / $outer;
        }

        // Масштаб у всех кусков один — самый мелкий: иначе готовые рисунки
        // несвязных кусков вышли бы разного размера.
        return [$origin, min($this->fitted)];
    }

    /**
     * Вершины арены там, где их поставил алгоритм, перенесённые на стол куска.
     *
     * @param array<int, Point2D> $drawing
     *
     * @return array<int, Point2D>
     */
    private function getDrawn(string $arena, string $part, array $drawing): array
    {
        [$origin, $scale] = $this->getFrame($part);
        $center = $this->centers[$part] ?? new Point2D();
        $result = [];

        foreach ($this->arenas[$arena] ?? [] as $vertex) {
            $point = $drawing[$vertex] ?? $origin;
            $result[$vertex] = new Point2D(
                $center->x + ($point->x - $origin->x) * $scale,
                $center->y + ($point->y - $origin->y) * $scale,
            );
        }

        $this->reachAround($part, $result);

        return $result;
    }

    /**
     * Середина и масштаб укладки каждого куска — заранее, по его первому
     * полю: алгоритм начинает с внешней грани.
     */
    private function collectFrames(Step $step): void
    {
        foreach ($step->children as $lane) {
            $first = $lane->steps[0]->action ?? null;

            if (! $first instanceof Build) {
                foreach ($lane->steps as $child) {
                    $this->collectFrames($child);
                }

                continue;
            }

            $this->firsts[$lane->arena] = $first->arena;
            $this->firstWalks[$lane->arena] = $first->walk;

            foreach ($lane->steps as $child) {
                $action = $child->action;

                if ($action instanceof Build || $action instanceof Postpone) {
                    $this->fields[$lane->arena][] = $action->arena;
                } elseif ($action instanceof Cover) {
                    array_push($this->fields[$lane->arena], ...array_map(strval(...), array_keys($action->fields)));
                }
            }

            $outer = array_values(array_unique($first->walk));
            $points = array_values(array_filter(array_map(fn (int $vertex): ?Point2D => $this->drawing[$vertex] ?? null, $outer)));

            if ($points === []) {
                continue;
            }

            $origin = new Point2D(
                array_sum(array_map(static fn (Point2D $point): float => $point->x, $points)) / count($points),
                array_sum(array_map(static fn (Point2D $point): float => $point->y, $points)) / count($points),
            );
            // Масштаб — по готовому рисунку: ближайшие вершины расслабленной
            // укладки расходятся на расстояние между соседями, как на столе.
            $vertexes = [];

            foreach ($lane->steps as $child) {
                if ($child->action instanceof Build) {
                    array_push($vertexes, ...$child->action->walk);
                } elseif ($child->action instanceof Cover) {
                    array_push($vertexes, ...array_merge(...array_values($child->action->fields)));
                }
            }

            $closest = $this->getClosest(array_values(array_unique($vertexes)), $this->relaxed);
            $scale = $closest === null ? 1.0 : 1 / $closest;
            $this->frames[$lane->arena] = [$origin, $scale];
        }
    }

    /**
     * Самое короткое расстояние между разными вершинами рисунка.
     *
     * @param int[] $vertexes
     * @param array<int, Point2D> $drawing
     */
    private function getClosest(array $vertexes, array $drawing): ?float
    {
        $result = null;
        $count = count($vertexes);

        for ($first = 0; $first < $count; $first++) {
            for ($second = $first + 1; $second < $count; $second++) {
                $pointA = $drawing[$vertexes[$first]] ?? null;
                $pointB = $drawing[$vertexes[$second]] ?? null;

                if ($pointA === null || $pointB === null) {
                    continue;
                }

                $distance = hypot($pointA->x - $pointB->x, $pointA->y - $pointB->y);

                if ($distance > GeometryService::EPSILON) {
                    $result = $result === null ? $distance : min($result, $distance);
                }
            }
        }

        return $result;
    }

    /**
     * Контуры всех ветвей — заранее: раскладка вокруг запертого обхода
     * должна знать, за какими его сторонами ничего нет.
     */
    private function collectContours(Step $step): void
    {
        if ($step->action instanceof Contour) {
            $walk = array_values($step->action->walk);
            $count = count($walk);
            $this->contours[$step->action->arena] = $walk;

            foreach ($walk as $number => $vertex) {
                $this->closed[$step->action->arena][self::edgeName($vertex, $walk[($number + 1) % $count])] = true;
            }
        }

        foreach ($step->children as $lane) {
            foreach ($lane->steps as $child) {
                $this->collectContours($child);
            }
        }
    }

    private static function edgeName(int $vertexA, int $vertexB): string
    {
        return min($vertexA, $vertexB) . '-' . max($vertexA, $vertexB);
    }

    /**
     * Обход в ту же сторону и с того же места, что и круг номеров, по
     * которому вершины лежат дома: с меньшего номера, и направление — то,
     * при котором номера реже перескакивают назад. Иначе многоугольник
     * ложится зеркально, и вершины на ходу проходят друг сквозь друга.
     *
     * @param int[] $walk
     *
     * @return int[]
     */
    private function orient(array $walk): array
    {
        $walk = array_values(array_unique($walk));
        $count = count($walk);

        if ($count < 3) {
            return $walk;
        }

        $start = (int) array_search(min($walk), $walk, true);
        $forward = array_merge(array_slice($walk, $start), array_slice($walk, 0, $start));
        $backward = array_merge([$forward[0]], array_reverse(array_slice($forward, 1)));

        return $this->getTurns($backward) < $this->getTurns($forward) ? $backward : $forward;
    }

    /**
     * Сколько раз обход проходит круг номеров: сумма шагов вперёд по кругу.
     *
     * @param int[] $walk
     */
    private function getTurns(array $walk): int
    {
        $sorted = $walk;
        sort($sorted);
        $rank = array_flip($sorted);
        $count = count($walk);
        $result = 0;

        foreach ($walk as $position => $vertex) {
            $result += ($rank[$walk[($position + 1) % $count]] - $rank[$vertex] + $count) % $count;
        }

        return $result;
    }

    /**
     * Середины всех арен.
     *
     * @param array<string, string> $parents арена => родитель
     * @param array<string, int> $sizes арена => сколько вершин на ней бывало разом
     * @param array<string, int[]> $born арена => вершины, с которыми она родилась
     *
     * @return array<string, Point2D>
     */
    private function getCenters(array $parents, array $sizes, array $born): array
    {
        $children = [];

        foreach ($parents as $arena => $parent) {
            $children[$parent][] = $arena;
        }

        $extents = [];
        $rings = [];

        foreach (array_keys($sizes) as $arena) {
            $this->measure($arena, $children, $sizes, $born, $extents, $rings);
        }

        $result = [];

        foreach (array_keys($sizes) as $arena) {
            if (! isset($parents[$arena])) {
                $this->place($arena, new Point2D(), $children, $sizes, $born, $extents, $rings, $result);
            }
        }

        return $result;
    }

    /**
     * Сколько места занимает арена вместе со всеми своими потомками.
     *
     * @param array<string, string[]> $children
     * @param array<string, int> $sizes
     * @param array<string, int[]> $born
     * @param array<string, float> $extents
     * @param array<string, array<string, float>> $rings арена => ребёнок => на каком расстоянии
     */
    private function measure(string $arena, array $children, array $sizes, array $born, array &$extents, array &$rings): float
    {
        if (isset($extents[$arena])) {
            return $extents[$arena];
        }

        // Середина — то, что лежит на самой арене. Клубок, из которого всё
        // началось, в счёт не идёт: дети уезжают из него по одному, и к тому
        // времени, как первый доедет до места, остаток уже поредел.
        $home = $this->reachAfterCut[$arena]
            ?? max($this->place->getPieceRadius($sizes[$arena] ?? 1, self::UNIT), $this->reach[$arena] ?? .0);
        $ordered = $this->getOrdered($arena, $children, $sizes, $born);
        $radii = [];

        foreach ($ordered as $child) {
            $radii[$child] = $this->measure($child, $children, $sizes, $born, $extents, $rings);
        }

        if ($radii === []) {
            return $extents[$arena] = $home;
        }

        // Каждый ребёнок — на своём расстоянии: вплотную к середине, с зазором.
        // Маленькие ложатся ближе, большие дальше, а не все на радиусе
        // самого большого. Если по кругу они не помещаются, все отодвигаются
        // одинаково, пока их секторы не уложатся в оборот.
        $near = [];

        foreach ($radii as $child => $radius) {
            $near[$child] = $home + self::GAP + $radius;
        }

        $factor = 1.0;

        while ($this->getSectorSum($radii, $near, $factor) > 2 * M_PI) {
            $factor *= 1.03;
        }

        $extent = $home;

        foreach ($near as $child => $distance) {
            $rings[$arena][$child] = $distance * $factor;
            $extent = max($extent, $distance * $factor + $radii[$child]);
        }

        return $extents[$arena] = $extent;
    }

    /**
     * Сколько оборота занимают дети на своих расстояниях: каждый — сектор,
     * в который помещается он сам и половина зазора с каждой стороны.
     *
     * @param array<string, float> $radii
     * @param array<string, float> $distances
     */
    private function getSectorSum(array $radii, array $distances, float $factor): float
    {
        $result = .0;

        foreach ($radii as $child => $radius) {
            $result += 2 * asin(min(1.0, ($radius + self::GAP / 2) / ($distances[$child] * $factor)));
        }

        return $result;
    }

    /**
     * Середины секторов по кругу, от верха по часовой: каждый ребёнок
     * занимает свой сектор, свободный остаток оборота делится поровну.
     *
     * @param array<string, float> $radii
     * @param array<string, float> $distances
     *
     * @return array<int, float>
     */
    private function getSpread(array $radii, array $distances): array
    {
        $sectors = [];

        foreach ($radii as $child => $radius) {
            $sectors[] = 2 * asin(min(1.0, ($radius + self::GAP / 2) / max($distances[$child], GeometryService::EPSILON)));
        }

        $free = max(.0, 2 * M_PI - array_sum($sectors)) / count($sectors);
        $result = [];
        $angle = .0;

        foreach ($sectors as $number => $sector) {
            $result[$number] = $angle + $sector / 2;
            $angle += $sector + $free;
        }

        return $result;
    }

    /**
     * Поставить арену и, от неё, кольцом всех её детей.
     *
     * @param array<string, string[]> $children
     * @param array<string, int> $sizes
     * @param array<string, int[]> $born
     * @param array<string, float> $extents
     * @param array<string, array<string, float>> $rings арена => ребёнок => на каком расстоянии
     * @param array<string, Point2D> $result
     */
    private function place(
        string $arena,
        Point2D $center,
        array $children,
        array $sizes,
        array $born,
        array $extents,
        array $rings,
        array &$result,
    ): void {
        $result[$arena] = $center;
        $ordered = $this->getOrdered($arena, $children, $sizes, $born);

        if ($ordered === []) {
            return;
        }

        $radii = [];

        foreach ($ordered as $child) {
            $radii[$child] = $extents[$child];
        }

        $angles = $this->getSpread($radii, $rings[$arena] ?? []);
        // Кольцо поворачивается так, чтобы каждый ребёнок оказался поближе
        // к тому месту, где его вершины лежали у родителя: тогда он уезжает
        // наружу, а не через середину.
        $turn = $this->getTurn($ordered, $angles, $arena, $children, $born);

        foreach ($ordered as $number => $child) {
            $this->rings[$arena][$child] = $angles[$number] + $turn;
            $this->place(
                $child,
                $this->place->getOnAngle($center, $rings[$arena][$child] ?? .0, $angles[$number] + $turn),
                $children,
                $sizes,
                $born,
                $extents,
                $rings,
                $result,
            );
        }
    }

    /**
     * Дети арены в том порядке, в каком их вершины лежали у родителя.
     *
     * @param array<string, string[]> $children
     * @param array<string, int> $sizes
     * @param array<string, int[]> $born
     *
     * @return string[]
     */
    private function getOrdered(string $arena, array $children, array $sizes, array $born): array
    {
        $result = $children[$arena] ?? [];
        $angles = [];

        foreach ($result as $child) {
            $angles[$child] = $this->getHomeAngle($arena, $born[$child] ?? [], $children, $born);
        }

        usort($result, static fn (string $a, string $b): int => $angles[$a] <=> $angles[$b]);

        return $result;
    }

    /**
     * Угол, под которым эти вершины лежали на круге родителя: средний по
     * кругу, в той же мере, что у мест на кольце, — от верха по часовой.
     *
     * @param int[] $vertexes
     * @param array<string, string[]> $children
     * @param array<string, int[]> $born
     */
    private function getHomeAngle(string $parent, array $vertexes, array $children, array $born): float
    {
        $all = $born[$parent] ?? [];

        foreach ($children[$parent] ?? [] as $child) {
            $all = array_merge($all, $born[$child] ?? []);
        }

        $all = array_values(array_unique($all));
        sort($all);
        $rank = array_flip($all);
        $count = max(count($all), 1);
        $x = .0;
        $y = .0;

        foreach ($vertexes as $vertex) {
            $angle = 2 * M_PI * ($rank[$vertex] ?? 0) / $count;
            $x += sin($angle);
            $y += cos($angle);
        }

        $result = atan2($x, $y);

        return $result < 0 ? $result + 2 * M_PI : $result;
    }

    /**
     * @param string[] $ordered
     * @param array<int, float> $angles
     * @param array<string, string[]> $children
     * @param array<string, int[]> $born
     */
    private function getTurn(array $ordered, array $angles, string $arena, array $children, array $born): float
    {
        $x = .0;
        $y = .0;

        foreach ($ordered as $number => $child) {
            $delta = $this->getHomeAngle($arena, $born[$child] ?? [], $children, $born) - $angles[$number];
            $x += sin($delta);
            $y += cos($delta);
        }

        return atan2($x, $y);
    }
}
