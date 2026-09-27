<?php

declare(strict_types=1);

namespace Tests;

use EugeneErg\Graphs\Services\PlaceService;
use EugeneErg\Graphs\ValueObjects\Point2D;
use EugeneErg\Graphs\ValueObjects\Table;

/**
 * Четыре места на столе, и все они друг за другом.
 *
 * Это ровно то, что должно быть видно на разборе: внутри — то, что
 * разбирают; на среднем круге — запертый обход, про который спрашивают;
 * снаружи — то, что по проверке внутрь не поместилось; и ещё дальше —
 * места, где ждёт уже вырезанное.
 *
 * Раньше это правило было написано в пяти методах сразу, и «запертые
 * на средний радиус» приходилось чинить в каждом. Теперь оно одно.
 */
final class TableTest extends AbstractTestCase
{
    public function testEveryPlaceHasItsOwnCircle(): void
    {
        $place = new PlaceService();
        $center = new Point2D(30, -40);
        $home = $place->getCircle([0, 1, 2, 3, 4], $center, 100.0);
        $table = new Table(
            center: $center,
            home: $home,
            radius: 100.0,
            ring: 400.0,
            slots: [1 => new Point2D(30, -440)],
        );

        $at = static fn (Point2D $point): float => hypot($point->x - 30, $point->y + 40);

        foreach ($home as $point) {
            self::assertEqualsWithDelta(100.0, $at($point), 1.0e-9, 'Дом — на домашнем круге.');
        }

        $locked = $at($table->locked(0, 5));
        $aside = $table->around();
        $slot = $at($table->slot(1));

        self::assertGreaterThan(100.0, $locked, 'Запертый обход должен выйти за домашний круг.');
        self::assertGreaterThan($locked, $aside, 'Вынесенное наружу — дальше запертого обхода.');
        self::assertGreaterThan($aside, $slot, 'Места для вырезанного — дальше всего.');
    }

    /**
     * Запертый обход ложится на средний круг многоугольником, по своему же
     * порядку: кандидат в грань и должен выглядеть гранью, а не горстью
     * точек там, где они лежали.
     */
    public function testLockedWalkLiesAsAPolygon(): void
    {
        $table = new Table(center: new Point2D(), radius: 100.0, ring: 400.0);
        $count = 6;
        $points = [];

        for ($number = 0; $number < $count; $number++) {
            $points[] = $table->locked($number, $count);
        }

        $first = hypot($points[0]->x, $points[0]->y);

        foreach ($points as $number => $point) {
            self::assertEqualsWithDelta($first, hypot($point->x, $point->y), 1.0e-9);

            $next = $points[($number + 1) % $count];

            self::assertEqualsWithDelta(
                hypot($points[0]->x - $points[1]->x, $points[0]->y - $points[1]->y),
                hypot($point->x - $next->x, $point->y - $next->y),
                1.0e-9,
                'Стороны многоугольника разной длины — обход лёг не по порядку.',
            );
        }
    }

    /** Готовая укладка переносится на свой стол целиком, не искажаясь. */
    public function testLayoutMovesToTheTableWhole(): void
    {
        $table = new Table(center: new Point2D(), shift: new Point2D(7, -3));
        $first = $table->shifted(new Point2D(10, 10));
        $second = $table->shifted(new Point2D(40, 50));

        self::assertSame(17.0, $first->x);
        self::assertSame(7.0, $first->y);
        self::assertSame(30.0, $second->x - $first->x);
        self::assertSame(40.0, $second->y - $first->y);
    }
}
