<?php

declare(strict_types=1);

namespace Tests;

use EugeneErg\Graphs\Services\PlaceService;
use EugeneErg\Graphs\ValueObjects\Point2D;

/**
 * Геометрия рассказа: три правила, по которым всё встаёт на места.
 *
 * Тесты заведены вместе со слоем мест. До него эти правила выводились заново
 * в каждом методе, который что-нибудь ставил, — и проверить их было негде:
 * проверялась картинка целиком, а не правило. Теперь правило одно, и сломать
 * его молча нельзя.
 */
final class PlaceServiceTest extends AbstractTestCase
{
    /**
     * Кусок лежит кругом, и на круге он занимает столько места, сколько
     * просили: соседи не ближе заданного расстояния.
     */
    public function testCircleKeepsTheAskedDistance(): void
    {
        $place = new PlaceService();

        foreach ([2, 3, 5, 8, 21] as $count) {
            $order = range(0, $count - 1);
            $points = $place->getCircle($order, new Point2D(10, -20), $place->getPieceRadius($count, 30.0));

            self::assertCount($count, $points);

            foreach ($order as $number) {
                $next = $points[$order[($number + 1) % $count]];
                $point = $points[$number];

                self::assertGreaterThanOrEqual(
                    29.999,
                    hypot($point->x - $next->x, $point->y - $next->y),
                    "На круге из $count вершин соседи встали ближе, чем просили.",
                );
            }
        }
    }

    /**
     * Порядок сохраняется: в каком порядке вершины отдали, в таком они
     * и идут по кругу. Иначе кусок, уехавший на свой стол, оказывается
     * перемешанным, и что уехало — не прочесть.
     */
    public function testCircleKeepsTheOrder(): void
    {
        $place = new PlaceService();
        $center = new Point2D(7, 3);
        $points = $place->getCircle([4, 9, 1, 6], $center, 50.0, 0.3);
        $angles = [];

        foreach ($points as $vertex => $point) {
            $angles[$vertex] = atan2($point->y - $center->y, $point->x - $center->x);
        }

        self::assertSame([4, 9, 1, 6], array_keys($points));

        $previous = null;

        foreach ($angles as $angle) {
            if ($previous !== null) {
                // Шаг по кругу один и тот же, и идёт он в одну сторону:
                // порядок, в котором вершины отдали, и есть порядок обхода.
                $step = fmod($angle - $previous + 2 * M_PI, 2 * M_PI);

                self::assertEqualsWithDelta(2 * M_PI / 4, $step, 1.0e-9);
            }

            $previous = $angle;
        }
    }

    /**
     * Круг, на котором не поместиться нельзя.
     *
     * Кусков может быть сколько угодно и любого размера — радиус подбирается
     * так, чтобы все легли и не задели ни друг друга, ни то, что лежит
     * в середине.
     */
    public function testRingFitsEveryPiece(): void
    {
        $place = new PlaceService();
        $cases = [
            [10.0, 10.0, 10.0],
            [80.0, 5.0, 5.0, 5.0],
            [30.0, 30.0, 30.0, 30.0, 30.0, 30.0, 30.0, 30.0, 30.0],
            [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0, 11.0, 12.0],
        ];

        foreach ($cases as $radii) {
            $inside = 40.0;
            $gap = 15.0;
            $ring = $place->getRing($radii, $inside, $gap);

            self::assertGreaterThanOrEqual(
                $inside + max($radii),
                $ring,
                'Кольцо налезло на то, что лежит в середине.',
            );
            self::assertLessThanOrEqual(
                2 * M_PI + 1.0e-9,
                $place->getFill($radii, $ring, $gap),
                'Куски не уложились в оборот: какие-то лежат друг на друге.',
            );

            $places = $place->getPlaces($radii, $ring, $gap);

            self::assertCount(count($radii), $places);

            $centers = [];

            foreach ($places as $number => $angle) {
                $centers[$number] = $place->getOnAngle(new Point2D(), $ring, $angle);
            }

            foreach ($centers as $first => $point) {
                foreach ($centers as $second => $other) {
                    if ($first >= $second) {
                        continue;
                    }

                    self::assertGreaterThanOrEqual(
                        $radii[$first] + $radii[$second],
                        hypot($point->x - $other->x, $point->y - $other->y),
                        'Два куска на кольце налезли друг на друга.',
                    );
                }
            }
        }
    }

