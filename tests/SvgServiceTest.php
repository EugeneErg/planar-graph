<?php

declare(strict_types=1);

namespace Tests;

use DOMDocument;
use DOMXPath;
use EugeneErg\Graphs\Services\SvgService;
use EugeneErg\Graphs\ValueObjects\Point2D;
use EugeneErg\Graphs\ValueObjects\Scene;
use LogicException;

final class SvgServiceTest extends AbstractTestCase
{
    public function testRenderProducesWellFormedSvg(): void
    {
        $document = $this->parse($this->getSvgService()->render($this->square(), $this->squareConnections()));

        self::assertSame('svg', $document->documentElement?->tagName);
        self::assertSame(4, $this->nodeCount($document, '//svg:path'));
        self::assertSame(4, $this->nodeCount($document, '//svg:g[@class="vertex"]'));
    }

    public function testStaticPictureHasNoAnimation(): void
    {
        $document = $this->parse($this->getSvgService()->render($this->square(), $this->squareConnections()));

        self::assertSame(0, $this->nodeCount($document, '//svg:animate'));
        self::assertSame(0, $this->nodeCount($document, '//svg:animateTransform'));
    }

    public function testAnimationMovesEveryEndpointAndEveryVertex(): void
    {
        $document = $this->parse($this->getSvgService()->animate([
            $this->scene($this->square()),
            $this->scene($this->square(20)),
        ]));

        // Ребро — путь из одного отрезка: оба конца едут одной анимацией.
        self::assertSame(4, $this->nodeCount($document, '//svg:path/svg:animate[@attributeName="d"]'));
        self::assertSame(4, $this->nodeCount($document, '//svg:animateTransform'));
    }

    /**
     * У каждого кадра своя доля времени. Долей за сотню, идут они через
     * тысячные — округлить их до сотых значит слепить соседние в одну,
     * и кадр между ними получит нулевую длину: на картинке выделение
     * и действие сливаются, как будто сначала всё подсветилось, а потом
     * всё разом произошло.
     */
    public function testEveryFrameGetsItsOwnMoment(): void
    {
        $scenes = [];

        for ($number = 0; $number < 150; $number++) {
            $scenes[] = $this->scene($this->square(100 + $number % 2));
        }

        $document = $this->parse($this->getSvgService()->animate($scenes));
        $moments = explode(';', $this->first($document, '//svg:animateTransform/@keyTimes'));

        self::assertCount(count($scenes), $moments);
        self::assertSame(
            count($moments),
            count(array_unique($moments)),
            'Две доли времени совпали — кадр между ними не успеет показаться.',
        );
    }

    public function testAnimationKeepsFirstAndLastPositions(): void
    {
        $document = $this->parse($this->getSvgService()->animate([
            $this->scene($this->square()),
            $this->scene($this->square(40)),
        ]));
        $values = explode(';', $this->first($document, '//svg:animateTransform/@values'));
        // Картинка масштабируется целиком, поэтому сверяем не координаты,
        // а во сколько раз квадрат сжался: со 100 до 40.
        $first = (float) explode(',', $values[0])[0];
        $last = (float) explode(',', $values[count($values) - 1])[0];

        self::assertEqualsWithDelta(0.4, $last / $first, 1.0e-6);
    }

    /**
     * Вершина может оказаться сразу в двух подграфах: когда граф разрезан
     * по точке сочленения, она принадлежит обеим ветвям. Копии заводятся
     * заранее и прячутся там, где их нет.
     */
    public function testDuplicateVertexGetsItsOwnHiddenCopy(): void
    {
        $whole = new Scene([
            Scene::vertexKey(0) => new Point2D(0, 0),
            Scene::vertexKey(1) => new Point2D(100, 0),
        ], []);
        $cut = new Scene([
            Scene::vertexKey(0) => new Point2D(-100, 0),
            Scene::vertexKey(0, 1) => new Point2D(100, 0),
            Scene::vertexKey(1) => new Point2D(200, 0),
        ], []);

        $document = $this->parse($this->getSvgService()->animate([$whole, $cut, $whole]));
        $copies = $this->all($document, '//svg:g[svg:text="0"]');

        self::assertCount(2, $copies, 'Для вершины 0 должно быть два экземпляра.');

        $opacity = $this->all($document, '//svg:g[@class="vertex"]/svg:animate[@attributeName="opacity"]/@values');

        self::assertContains('0;1;0', $opacity, 'Копия видна только в разрезанном кадре.');
    }

