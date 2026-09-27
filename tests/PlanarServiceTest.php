<?php

declare(strict_types=1);

namespace Tests;

use DOMDocument;
use EugeneErg\Graphs\Aggregates\SliceAggregate;
use EugeneErg\Graphs\Exceptions\InvalidConnectionException;
use EugeneErg\Graphs\Exceptions\InvalidVertexValueException;
use EugeneErg\Graphs\ValueObjects\Point2D;
use EugeneErg\Graphs\ValueObjects\ZeroSlice;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Сквозные проверки: от списка связей до картинки.
 */
final class PlanarServiceTest extends AbstractTestCase
{
    /**
     * Кадры до распутывания: клубок на окружности, затем начальная догадка.
     * Всё, что идёт дальше, обязано быть плоским.
     */
    private const int FIRST_PLANAR_FRAME = 1;

    /**
     * @param true[][] $connections
     *
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     */
    #[DataProvider('getGraphs')]
    public function testLayoutIsPlanar(array $connections): void
    {
        $coordinates = $this->layout($connections);

        self::assertSame(0, self::countCrossings($connections, $coordinates));
    }

    /**
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testEveryVertexGetsCoordinates(array $connections): void
    {
        $coordinates = $this->layout($connections);

        self::assertSame(array_keys($connections), array_keys($coordinates));
    }

    /**
     * Ни одна пара вершин не должна оказаться в одной точке —
     * иначе рисунок нечитаем при любом масштабе.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testVertexesDoNotCollide(array $connections): void
    {
        $coordinates = $this->layout($connections);

        if (count($coordinates) < 2) {
            self::assertCount(1, $coordinates);

            return;
        }

        self::assertGreaterThan(1.0, self::getMinVertexDistance($coordinates));
    }

    /**
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testEveryFrameAfterUntanglingIsPlanar(array $connections): void
    {
        $frames = $this->frames($connections);

        self::assertGreaterThan(self::FIRST_PLANAR_FRAME, count($frames));

        foreach (array_slice($frames, self::FIRST_PLANAR_FRAME) as $number => $frame) {
            self::assertSame(
                0,
                self::countCrossings($connections, $frame),
                sprintf('Кадр %d потерял планарность.', $number + self::FIRST_PLANAR_FRAME),
            );
        }
    }

    /**
     * Нулевой кадр — тот самый клубок: все вершины на одной окружности.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testFirstFrameIsCircleOfAllVertexes(array $connections): void
    {
        $frames = $this->frames($connections);
        $geometry = $this->getGeometryService();
        $center = new Point2D();
        $distances = array_map(
            static fn (Point2D $point): float => round($geometry->distance($center, $point), 6),
            $frames[0],
        );

        self::assertSame(array_keys($connections), array_keys($frames[0]));
        self::assertCount(1, array_unique($distances), 'Вершины клубка должны лежать на одной окружности.');
    }

    /**
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testSvgIsValidXml(array $connections): void
    {
        $svg = $this->getPlanarService()->connectionsToSvg($connections, new SliceAggregate(new ZeroSlice()));
        $document = new DOMDocument();

        self::assertTrue($document->loadXML($svg));
        self::assertSame('svg', $document->documentElement?->tagName);
    }

    public function testSvgWritesNothingToDisk(): void
    {
        $before = glob(getcwd() . '/*.svg');

        $this->getPlanarService()->connectionsToSvg(self::getBig1(), new SliceAggregate(new ZeroSlice()));

        self::assertSame($before, glob(getcwd() . '/*.svg'));
    }

    public function testDisconnectedComponentsDoNotOverlap(): void
    {
        $connections = self::merge(false, self::getSimpleTriangle(), self::getSimpleRectangleCase());

        $coordinates = $this->layout($connections);

        self::assertSame(0, self::countCrossings($connections, $coordinates));
        self::assertSame(array_keys($connections), array_keys($coordinates));
        self::assertGreaterThan(1.0, self::getMinVertexDistance($coordinates));
    }

    public function testLayoutIsDeterministic(): void
    {
        $connections = self::getBig1();

        self::assertEquals($this->layout($connections), $this->layout($connections));
    }

    /**
     * @return array<string, array{true[][]}>
     */
    public static function getGraphs(): array
    {
        return [
            'точка' => [self::getDot()],
            'отрезок' => [self::getLine()],
            'три ребра' => [self::getThreeLines()],
            'треугольник' => [self::getSimpleTriangle()],
            'прямоугольник' => [self::getRectangle()],
            'треугольник в треугольнике' => [self::getTriangleInTriangle()],
            'три вложенных треугольника' => [self::getTriangleInTriangleInTriangle()],
            'дерево с циклом' => [self::getSmallTree()],
            'большой граф' => [self::getBig1()],
            'большой с перешейками' => [self::getBig2()],
            // Колёса: поля прилегают к границе одним ребром и откладываются,
            // а дуга, накрывшая отложенные, собирается из кусков. На ней
            // точка Безье уходила за конец дуги — за внешнюю грань.
            'колёса' => [self::getSevenFields()],
        ];
    }

    /**
     * @return true[][]
     */
    private static function getSimpleRectangleCase(): array
    {
        return self::getRectangle();
    }

    /**
     * @param true[][] $connections
     *
     * @return Point2D[]
     */
    private function layout(array $connections): array
    {
        return $this->getPlanarService()->connectionsToCoordinates($connections, new SliceAggregate(new ZeroSlice()));
    }

    /**
     * @param true[][] $connections
     *
     * @return Point2D[][]
     */
    private function frames(array $connections): array
    {
        return $this->getPlanarService()->connectionsToFrames($connections, new SliceAggregate(new ZeroSlice()));
    }
}
