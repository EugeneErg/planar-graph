<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects;

/**
 * Стол: место, на котором разбирают один кусок графа.
 *
 * У каждого несвязного куска свой стол, и всё, что из куска вырезают,
 * остаётся на нём же. Столы не задевают друг друга, поэтому два несвязных
 * графа не мешают друг другу ни при нарезке, ни при сборке.
 *
 * На столе четыре места, и это все места, какие есть в рассказе:
 *
 * - **дом** — где вершина лежит, пока её не трогают (`home`);
 * - **место вокруг** — куда уезжает вырезанное (`slot`);
 * - **средний круг** — куда выносят запертый обход: про него спрашивают,
 *   поэтому его и вынимают из куска (`locked`);
 * - **внешний круг** — куда уезжает то, что по проверке внутрь обхода
 *   не поместилось (`aside`).
 *
 * Стол — единственное место, где написано, где эти четыре находятся.
 * Остальной рассказ только называет место, а не считает координаты.
 */
final readonly class Table
{
    /**
     * Кругов на столе три, и на время разбора кусок раскладывается по ним.
     *
     * Внутри остаётся то, что разбирают. На среднем круге — запертый обход.
     * Снаружи — то, что по проверке внутрь обхода не поместилось. Так видно
     * и вопрос, и ответ.
     */
    private const float LOCKED_RING = 1.35;

    /** Насколько вынесенное наружу отходит от домашнего круга. */
    private const float AROUND_RING = 1.2;

    /**
     * @param Point2D[] $home домашнее место каждой вершины стола: посчитано
     *                        раз и навсегда, поэтому на месте вынутого куска
     *                        остаётся дыра, а остальные стоят как стояли
     * @param Point2D[] $slots места вокруг: куда уезжает вырезанное
     * @param float $radius домашний круг
     * @param float $ring внешний круг
     * @param Point2D $shift насколько подвинуть готовую укладку, чтобы она
     *                       собиралась здесь, а не там, где её посчитали
     */
    public function __construct(
        public Point2D $center,
        public array $home = [],
        public float $radius = .0,
        public float $ring = .0,
        public float $turn = .0,
        public array $slots = [],
        public Point2D $shift = new Point2D(),
    ) {
    }

    /**
     * Место вокруг стола, на котором ждёт вырезанное.
     */
    public function slot(int $place): Point2D
    {
        return $this->slots[$place] ?? new Point2D();
    }

    /**
     * Точка запертого обхода: обход ложится на средний круг многоугольником,
     * по своему же порядку, — тогда кандидат в грань и выглядит гранью,
     * а не горстью точек там, где они лежали.
     */
    public function locked(int $number, int $count): Point2D
    {
        $angle = 2 * M_PI * $number / max($count, 1);
        $radius = $this->radius * self::LOCKED_RING;

        return new Point2D(
            $this->center->x + $radius * sin($angle),
            $this->center->y - $radius * cos($angle),
        );
    }

    /**
     * Радиус, на который уезжает вынесенное наружу: за домашний круг, но не
     * до кольца мест — там лежит уже вырезанное.
     */
    public function around(): float
    {
        return ($this->radius * self::AROUND_RING + $this->ring) / 2;
    }

    /**
     * Готовая укладка, перенесённая на этот стол.
     */
    public function shifted(Point2D $point): Point2D
    {
        return new Point2D($point->x + $this->shift->x, $point->y + $this->shift->y);
    }
}