    public function testEdgeHidesWhereItIsAbsent(): void
    {
        $with = new Scene([
            Scene::vertexKey(0) => new Point2D(0, 0),
            Scene::vertexKey(1) => new Point2D(100, 0),
        ], [Scene::edgeKey(0, 1) => [new Point2D(0, 0), new Point2D(100, 0)]]);
        $without = new Scene([
            Scene::vertexKey(0) => new Point2D(0, 0),
            Scene::vertexKey(1) => new Point2D(100, 0),
        ], []);

        $document = $this->parse($this->getSvgService()->animate([$with, $without]));

        self::assertSame(
            ['1;0'],
            $this->all($document, '//svg:path/svg:animate[@attributeName="opacity"]/@values'),
        );
    }

    /**
     * Картинка масштабируется так, чтобы ближайшие вершины не слипались:
     * ради этого всё и затевалось.
     */
    public function testLayoutIsScaledSoVertexesDoNotOverlap(): void
    {
        $radius = 11.0;
        $gap = 3.2;
        $service = new SvgService(vertexRadius: $radius, minVertexGap: $gap);
        $tight = [
            0 => new Point2D(0, 0),
            1 => new Point2D(2, 0),
            2 => new Point2D(2, 2),
            3 => new Point2D(0, 2),
        ];

        $document = $this->parse($service->render($tight, $this->squareConnections()));
        $points = [];

        foreach (['0', '1', '2', '3'] as $vertex) {
            $transform = $this->first($document, sprintf('//svg:g[svg:text="%s"]/@transform', $vertex));
            preg_match('/translate\(([-\d.]+),([-\d.]+)\)/', $transform, $matches);
            $points[] = new Point2D((float) $matches[1], (float) $matches[2]);
        }

        self::assertEqualsWithDelta($radius * $gap, self::getMinVertexDistance($points), 0.05);
    }

    /**
     * Вершина не должна налезать на чужое ребро: пересечения нет, а глаз
     * читает его как пересечение. Если укладка тесная, картинка растягивается.
     */
    public function testPictureKeepsVertexesOffForeignEdges(): void
    {
        $radius = 11.0;
        $tight = new Scene(
            [
                Scene::vertexKey(0) => new Point2D(-100, 0),
                Scene::vertexKey(1) => new Point2D(100, 0),
                Scene::vertexKey(2) => new Point2D(0, 2),
                Scene::vertexKey(3) => new Point2D(0, 80),
            ],
            [
                Scene::edgeKey(0, 1) => [new Point2D(-100, 0), new Point2D(100, 0)],
                Scene::edgeKey(2, 3) => [new Point2D(0, 2), new Point2D(0, 80)],
            ],
        );

        $document = $this->parse((new SvgService(vertexRadius: $radius))->animate([$tight, $tight]));
        $points = [];

        foreach (['0', '1', '2', '3'] as $vertex) {
            $transform = $this->first($document, sprintf('//svg:g[svg:text="%s"]/@transform', $vertex));
            preg_match('/translate\(([-\d.]+),([-\d.]+)\)/', $transform, $matches);
            $points[$vertex] = new Point2D((float) $matches[1], (float) $matches[2]);
        }

        self::assertGreaterThan(
            $radius,
            $this->getGeometryService()->distanceToSegment($points['2'], $points['0'], $points['1']),
            'Вершина 2 налезла на ребро 0-1.',
        );
    }

    public function testViewBoxCoversEveryScene(): void
    {
        $service = $this->getSvgService();

        $narrow = $this->viewBoxWidth($service->animate([$this->scene($this->square()), $this->scene($this->square())]));
        $wide = $this->viewBoxWidth($service->animate([
            $this->scene($this->square()),
            $this->scene($this->square(500)),
            $this->scene($this->square()),
        ]));

        self::assertGreaterThan($narrow * 2, $wide);
    }