    /**
     * Вынесенное наружу уезжает по своему радиусу: угол не меняется, поэтому
     * обход не перекручивается по дороге.
     */
    public function testOnRingKeepsTheAngle(): void
    {
        $place = new PlaceService();
        $center = new Point2D(-5, 12);

        foreach ([new Point2D(20, 12), new Point2D(-5, -40), new Point2D(60, 90)] as $point) {
            $moved = $place->getOnRing($point, $center, 77.0);

            self::assertEqualsWithDelta(77.0, hypot($moved->x - $center->x, $moved->y - $center->y), 1.0e-9);
            self::assertEqualsWithDelta(
                atan2($point->y - $center->y, $point->x - $center->x),
                atan2($moved->y - $center->y, $moved->x - $center->x),
                1.0e-9,
                'Точку унесло не по своему лучу — обход перекрутится.',
            );
        }
    }

    /** Из середины уезжать некуда, поэтому направление выбирается любое. */
    public function testOnRingFromTheCentreGoesSomewhere(): void
    {
        $place = new PlaceService();
        $center = new Point2D(3, 4);
        $moved = $place->getOnRing($center, $center, 10.0);

        self::assertEqualsWithDelta(10.0, hypot($moved->x - $center->x, $moved->y - $center->y), 1.0e-9);
    }

    /**
     * Поворот ставит круг так, чтобы вершины оказались ближе всего к тому,
     * где они лежали: кусок уезжает на свой стол, а не проворачивается.
     */
    public function testTurnKeepsVertexesNearWhereTheyWere(): void
    {
        $place = new PlaceService();
        $center = new Point2D();
        $was = [];

        // Пять вершин ровным кругом, но повёрнутым на треть радиана: если
        // поворот считается правильно, равномерная раскладка встанет ровно
        // на них.
        foreach (range(0, 4) as $number) {
            $angle = 0.33 + 2 * M_PI * $number / 5;
            $was[$number] = new Point2D(100 * cos($angle), 100 * sin($angle));
        }

        $angles = $place->getAngles(array_keys($was), $was);
        $points = $place->getCircle(array_keys($angles), $center, 100.0, $place->getTurn($angles));

        foreach ($was as $vertex => $point) {
            self::assertEqualsWithDelta(
                .0,
                hypot($points[$vertex]->x - $point->x, $points[$vertex]->y - $point->y),
                1.0e-3,
                'Круг провернулся: вершина встала не туда, где лежала.',
            );
        }
    }

    /** Отрезанный кусок ложится обходом от верха: поле выглядит полем. */
    public function testWheelStartsAtTheTop(): void
    {
        $place = new PlaceService();
        $center = new Point2D(50, 50);
        $points = $place->getWheel([2, 5, 7, 8], $center, 10.0);

        self::assertEqualsWithDelta(50.0, $points[2]->x, 1.0e-9);
        self::assertEqualsWithDelta(40.0, $points[2]->y, 1.0e-9);
        self::assertEqualsWithDelta(60.0, $points[5]->x, 1.0e-9);
        self::assertEqualsWithDelta(50.0, $points[5]->y, 1.0e-9);
        self::assertEqualsWithDelta(50.0, $points[7]->x, 1.0e-9);
        self::assertEqualsWithDelta(60.0, $points[7]->y, 1.0e-9);
        self::assertEqualsWithDelta(40.0, $points[8]->x, 1.0e-9);
        self::assertEqualsWithDelta(50.0, $points[8]->y, 1.0e-9);
    }

    /** Одинокая вершина лежит в середине: круга из одной точки не бывает. */
    public function testOneVertexLiesInTheMiddle(): void
    {
        $place = new PlaceService();
        $center = new Point2D(1, 2);

        self::assertSame($center, $place->getCircle([3], $center, 40.0)[3]);
        self::assertSame($center, $place->getWheel([3], $center, 40.0)[3]);
    }

    /** Пустого кольца не бывает: просить радиус не для кого. */
    public function testEmptyRingHasNoRadius(): void
    {
        $place = new PlaceService();

        self::assertSame(.0, $place->getRing([], 100.0, 10.0));
        self::assertSame([], $place->getPlaces([], 100.0, 10.0));
        self::assertSame([], $place->getPlaces([10.0], .0, 10.0));
    }
}
