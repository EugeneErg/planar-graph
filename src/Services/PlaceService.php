<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\ValueObjects\Point2D;

/**
 * Геометрия рассказа: круги, кольца и точки на них.
 *
 * Здесь нет ни кусков, ни кадров, ни алгоритма — только «сколько места надо»
 * и «где эта точка». Всё, что считает координаты, считает их отсюда.
 *
 * Заведено потому, что иначе одни и те же три правила выводились заново
 * в каждом методе, который что-нибудь ставил на место: кусок лежит кругом
 * в порядке обхода, вырезанное раскладывается вокруг родителя по кольцу,
 * вынесенное отодвигается от середины, сохраняя угол. Правил три, а мест,
 * где они были написаны, было одиннадцать — и каждая правка попадала
 * в половину из них.
 */
final readonly class PlaceService
{
    /**
     * Радиус круга, на котором `$count` точек стоят не ближе `$spacing`
     * друг к другу.
     */
    public function getPieceRadius(int $count, float $spacing): float
    {
        return $count < 2 ? $spacing / 2 : max($spacing, $spacing / (2 * sin(M_PI / $count)));
    }

    /**
     * Точки ровным кругом, в заданном порядке и с заданным поворотом.
     *
     * Так лежит кусок у себя дома: круг считается по тому, что в куске
     * осталось, поэтому после каждого разреза оставшиеся расходятся ровно.
     * А поворот приходит снаружи и не меняется, иначе весь остаток
     * проворачивался бы после каждого разреза.
     *
     * @param int[] $order вершины в том порядке, в каком они лягут на круг
     *
     * @return Point2D[]
     */
    public function getCircle(array $order, Point2D $center, float $radius, float $turn = .0): array
    {
        $count = count($order);
        $result = [];
        $number = 0;

        foreach ($order as $vertex) {
            $angle = $turn + 2 * M_PI * $number / max($count, 1);
            $result[$vertex] = $count === 1
                ? $center
                : new Point2D($center->x + $radius * cos($angle), $center->y + $radius * sin($angle));
            $number++;
        }

        return $result;
    }

    /**
     * Точки по кругу в порядке обхода, начиная сверху.
     *
     * Так лежит отрезанный кусок на своём месте: обход идёт по кругу подряд,
     * поэтому поле и выглядит многоугольником, а не горстью точек.
     *
     * @param int[] $order
     *
     * @return Point2D[]
     */
    public function getWheel(array $order, Point2D $center, float $radius): array
    {
        $order = array_values($order);
        $count = count($order);
        $result = [];

        foreach ($order as $position => $vertex) {
            $result[$vertex] = $count === 1
                ? $center
                : $this->getOnAngle($center, $radius, 2 * M_PI * $position / $count);
        }

        return $result;
    }

    /**
     * Та же точка, но отодвинутая от середины на заданный радиус: угол
     * сохраняется, поэтому обход не перекручивается.
     */
    public function getOnRing(Point2D $point, Point2D $center, float $radius): Point2D
    {
        $distance = hypot($point->x - $center->x, $point->y - $center->y);

        if ($distance < GeometryService::EPSILON) {
            return new Point2D($center->x + $radius, $center->y);
        }

        return new Point2D(
            $center->x + ($point->x - $center->x) * $radius / $distance,
            $center->y + ($point->y - $center->y) * $radius / $distance,
        );
    }

    /**
     * Точка на кольце под заданным углом. Угол отсчитывается от верха
     * по часовой стрелке — в эту же сторону идут места вокруг стола.
     */
    public function getOnAngle(Point2D $center, float $radius, float $angle): Point2D
    {
        return new Point2D($center->x + $radius * sin($angle), $center->y - $radius * cos($angle));
    }

    /**
     * Углы вершин вокруг середины, по возрастанию: в этом порядке они
     * и лягут на круг, чтобы не перемешаться.
     *
     * @param int[] $vertexes
     * @param Point2D[] $coordinates
     *
     * @return array<int, float>
     */
    public function getAngles(array $vertexes, array $coordinates): array
    {
        $center = $this->getCenter($coordinates);
        $result = [];

        foreach ($vertexes as $vertex) {
            $point = $coordinates[$vertex] ?? $center;
            $result[$vertex] = atan2($point->y - $center->y, $point->x - $center->x);
        }

        asort($result);

        return $result;
    }

    /**
     * На сколько повернуть равномерный круг, чтобы вершины встали ближе всего
     * к своим местам: средний угол между тем, где вершина стояла, и тем, куда
     * её кладёт равномерная раскладка.
     *
     * @param array<int, float> $angles вершина => её угол, по возрастанию
     */
    public function getTurn(array $angles): float
    {
        $count = count($angles);

        if ($count === 0) {
            return .0;
        }

        $x = .0;
        $y = .0;
        $number = 0;

        foreach ($angles as $angle) {
            $turn = $angle - 2 * M_PI * $number / $count;
            $x += cos($turn);
            $y += sin($turn);
            $number++;
        }

        return $x === .0 && $y === .0 ? (float) reset($angles) : atan2($y, $x);
    }

    /**
     * Радиус кольца: такой, чтобы на нём поместились все куски.
     *
     * Кусков может быть сколько угодно и любого размера, но каждый меньше
     * целого, поэтому радиус всегда есть — его и находим. Кусок на кольце
     * занимает сектор в два `asin((радиус + зазор) / кольцо)`: чем дальше
     * кольцо, тем меньше сектор, поэтому кольцо раздвигается, пока сумма
     * секторов не уложится в полный оборот. Заодно кольцо обходит середину,
     * где лежит сам кусок.
     *
     * @param array<int, float> $radii место => радиус самого большого куска на нём
     */
    public function getRing(array $radii, float $outside, float $gap): float
    {
        if ($radii === []) {
            return .0;
        }

        $low = $outside + max($radii) + $gap;
        $high = $low;

        while ($this->getFill($radii, $high, $gap) > 2 * M_PI) {
            $high *= 2;
        }

        for ($step = 0; $step < 60; $step++) {
            $middle = ($low + $high) / 2;

            if ($this->getFill($radii, $middle, $gap) > 2 * M_PI) {
                $low = $middle;

                continue;
            }

            $high = $middle;
        }

        return $high;
    }

    /**
     * Куда на кольце смотрит каждое место: куски идут подряд по своим
     * секторам, а свободный остаток оборота делится между ними поровну.
     *
     * @param array<int, float> $radii
     *
     * @return array<int, float>
     */
    public function getPlaces(array $radii, float $ring, float $gap): array
    {
        if ($radii === [] || $ring <= .0) {
            return [];
        }

        $slack = max(2 * M_PI - $this->getFill($radii, $ring, $gap), .0) / count($radii);
        $angle = .0;
        $result = [];

        foreach ($radii as $place => $radius) {
            $half = asin(min(($radius + $gap / 2) / $ring, 1.0));
            $angle += $half;
            $result[$place] = $angle;
            $angle += $half + $slack;
        }

        return $result;
    }

    /**
     * Сколько оборота займут куски на кольце такого радиуса.
     *
     * @param array<int, float> $radii
     */
    public function getFill(array $radii, float $ring, float $gap): float
    {
        $result = .0;

        foreach ($radii as $radius) {
            $part = ($radius + $gap / 2) / $ring;
            $result += $part >= 1.0 ? M_PI : 2 * asin($part);
        }

        return $result;
    }

    /**
     * Координаты только этих вершин.
     *
     * @param int[] $vertexes
     * @param Point2D[] $coordinates
     *
     * @return Point2D[]
     */
    public function getPoints(array $vertexes, array $coordinates): array
    {
        $result = [];

        foreach ($vertexes as $vertex) {
            if (isset($coordinates[$vertex])) {
                $result[$vertex] = $coordinates[$vertex];
            }
        }

        return $result;
    }

    /**
     * Насколько далеко точки уходят от центра.
     *
     * @param Point2D[] $points
     */
    public function getRadius(Point2D $center, array $points): float
    {
        $result = .0;

        foreach ($points as $point) {
            $result = max($result, sqrt(($point->x - $center->x) ** 2 + ($point->y - $center->y) ** 2));
        }

        return $result;
    }

    /**
     * @param Point2D[] $points
     */
    public function getCenter(array $points): Point2D
    {
        if ($points === []) {
            return new Point2D();
        }

        $left = INF;
        $right = -INF;
        $top = INF;
        $bottom = -INF;

        foreach ($points as $point) {
            $left = min($left, $point->x);
            $right = max($right, $point->x);
            $top = min($top, $point->y);
            $bottom = max($bottom, $point->y);
        }

        return new Point2D(($left + $right) / 2, ($top + $bottom) / 2);
    }
}