    /**
     * Текста в картинке нет вовсе, кроме номеров вершин: что происходит,
     * должно быть понятно по самому происходящему.
     */
    public function testPictureHasNoCaptions(): void
    {
        $document = $this->parse($this->getSvgService()->animate([
            $this->scene($this->square()),
            $this->scene($this->square(60)),
            $this->scene($this->square(40)),
        ]));
        $texts = array_map(trim(...), $this->all($document, '//svg:text'));
        sort($texts);

        self::assertSame(['0', '1', '2', '3'], $texts, 'В картинке остаются только номера вершин.');
    }

    public function testGroupsColourVertexes(): void
    {
        $plain = $this->scene($this->square());
        $coloured = new Scene(
            $this->keyed($this->square(60)),
            [],
            [Scene::vertexKey(0) => 1, Scene::vertexKey(1) => 2],
        );

        $document = $this->parse($this->getSvgService()->animate([$plain, $coloured]));
        $opacities = $this->all(
            $document,
            '//svg:g[svg:text="0"]/svg:circle[@class="group"]/svg:animate[@attributeName="fill-opacity"]/@values',
        );

        self::assertSame(['0;0.35'], $opacities);
    }

    public function testHighlightRingsVertexes(): void
    {
        $plain = $this->scene($this->square());
        $ringed = new Scene(
            $this->keyed($this->square(60)),
            [],
            [],
            [Scene::vertexKey(2) => true],
        );

        $document = $this->parse($this->getSvgService()->animate([$plain, $ringed]));

        self::assertSame(
            ['0;1'],
            $this->all($document, '//svg:g[svg:text="2"]/svg:circle[@class="ring"]/svg:animate[@attributeName="opacity"]/@values'),
        );
        self::assertSame(
            [],
            $this->all($document, '//svg:g[svg:text="0"]/svg:circle[@class="ring"]/svg:animate[@attributeName="opacity"]/@values'),
            'Невыделенная вершина кольца не получает.',
        );
    }

    /**
     * Картинка проигрывается один раз и замирает на готовой укладке, а не
     * начинается заново.
     */
    public function testPicturePlaysOnceAndStops(): void
    {
        $svg = $this->getSvgService()->animate([$this->scene($this->square()), $this->scene($this->square(40))]);
        $document = $this->parse($svg);

        self::assertStringNotContainsString('indefinite', $svg, 'Рассказ не должен повторяться.');
        self::assertNotSame([], $this->all($document, '//svg:animate/@repeatCount'));

        foreach ($this->all($document, '//svg:animate/@repeatCount') as $count) {
            self::assertSame('1', $count);
        }

        foreach ($this->all($document, '//svg:animate/@fill') as $fill) {
            self::assertSame('freeze', $fill, 'Последний кадр должен остаться на экране.');
        }
    }

    /**
     * Надрезанное ребро — то, которое одно поле уже забрало, а второе ещё
     * нет, — помечается особо, а ребро отрезанного поля красится в цвет
     * этого поля.
     */
    public function testHalfCutAndFieldEdgesAreColoured(): void
    {
        $plain = $this->scene($this->square());
        $marked = new Scene(
            $this->keyed($this->square()),
            [
                Scene::edgeKey(0, 1) => [$this->square()[0], $this->square()[1]],
                Scene::edgeKey(1, 2) => [$this->square()[1], $this->square()[2]],
            ],
            [Scene::edgeKey(0, 1) => -1, Scene::edgeKey(1, 2) => 2],
        );

        $document = $this->parse($this->getSvgService()->animate([$plain, $marked]));
        $classes = $this->all($document, '//svg:path/svg:animate[@attributeName="class"]/@values');
        $style = $this->first($document, '//svg:style');

        self::assertContains('edge;edge half', $classes, 'Надрезанное ребро помечается особо.');
        self::assertContains('edge;edge g2', $classes, 'Ребро отрезанного поля красится в цвет поля.');
        self::assertStringContainsString('.edge.half{', $style);
        self::assertStringContainsString('.edge.g2{', $style);
    }

