<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\Aggregates\Trace;
use EugeneErg\Graphs\ValueObjects\Angle;
use EugeneErg\Graphs\ValueObjects\Arc;
use EugeneErg\Graphs\ValueObjects\Edge;
use EugeneErg\Graphs\ValueObjects\GravityInterface;
use EugeneErg\Graphs\ValueObjects\Point2D;
use EugeneErg\Graphs\ValueObjects\StageKind;
use EugeneErg\Graphs\ValueObjects\Topology;

/**
 * Расстановка вершин плоской укладки.
 *
 * Сначала строится начальная раскладка: внешняя грань ложится на окружность,
 * внутренние вершины подвешиваются на дуги Безье. Такая раскладка планарна,
 * но сжата: чем больше вершин, тем плотнее они слипаются у центра.
 *
 * Дальше идёт расслабление. Каждая внутренняя вершина видит вокруг себя
 * многоугольник из смежных граней и переезжает в центр масс той его части,
 * которую реально видит. Шаг принимается, только если укладка осталась плоской,
 * иначе он уменьшается вдвое. Планарность поэтому не теряется ни на одном кадре.
 */
final readonly class CoordinateService
{
    /** Предохранитель: сколько шагов выравнивания самое большее за один круг. */
    private const int EVEN_ROUNDS = 5000;

    /** Через сколько кругов без заметного улучшения шаг делится пополам. */
    private const int EVEN_PATIENCE = 5;

    /** По скольким направлениям вершина пробует шагнуть. */
    private const int EVEN_DIRECTIONS = 16;

    public function __construct(
        private GeometryService $geometry = new GeometryService(),
        private int $maxIterations = 800,
        private float $accuracy = 0.01,
        private MotionService $motion = new MotionService(),
    ) {
    }

    /**
     * @return Point2D[]
     */
    public function getCoordinates(
        Topology $topology,
        float $radius,
        ?Point2D $center = null,
        ?Trace $trace = null,
    ): array {
        $result = $this->getCircle($topology->outerEdge->vertexes, $radius, $center);
        // Вторая группа — обход, который прокладывает этот шаг: по нему
        // отрисовка узнаёт, какие рёбра уже легли, а значит и в каком порядке
        // поля укладывались на самом деле. Внешняя грань — замкнутый обход,
        // поэтому первая вершина повторяется в конце.
        $trace?->add(
            StageKind::Build,
            sprintf('Кладём внешнюю грань на окружность: %s', implode(' - ', $topology->outerEdge->vertexes)),
            [array_keys($result), [...$topology->outerEdge->vertexes, ...array_slice($topology->outerEdge->vertexes, 0, 1)]],
        );

        foreach ($topology->arcs as $number => $arc) {
            $indexes = $this->getIndexes($arc);
            $begin = $result[$arc->firstVertex()];
            $end = $result[$arc->lastVertex()];
            $gravityCenter = $this->getGravityCenter($arc->gravity, $result);
            $added = [];

            foreach ($indexes as $vertex => $index) {
                if (!isset($result[$vertex])) {
                    $result[$vertex] = $this->bezierPoint($begin, $gravityCenter, $end, $index);
                    $added[] = $vertex;
                }
            }

            // Поля ложатся по одному, и каждое видно отдельным шагом.
            $trace?->add(
                StageKind::Build,
                sprintf(
                    'Добавляем поле %d: %s',
                    $number + 1,
                    implode(' - ', array_merge(...$arc->vertexes)),
                ),
                [array_keys($result), array_merge(...$arc->vertexes)],
                $added,
            );
        }

        return $result;
    }

    /**
     * Все вершины на одной окружности в порядке номеров: то самое спутанное
     * начальное состояние, из которого укладка потом распутывается.
     *
     * @param int[] $vertexes
     *
     * @return Point2D[]
     */
    public function getTangledCoordinates(array $vertexes, float $radius, ?Point2D $center = null): array
    {
        sort($vertexes);

        return $this->getCircle($vertexes, $radius, $center);
    }

    /**
     * Барицентрическая укладка Татта: внешняя грань остаётся на месте,
     * каждая внутренняя вершина садится в среднее своих соседей.
     *
     * Это распрямляет начальную догадку в честную плоскую укладку с нужной
     * топологией. Вершины при этом сгущаются в плотных местах — расходятся
     * они уже на расслаблении.
     *
     * @param Edge[] $edges внутренние грани
     * @param Point2D[] $coordinates
     *
     * @return Point2D[]
     */
    public function getBarycentricCoordinates(Edge $outerEdge, array $edges, array $coordinates): array
    {
        $outerVertexes = array_flip($outerEdge->vertexes);
        $neighbours = array_diff_key($this->getNeighbours($edges), $outerVertexes);
        $guess = $coordinates;

        for ($iteration = 0; $iteration < $this->maxIterations; $iteration++) {
            $shift = .0;

            foreach ($neighbours as $vertex => $vertexNeighbours) {
                if ($vertexNeighbours === []) {
                    continue;
                }

                $moved = $this->geometry->averagePoint(
                    array_map(static fn (int $item): Point2D => $coordinates[$item], $vertexNeighbours),
                );
                $shift = max($shift, $this->geometry->distance($coordinates[$vertex], $moved));
                $coordinates[$vertex] = $moved;
            }

            if ($shift < $this->accuracy) {
                break;
            }
        }

        // Татт честен только для трёхсвязного графа. Кусок, который держится
        // за остальное двумя точками (цепочка, K4 на одной вершине вместе со
        // связками склейки), он сплющивает в отрезок или в точку, и
        // расслабление такое уже не разведёт. Тогда остаётся догадка: она
        // тоже плоская, только кривее.
        $all = $this->motion->getEdges($this->getNeighbours(array_merge([$outerEdge], $edges)));

        // Сравнивается самый узкий просвет: вопрос в том, не слиплось ли что-то.
        return ($this->getScore($coordinates, $all)[0] ?? .0) < ($this->getScore($guess, $all)[0] ?? .0)
            ? $guess
            : $coordinates;
    }

    /**
     * Итоговая расслабленная укладка.
     *
     * @param Edge[] $edges внутренние грани
     * @param Point2D[] $coordinates
     *
     * @return Point2D[]
     */
    public function relaxCoordinates(Edge $outerEdge, array $edges, array $coordinates): array
    {
        $steps = $this->relaxSteps($outerEdge, $edges, $coordinates);

        return $steps[count($steps) - 1];
    }

    /**
     * Все промежуточные состояния расслабления, начиная с исходного.
     * Нужны для анимации: каждый элемент — кадр.
     *
     * @param Edge[] $edges внутренние грани
     * @param Point2D[] $coordinates
     *
     * @return Point2D[][]
     */
    public function relaxSteps(Edge $outerEdge, array $edges, array $coordinates): array
    {
        return $this->evenSteps($outerEdge, $edges, $coordinates);
    }

    /**
     * Расслабление выравниванием просветов. Просвет вершины — расстояние до
     * ближайшей другой вершины и до ближайшего ребра, которое к ней не
     * приходит. Идеал — когда просветы у всех как можно ровнее и при этом
     * как можно шире: так средняя вершина ломаной встаёт ровно посередине.
     *
     * Вершины по очереди пробуют шагнуть в разные стороны; шаг принимается,
     * если просветы стали ровнее (`compareEven`), а самый узкий не просел.
     * Считается весь рисунок, а не одна
     * вершина: вершина, у которой просторно, отойдёт, если этим освободит
     * место тесным соседям. Шаг — только если рёбра вершины по дороге
     * ничего не задели, поэтому рисунок остаётся плоским. Когда никто
     * никуда не шагнул, шаг делится пополам.
     *
     * @param Edge[] $edges внутренние грани
     * @param Point2D[] $coordinates
     *
     * @return Point2D[][]
     */
    private function evenSteps(Edge $outerEdge, array $edges, array $coordinates): array
    {
        $neighbours = $this->getNeighbours($edges);
        $lines = $this->motion->getEdges($this->getNeighbours(array_merge([$outerEdge], $edges)));
        $fixed = array_flip($outerEdge->vertexes);
        $movable = array_values(array_filter(array_keys($neighbours), static fn (int $vertex): bool => ! isset($fixed[$vertex])));
        $incident = [];

        foreach ($lines as [$vertexA, $vertexB]) {
            $incident[$vertexA][] = $vertexB;
            $incident[$vertexB][] = $vertexA;
        }

        $steps = [$coordinates];
        $ideal = $this->getIdealLength($outerEdge, $coordinates);

        // Сначала расширяются самые узкие места, потом, не давая самому
        // узкому просесть, выравнивается остальное: иначе выравнивание
        // начиналось бы с тесной догадки и держалось её уровня.
        // Каждый круг идёт, пока рисунок меняется больше, чем на точность:
        // шаг мельчает, когда шагов не находится или они почти ничего
        // не дают, и круг кончается, когда шаг стал меньше точности.
        // Повторять оба круга друг за другом бессмысленно: они меряют разное
        // и перетягивают рисунок друг у друга.
        foreach ([false, true] as $even) {
            $step = $even ? $ideal / 4 : $ideal / 2;
            $mark = $this->getScore($coordinates, $lines);

            for ($round = 0; $round < self::EVEN_ROUNDS && $step > $this->accuracy; $round++) {
                // Шаги ещё находятся, но рисунок почти не лучшеет: вершины
                // топчутся около своего места. Тогда шаг мельче.
                if ($round % self::EVEN_PATIENCE === self::EVEN_PATIENCE - 1) {
                    $score = $this->getScore($coordinates, $lines);

                    if (! $this->hasProgress($score, $mark)) {
                        $step /= 2;
                    }

                    $mark = $score;
                }

                $previous = $coordinates;
                $moves = [];

                foreach ($movable as $vertex) {
                    $moved = $this->getEvenMove($vertex, $coordinates, $lines, $incident, $step, $even);

                    if ($moved !== null) {
                        $coordinates[$vertex] = $moved;
                        $moves[] = [$vertex, $moved];
                    }
                }

                if ($moves === []) {
                    $step /= 2;

                    continue;
                }

                // Вершины шагали по очереди, а картинка проигрывает шаг разом:
                // если по дороге что-то пересекается, шаг идёт по одной вершине.
                if (! $this->motion->isTransitionPlanar($previous, $coordinates, $lines)) {
                    $coordinates = $previous;

                    foreach ($moves as [$vertex, $moved]) {
                        $coordinates[$vertex] = $moved;
                        $steps[] = $coordinates;
                    }

                    continue;
                }

                $steps[] = $coordinates;
            }
        }


        return $steps;
    }

    /**
     * Лучший шаг вершины по просветам всего рисунка, или null.
     *
     * Для каждой другой вершины заранее считается её просвет без участия
     * этой: пока шагает одна, у остальных меняются только расстояния до неё
     * и до её рёбер.
     *
     * @param Point2D[] $coordinates
     * @param array<int, array{int, int}> $lines
     * @param array<int, int[]> $incident
     */
    private function getEvenMove(int $vertex, array $coordinates, array $lines, array $incident, float $step, bool $even): ?Point2D
    {
        $base = [];

        foreach ($coordinates as $other => $point) {
            if ($other === $vertex) {
                continue;
            }

            $clearance = INF;

            foreach ($coordinates as $third => $thirdPoint) {
                if ($third !== $other && $third !== $vertex) {
                    $clearance = min($clearance, $this->geometry->distance($point, $thirdPoint));
                }
            }

            foreach ($lines as [$vertexA, $vertexB]) {
                if ($vertexA !== $other && $vertexB !== $other && $vertexA !== $vertex && $vertexB !== $vertex) {
                    $clearance = min($clearance, $this->geometry->distanceToSegment($point, $coordinates[$vertexA], $coordinates[$vertexB]));
                }
            }

            $base[$other] = $clearance;
        }

        $own = $incident[$vertex] ?? [];
        $clearances = function (Point2D $at) use ($vertex, $coordinates, $lines, $own, $base): array {
            $result = [];
            $mine = INF;

            foreach ($base as $other => $clearance) {
                $point = $coordinates[$other];
                $distance = $this->geometry->distance($point, $at);
                $mine = min($mine, $distance);
                $clearance = min($clearance, $distance);

                foreach ($own as $neighbour) {
                    if ($neighbour !== $other) {
                        $clearance = min($clearance, $this->geometry->distanceToSegment($point, $at, $coordinates[$neighbour]));
                    }
                }

                $result[] = $clearance;
            }

            foreach ($lines as [$vertexA, $vertexB]) {
                if ($vertexA !== $vertex && $vertexB !== $vertex) {
                    $mine = min($mine, $this->geometry->distanceToSegment($at, $coordinates[$vertexA], $coordinates[$vertexB]));
                }
            }

            $result[] = $mine;
            sort($result);

            return $result;
        };

        $origin = $coordinates[$vertex];
        $best = $clearances($origin);
        $result = null;

        for ($direction = 0; $direction < self::EVEN_DIRECTIONS; $direction++) {
            $angle = 2 * M_PI * $direction / self::EVEN_DIRECTIONS;
            $candidate = new Point2D($origin->x + cos($angle) * $step, $origin->y + sin($angle) * $step);
            $score = $clearances($candidate);

            if (($even ? $this->compareEven($score, $best) : $this->compareLeximin($score, $best)) > 0 && $this->isFreeMove($vertex, $candidate, $own, $coordinates, $lines)) {
                [$best, $result] = [$score, $candidate];
            }
        }

        return $result;
    }

    /**
     * Стал ли рисунок заметно лучше: самый узкий просвет шире или просветы
     * в целом шире.
     *
     * @param float[] $now просветы по возрастанию
     * @param float[] $before
     */
    private function hasProgress(array $now, array $before): bool
    {
        $sum = static fn (array $clearances): float => array_sum(array_map(
            static fn (float $clearance): float => log(max($clearance, 1e-9)),
            $clearances,
        ));

        return ($now[0] ?? .0) > ($before[0] ?? .0) + 10 * $this->accuracy
            || $sum($now) > $sum($before) + 1e-2;
    }

    /**
     * Просветы по возрастанию: чей первый заметно отличающийся шире, та
     * укладка и лучше.
     *
     * @param float[] $a
     * @param float[] $b
     */
    private function compareLeximin(array $a, array $b): int
    {
        foreach ($a as $number => $value) {
            $other = $b[$number] ?? .0;

            if (abs($value - $other) > $this->accuracy / 10) {
                return $value <=> $other;
            }
        }

        return 0;
    }

    /**
     * Какая укладка ровнее. Самый узкий просвет проседать не должен; при
     * этом условии лучше та, где просветы в целом ровнее и шире — по сумме
     * логарифмов: тесная вершина весит больше просторной, но и просторная
     * может уступить немного из своего запаса, если тесной от этого
     * заметно легче.
     *
     * @param float[] $a просветы по возрастанию
     * @param float[] $b
     */
    private function compareEven(array $a, array $b): int
    {
        if (($a[0] ?? .0) < ($b[0] ?? .0) - $this->accuracy / 10) {
            return -1;
        }

        $sum = static fn (array $clearances): float => array_sum(array_map(
            static fn (float $clearance): float => log(max($clearance, 1e-9)),
            $clearances,
        ));
        $difference = $sum($a) - $sum($b);

        return abs($difference) < 1e-4 ? 0 : ($difference > 0 ? 1 : -1);
    }

    /**
     * Проедет ли вершина по прямой, не задев рёбрами ничего чужого. Сама
     * вершина через ребро пройти не может, не задев его своими рёбрами.
     *
     * @param int[] $neighbours
     * @param Point2D[] $coordinates
     * @param array<int, array{int, int}> $edges
     */
    private function isFreeMove(int $vertex, Point2D $target, array $neighbours, array $coordinates, array $edges): bool
    {
        $origin = $coordinates[$vertex];

        // Сама вершина не переходит через чужое ребро — даже через ребро,
        // которое сходится с её собственным в соседе: иначе у соседа
        // меняется порядок рёбер, а с ним и грани.
        foreach ($edges as [$vertexA, $vertexB]) {
            if ($vertexA !== $vertex && $vertexB !== $vertex
                && $this->geometry->segmentsIntersect($origin, $target, $coordinates[$vertexA], $coordinates[$vertexB])
            ) {
                return false;
            }
        }

        foreach ($neighbours as $neighbour) {
            $point = $coordinates[$neighbour];

            foreach ($edges as [$vertexA, $vertexB]) {
                if ($vertexA === $vertex || $vertexB === $vertex || $vertexA === $neighbour || $vertexB === $neighbour) {
                    continue;
                }

                $a = $coordinates[$vertexA];
                $b = $coordinates[$vertexB];

                if ($this->geometry->segmentsIntersectDuringMotion($origin, $target, $point, $point, $a, $a, $b, $b)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Насколько укладка читаема: у каждой вершины — просвет, расстояние до
     * ближайшей другой вершины и до ближайшего чужого ребра; просветы по
     * возрастанию. Чем они больше, тем меньше нужно приближать картинку.
     *
     * Одного расстояния между вершинами мало: вершина может стоять далеко
     * от всех вершин и при этом лежать на чужом ребре — и выглядит это как
     * пересечение, которого нет.
     *
     * И одного самого узкого просвета мало: если в укладке слиплись две пары,
     * то, разведя одну, узкий просвет остаётся тем же — и шаг, который
     * улучшил картинку, выбрасывается как ничего не давший. Поэтому
     * укладки сравниваются по всем просветам сразу (`compareScores`).
     *
     * @param Point2D[] $coordinates
     * @param array<int, array{int, int}> $edges
     *
     * @return float[]
     */
    private function getScore(array $coordinates, array $edges): array
    {
        $clearances = array_fill_keys(array_keys($coordinates), INF);
        $vertexes = array_keys($coordinates);
        $count = count($vertexes);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $distance = $this->geometry->distance($coordinates[$vertexes[$i]], $coordinates[$vertexes[$j]]);
                $clearances[$vertexes[$i]] = min($clearances[$vertexes[$i]], $distance);
                $clearances[$vertexes[$j]] = min($clearances[$vertexes[$j]], $distance);
            }
        }

        foreach ($coordinates as $vertex => $point) {
            foreach ($edges as [$vertexA, $vertexB]) {
                if ($vertex !== $vertexA && $vertex !== $vertexB) {
                    $clearances[$vertex] = min($clearances[$vertex], $this->geometry->distanceToSegment($point, $coordinates[$vertexA], $coordinates[$vertexB]));
                }
            }
        }

        $result = array_values(array_map(static fn (float $clearance): float => $clearance === INF ? .0 : $clearance, $clearances));
        sort($result);

        return $result;
    }

    /**
     * Длина ребра, при которой вершины равномерно заполняют внешнюю грань.
     *
     * @param Point2D[] $coordinates
     */
    private function getIdealLength(Edge $outerEdge, array $coordinates): float
    {
        $polygon = array_map(static fn (int $vertex): Point2D => $coordinates[$vertex], $outerEdge->vertexes);
        $area = abs($this->geometry->doubleArea($polygon)) / 2;
        $count = max(count($coordinates), 1);

        return $area > 0 ? sqrt($area / $count) : 1.0;
    }

    /**
     * Соседи каждой вершины по граням.
     *
     * Сосед стоит и ключом, и значением: значения нужны обходу соседей,
     * а ключи — построению списка рёбер (`MotionService::getEdges`).
     *
     * @param Edge[] $faces
     *
     * @return array<int, array<int, int>>
     */
    private function getNeighbours(array $faces): array
    {
        $result = [];

        foreach ($faces as $face) {
            $vertexes = $face->vertexes;
            $count = count($vertexes);

            for ($i = 0; $i < $count; $i++) {
                $current = $vertexes[$i];
                $next = $vertexes[($i + 1) % $count];
                $result[$current][$next] = $next;
                $result[$next][$current] = $current;
            }
        }

        return $result;
    }

    /**
     * @param int[] $vertexes
     *
     * @return Point2D[]
     */
    private function getCircle(array $vertexes, float $radius, ?Point2D $center = null, ?Angle $startAngle = null): array
    {
        $vertexes = array_values(array_unique($vertexes));
        $vertexCount = count($vertexes);

        if ($vertexCount === 0) {
            return [];
        }

        $center ??= new Point2D();

        if ($vertexCount === 1) {
            return array_fill_keys($vertexes, $center);
        }

        $startAngle ??= new Angle();
        $angle = Angle::pi(2)->divided($vertexCount);
        $result = [];
        $pos = 0;

        foreach ($vertexes as $vertex) {
            $result[$vertex] = $this->getPoint($radius, $startAngle->plus($angle->times($pos)), $center);
            $pos++;
        }

        return $result;
    }

    private function getPoint(float $distance, Angle $angle, Point2D $center): Point2D
    {
        return new Point2D($center->x + $distance * $angle->sin(), $center->y + $distance * -$angle->cos());
    }

    /** @return float[] */
    public function getIndexes(Arc $arc): array
    {
        $result = [];
        $count = count($arc->vertexes);

        foreach ($arc->vertexes as $num1 => $vertexes) {
            $total = count($vertexes) * $count;
            $prevCount = $num1 * count($vertexes);

            foreach ($vertexes as $num2 => $vertex) {
                // Единственная точка дуги не с чем распределять — ставим её посередине.
                $result[$vertex] = $total > 1 ? (float) ($num2 + $prevCount) / ($total - 1) : 0.5;
            }
        }

        return $result;
    }

    /**
     * @param Point2D[] $coordinates
     */
    private function getGravityCenter(GravityInterface $gravity, array $coordinates): Point2D
    {
        $points = $gravity->getItems();
        $x = 0;
        $y = 0;

        /** @var int|GravityInterface $point */
        foreach ($points as $point) {
            $coordinate = $point instanceof GravityInterface
                ? $this->getGravityCenter($point, $coordinates)
                : $coordinates[$point];
            $x += $coordinate->x;
            $y += $coordinate->y;
        }

        return new Point2D(
            $x / count($points),
            $y / count($points)
        );
    }

    private function bezierPoint(Point2D $begin, Point2D $center, Point2D $end, float $index): Point2D
    {
        $index1 = pow(1 - $index, 2);
        $index2 = 2 * $index * (1 - $index);
        $index3 = pow($index, 2);

        return new Point2D(
            $index1 * $begin->x + $index2 * $center->x + $index3 * $end->x,
            $index1 * $begin->y + $index2 * $center->y + $index3 * $end->y,
        );
    }
}
