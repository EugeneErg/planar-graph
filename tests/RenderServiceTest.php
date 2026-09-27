<?php

declare(strict_types=1);

namespace Tests;

use EugeneErg\Graphs\Aggregates\SliceAggregate;
use EugeneErg\Graphs\Aggregates\Trace;
use EugeneErg\Graphs\Services\ArenaGeometry;
use EugeneErg\Graphs\Services\InstructionService;
use EugeneErg\Graphs\Services\RenderService;
use EugeneErg\Graphs\ValueObjects\Scene;
use EugeneErg\Graphs\ValueObjects\ZeroSlice;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Новый конвейер целиком: инструкция → геометрия → отрисовка.
 */
final class RenderServiceTest extends AbstractTestCase
{
    /**
     * Ничего не появляется из ниоткуда: и шары, и палки — те же во всех
     * кадрах. Раздвоенное до разреза лежит поверх того, от чего раздвоится.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testNothingAppearsOutOfThinAir(array $connections): void
    {
        $scenes = $this->render($connections);
        $vertexes = array_keys($scenes[0]->vertexes);
        $edges = array_keys($scenes[0]->edges);
        sort($vertexes);
        sort($edges);

        foreach ($scenes as $number => $scene) {
            $now = array_keys($scene->vertexes);
            $lines = array_keys($scene->edges);
            sort($now);
            sort($lines);

            self::assertSame($vertexes, $now, sprintf('Кадр %d: набор шаров изменился.', $number));
            self::assertSame($edges, $lines, sprintf('Кадр %d: набор палок изменился.', $number));
        }
    }

    /**
     * Вопрос не остаётся без ответа: к концу ничего не заперто и не залито
     * краской вопроса.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testEveryQuestionIsAnswered(array $connections): void
    {
        $scenes = $this->render($connections);

        self::assertSame([], end($scenes)->blocked);
    }

    /**
     * Граф собран: в последнем кадре все копии каждой вершины лежат в одной
     * точке — там, где её поставило расслабление.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testEveryCopyEndsInTheDrawing(array $connections): void
    {
        $scenes = $this->render($connections);
        $points = [];

        foreach (end($scenes)->vertexes as $key => $point) {
            $points[Scene::vertexOf($key)][] = $point;
        }

        foreach ($points as $vertex => $list) {
            foreach ($list as $point) {
                self::assertEqualsWithDelta($list[0]->x, $point->x, 1e-6, sprintf('Копии вершины %d не сошлись.', $vertex));
                self::assertEqualsWithDelta($list[0]->y, $point->y, 1e-6, sprintf('Копии вершины %d не сошлись.', $vertex));
            }
        }
    }

    /**
     * @return array<string, array{true[][]}>
     */
    public static function getGraphs(): array
    {
        return [
            'много кусков' => [require __DIR__ . '/Cases/Graphs/ManyParts.php'],
            'колёса' => [require __DIR__ . '/Cases/Graphs/SevenFields.php'],
            'разбор отрезанного' => [require __DIR__ . '/Cases/Graphs/Nested.php'],
        ];
    }

    /**
     * @param true[][] $connections
     *
     * @return Scene[]
     */
    private function render(array $connections): array
    {
        $trace = new Trace();
        $frames = $this->getPlanarService()->connectionsToFrames($connections, new SliceAggregate(new ZeroSlice()), 100.0, $trace);
        $instruction = (new InstructionService())->build($trace, $connections);
        $geometry = new ArenaGeometry();

        return (new RenderService())->render($geometry->layout(
            $instruction,
            $frames[min(2, count($frames) - 1)],
            $frames[count($frames) - 1],
        ));
    }
}