    /**
     * Неподвижное не занимает места.
     *
     * Кадров в рассказе сотни, а каждый шар шевелится в считанных из них.
     * Пока в svg писались все кадры подряд, сотня вершин давала полсотни
     * мегабайт повторов одного и того же числа, и браузер на таком вставал.
     * Записывать надо только те моменты, где значение менялось: между двумя
     * равными концами и сплайн, и `discrete` дают ровно то же самое.
     */
    public function testStandingStillCostsNothing(): void
    {
        $scenes = [];

        for ($number = 0; $number < 200; $number++) {
            $scenes[] = $this->scene($this->square());
        }

        $scenes[] = $this->scene($this->square(40));
        $document = $this->parse($this->getSvgService()->animate($scenes));
        $values = explode(';', $this->first($document, '//svg:animateTransform/@values'));
        $moments = explode(';', $this->first($document, '//svg:animateTransform/@keyTimes'));

        self::assertCount(
            3,
            $values,
            'Двести кадров стояния на месте записаны как двести значений вместо трёх.',
        );
        self::assertSame(count($values), count($moments));
        self::assertSame($values[0], $values[1], 'Пока шар стоял, значение не должно меняться.');
        self::assertNotSame($values[1], $values[2], 'Последний кадр — тот, в котором шар поехал.');
        self::assertSame('0', $moments[0]);
        self::assertSame('1', $moments[count($moments) - 1]);
    }

    public function testEmptyStoryFails(): void
    {
        $this->expectException(LogicException::class);

        $this->getSvgService()->animate([]);
    }

    public function testNothingIsWrittenToDisk(): void
    {
        $before = glob(getcwd() . '/*.svg');

        $this->getSvgService()->animate([$this->scene($this->square()), $this->scene($this->square(20))]);

        self::assertSame($before, glob(getcwd() . '/*.svg'));
    }

    /**
     * @param Point2D[] $coordinates
     */
    private function scene(array $coordinates): Scene
    {
        $edges = [];

        foreach ($this->squareConnections() as $vertexA => $connection) {
            foreach (array_keys($connection) as $vertexB) {
                if ($vertexA < $vertexB) {
                    $edges[Scene::edgeKey($vertexA, $vertexB)] = [$coordinates[$vertexA], $coordinates[$vertexB]];
                }
            }
        }

        return new Scene($this->keyed($coordinates), $edges);
    }

    /**
     * @param Point2D[] $coordinates
     *
     * @return array<string, Point2D>
     */
    private function keyed(array $coordinates): array
    {
        $result = [];

        foreach ($coordinates as $vertex => $point) {
            $result[Scene::vertexKey($vertex)] = $point;
        }

        return $result;
    }

    /**
     * @return Point2D[]
     */
    private function square(float $size = 100.0): array
    {
        return [
            0 => new Point2D(-$size, -$size),
            1 => new Point2D($size, -$size),
            2 => new Point2D($size, $size),
            3 => new Point2D(-$size, $size),
        ];
    }

    /**
     * @return true[][]
     */
    private function squareConnections(): array
    {
        return [
            0 => [1 => true, 3 => true],
            1 => [0 => true, 2 => true],
            2 => [1 => true, 3 => true],
            3 => [2 => true, 0 => true],
        ];
    }

    private function viewBoxWidth(string $svg): float
    {
        [, , $width] = array_map(floatval(...), explode(' ', $this->first($this->parse($svg), '//svg:svg/@viewBox')));

        return $width;
    }

    private function parse(string $svg): DOMDocument
    {
        $document = new DOMDocument();

        self::assertTrue($document->loadXML($svg), 'SVG должен быть корректным XML.');

        return $document;
    }

    /**
     * @return string[]
     */
    private function all(DOMDocument $document, string $path): array
    {
        $nodes = $this->xpath($document)->query($path);
        $result = [];

        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $result[] = (string) $node->nodeValue;
            }
        }

        return $result;
    }

    private function nodeCount(DOMDocument $document, string $path): int
    {
        return $this->xpath($document)->query($path)?->length ?? 0;
    }

    private function first(DOMDocument $document, string $path): string
    {
        $nodes = $this->xpath($document)->query($path);

        self::assertNotFalse($nodes);
        self::assertGreaterThan(0, $nodes->length, sprintf('Не найдено ни одного узла по пути %s.', $path));

        return (string) $nodes->item(0)?->nodeValue;
    }

    private function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('svg', 'http://www.w3.org/2000/svg');

        return $xpath;
    }
}
