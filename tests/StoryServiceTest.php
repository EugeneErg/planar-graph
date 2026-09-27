<?php

declare(strict_types=1);

namespace Tests;

use EugeneErg\Graphs\Aggregates\SliceAggregate;
use EugeneErg\Graphs\Aggregates\Trace;
use EugeneErg\Graphs\Services\MotionService;
use EugeneErg\Graphs\Services\StoryService;
use EugeneErg\Graphs\ValueObjects\Point2D;
use EugeneErg\Graphs\ValueObjects\Scene;
use EugeneErg\Graphs\ValueObjects\Stage;
use EugeneErg\Graphs\ValueObjects\StageKind;
use EugeneErg\Graphs\ValueObjects\ZeroSlice;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Рассказ о работе алгоритма: каждый шаг виден, разрезанное разъезжается,
 * общие вершины раздваиваются, а потом всё собирается обратно.
 */
final class StoryServiceTest extends AbstractTestCase
{
    /**
     * Каждый этап алгоритма виден в картинке. Не на всяком графе есть что
     * делать на каждом шаге — связный граф не на что разрезать, — поэтому
     * этапы собираются со всех графов сразу.
     *
     * Порядок полей отдельного кадра не получает: вырезанное поле сразу
     * ложится на то место, с которого его и возьмут, поэтому очередь видна
     * с первого разреза и переставлять ничего не нужно.
     */
    public function testEveryStageIsShownSomewhere(): void
    {
        $shown = [];

        foreach (self::getGraphs() as [$connections]) {
            [$trace, $frames] = $this->trace($connections);

            foreach ((new StoryService())->build($trace, $frames, $connections) as $scene) {
                $shown[$scene->kind?->value ?? ''] = true;
            }
        }

        foreach (StageKind::cases() as $kind) {
            // «Порядок полей» и «найдена ветвь» своего кадра не получают.
            // Порядок виден с первого разреза: поле сразу ложится туда,
            // откуда его и возьмут. А находка ветви — пометка в журнале:
            // алгоритм записывает ветви на обратном ходу рекурсии, и рассказ
            // разворачивает их в цикл «запер — залил — вырезал», где каждая
            // и показывается.
            if ($kind === StageKind::Order || $kind === StageKind::Branch || self::isArcStage($kind)) {
                continue;
            }

            self::assertArrayHasKey($kind->value, $shown, sprintf('Этап «%s» не показан.', $kind->value));
        }
    }

    /**
     * Шаг, на котором есть что показать, не пропадает: если куски разъехались
     * или поле вырезано, в картинке есть соответствующий кадр.
     */
    public function testStageThatDoesSomethingIsNeverSkipped(): void
    {
        $connections = self::merge(false, self::getSmallTree(), self::getSimpleTriangle());
        [$trace, $frames] = $this->trace($connections);
        $shown = [];

        foreach ((new StoryService())->build($trace, $frames, $connections) as $scene) {
            $shown[$scene->kind?->value ?? ''] = true;
        }

        foreach ($trace->getStages() as $stage) {
            if (self::isIdle($stage)) {
                continue;
            }

            self::assertArrayHasKey(
                $stage->kind->value,
                $shown,
                sprintf('Шаг «%s» потерялся.', $stage->caption),
            );
        }
    }

    /**
     * Шаг, которому на этом графе нечего делать: разрезать не на что,
     * выделять нечего, порядок и так верен.
     */
    private static function isIdle(Stage $stage): bool
    {
        return match ($stage->kind) {
            StageKind::Components, StageKind::Branches => count(array_filter($stage->groups)) < 2,
            StageKind::ArticulationVertexes, StageKind::OuterFace => $stage->highlight === [],
            StageKind::Order, StageKind::Branch => true,
            StageKind::Arc, StageKind::Postpone, StageKind::Absorb, StageKind::Merge => true,
            default => false,
        };
    }

    /**
     * Все этапы обязаны присутствовать: заливка, разбиение на куски,
     * точки сочленения, вырезание ветвей, грани, внешняя грань, сборка,
     * раскладка и расслабление.
     */
    public function testWholeAlgorithmIsTold(): void
    {
        // Ни на одном графе не встречается всё сразу: на связном нечего
        // делить на куски, на двусвязном — на ветви, а на графе без
        // несовместимых кусков ничего не выносят наружу. Поэтому этапы
        // собираются со всех фикстур.
        $kinds = [];

        foreach (self::getGraphs() as [$connections]) {
            [$trace] = $this->trace($connections);

            foreach ($trace->getStages() as $stage) {
                $kinds[$stage->kind->value] = true;
            }
        }

        foreach (StageKind::cases() as $kind) {
            // Объединение отложенных — случай, когда укладывать больше нечего.
            // Ни на одной фикстуре он не встречается: отложенное всякий раз
            // накрывает обычная дуга.
            if ($kind === StageKind::Merge) {
                continue;
            }

            self::assertArrayHasKey($kind->value, $kinds, sprintf('Не рассказан этап «%s».', $kind->value));
        }
    }

    /**
     * Как ложатся дуги, рассказывает новый конвейер (`InstructionService`),
     * а не этот.
     */
    private static function isArcStage(StageKind $kind): bool
    {
        return in_array($kind, [StageKind::Arc, StageKind::Postpone, StageKind::Absorb, StageKind::Merge], true);
    }

    /**
     * Заливка показывается волнами: с какой вершины начали и что добавилось
     * на каждом шаге.
     */
    public function testFillIsShownWaveByWave(): void
    {
        [$trace] = $this->trace(self::getLine());
        $waves = array_values(array_filter(
            $trace->getStages(),
            static fn ($stage): bool => $stage->kind === StageKind::Fill,
        ));

        self::assertGreaterThanOrEqual(2, count($waves), 'Заливка должна идти хотя бы в две волны.');
        self::assertStringContainsString('начинаем с вершины', $waves[0]->caption);
        self::assertSame([0], $waves[0]->highlight, 'Первая волна — стартовая вершина.');
        self::assertSame([1], $waves[1]->highlight, 'Вторая волна — её сосед.');
    }

    public function testFillThatFoundNothingIsNotShown(): void
    {
        [$trace] = $this->trace(self::getDot());

        foreach ($trace->getStages() as $stage) {
            self::assertNotSame(StageKind::Fill, $stage->kind, 'Заливка одной вершины ничего не нашла.');
        }
    }

    /**
     * Заливка не стирает то, что закрашено раньше: на каждой волне видно
     * и прошлые ветви, и текущую.
     */
    public function testFillKeepsWhatWasPaintedBefore(): void
    {
        [$trace] = $this->trace(self::getSmallTree());
        $painted = null;
        $runs = 0;

        foreach ($trace->getStages() as $stage) {
            // Разбор кусков и разрез на ветви красят по-разному, поэтому
            // отсчёт начинается заново на каждой серии заливок.
            if ($stage->kind !== StageKind::Fill) {
                $painted = null;

                continue;
            }

            $now = count($stage->getVertexGroups());

            if ($painted === null) {
                $runs++;
            } else {
                self::assertGreaterThanOrEqual(
                    $painted,
                    $now,
                    sprintf('На шаге «%s» закрашенного стало меньше.', $stage->caption),
                );
            }

            $painted = $now;
        }

        self::assertGreaterThanOrEqual(2, $runs, 'Заливки идут и по кускам, и по ветвям.');
    }

    /**
     * Нарезка на поля видна по шагам: каждое вырезанное поле — свой шаг.
     */
    public function testFieldsAreCutOneByOne(): void
    {
        [$trace] = $this->trace(self::getTriangleInTriangle());
        $fields = array_values(array_filter(
            $trace->getStages(),
            static fn ($stage): bool => $stage->kind === StageKind::Field,
        ));

        self::assertGreaterThanOrEqual(2, count($fields));

        foreach ($fields as $field) {
            self::assertGreaterThanOrEqual(2, count($field->highlight), 'Поле — это путь, а не точка.');
        }
    }

    /**
     * Построение идёт по полю за раз: сначала внешняя грань, потом каждое поле
     * добавляет свои вершины, и назад ничего не пропадает.
     */
    public function testLayoutIsBuiltFieldByField(): void
    {
        [$trace, $frames, $connections] = $this->trace(self::getBig1());
        $scenes = (new StoryService())->build($trace, $frames, $connections);
        $counts = [];
        $total = null;

        foreach ($scenes as $scene) {
            if ($scene->kind !== StageKind::Build) {
                continue;
            }

            // Приглушённые ждут снаружи, но из кадра не пропадают.
            $placed = [];
            $all = [];

            foreach (array_keys($scene->vertexes) as $key) {
                $all[Scene::vertexOf($key)] = true;

                if (! isset($scene->faded[$key])) {
                    $placed[Scene::vertexOf($key)] = true;
                }
            }

            $counts[] = count($placed);
            $total ??= count($all);

            self::assertSame($total, count($all), 'Вершины не должны исчезать из кадра.');
        }

        self::assertGreaterThanOrEqual(3, count($counts), 'Построение должно идти в несколько шагов.');
        self::assertSame(count($frames[1]), $counts[count($counts) - 1], 'В конце построения уложены все вершины.');
        self::assertLessThan($counts[count($counts) - 1], $counts[0], 'Начинаем не со всего графа сразу.');

        for ($i = 1; $i < count($counts); $i++) {
            self::assertGreaterThanOrEqual($counts[$i - 1], $counts[$i], 'Уложенное не должно отыгрываться назад.');
        }
    }

    /**
     * Ребро лежит между двумя полями и режется дважды. Пока разрез только
     * один, ребро помечено: видно, с чем ещё предстоит работа.
     */
    public function testHalfCutEdgeIsMarkedUntilSecondCut(): void
    {
        [$trace, $frames, $connections] = $this->trace(self::getTriangleInTriangle());
        $scenes = (new StoryService())->build($trace, $frames, $connections);
        $marked = [];

        foreach ($scenes as $number => $scene) {
            $marked[$number] = count(array_filter(
                array_keys($scene->groups),
                static fn (string $key): bool => str_contains($key, '-'),
            ));
        }

        // Цвет надреза появляется в момент разреза, а не когда отрезанное
        // доехало до места: выделили поле — и сразу видно, что надрезано.
        foreach ($scenes as $number => $scene) {
            if ($scene->kind === StageKind::Field && $scene->highlight !== [] && isset($scenes[$number + 1])) {
                self::assertSame(
                    $marked[$number + 1],
                    $marked[$number],
                    sprintf('В кадре %d надрез ещё не показан, хотя резать уже начали.', $number),
                );
            }
        }

        self::assertSame(0, $marked[0], 'В целом графе ничего ещё не надрезано.');
        self::assertGreaterThan(0, max($marked), 'После первого разреза надрезанное должно быть видно.');
        self::assertSame(
            0,
            $marked[count($scenes) - 1],
            'В готовой укладке надрезанных рёбер не остаётся: каждое разрезано дважды.',
        );
    }

    /**
     * Перед расслаблением нет паузы: собранная укладка не показывается
     * второй раз, а сразу начинает расходиться.
     */
    public function testRelaxationStartsRightAfterBuilding(): void
    {
        [$trace, $frames, $connections] = $this->trace(self::getBig1());
        $scenes = (new StoryService())->build($trace, $frames, $connections);
        $relax = array_values(array_filter(
            $scenes,
            static fn (Scene $scene): bool => $scene->kind === StageKind::Relax,
        ));

        self::assertGreaterThanOrEqual(2, count($relax));
        self::assertLessThan(
            $relax[count($relax) - 1]->weight / 4,
            $relax[0]->weight,
            'Первый кадр расслабления повторяет собранную укладку — держать его незачем.',
        );
    }

    /**
     * Расслабление начинается с построенной раскладки, а не с клубка,
     * поэтому все его переходы плоские.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testRelaxationNeverCrosses(array $connections): void
    {
        [$trace, $frames] = $this->trace($connections);
        $motion = new MotionService();
        $edges = $motion->getEdges($connections);
        $scenes = array_values(array_filter(
            (new StoryService())->build($trace, $frames, $connections),
            static fn (Scene $scene): bool => $scene->kind === StageKind::Relax,
        ));

        // На маленьком графе укладке Татта расходиться уже некуда: кадр
        // всего один, и проверять в нём нечего.
        self::assertGreaterThanOrEqual(1, count($scenes), 'Расслабление должно быть в рассказе.');

        for ($i = 0; $i < count($scenes) - 1; $i++) {
            self::assertTrue(
                $motion->isTransitionPlanar(
                    $this->pointsOf($scenes[$i]),
                    $this->pointsOf($scenes[$i + 1]),
                    $edges,
                ),
                sprintf('Переход %d расслабления теряет планарность.', $i),
            );
        }
    }

    public function testDisconnectedComponentsMoveApart(): void
    {
        $connections = self::merge(false, self::getSimpleTriangle(), self::getRectangle());
        [$trace, $frames] = $this->trace($connections);
        $scenes = (new StoryService())->build($trace, $frames, $connections);
        $scene = $this->findScene($scenes, StageKind::Components);

        $first = $this->boundsOf($scene, [0, 1, 2]);
        $second = $this->boundsOf($scene, [3, 4, 5, 6]);

        self::assertTrue(
            $first[1] < $second[0] || $second[1] < $first[0]
                || $first[3] < $second[2] || $second[3] < $first[2],
            'Несвязные куски должны стоять врозь, а не друг на друге.',
        );
    }

    /**
     * Точка сочленения принадлежит нескольким ветвям, поэтому при разрезании
     * она раздваивается: в кадре появляются её копии. Ветви отрезают по одной,
     * поэтому смотреть надо на последний кадр разреза — когда отрезаны все.
     */
    public function testArticulationVertexIsDuplicatedWhenBranchesAreCut(): void
    {
        [$trace, $frames, $connections] = $this->trace(self::getSmallTree());
        $scenes = (new StoryService())->build($trace, $frames, $connections);
        $spots = $this->spotsOf($this->findLastScene($scenes, StageKind::Branches));

        self::assertSame(2, $spots[4] ?? 0, 'Вершина 4 лежит в двух ветвях.');
        self::assertSame(1, $spots[0] ?? 0, 'Обычная вершина не раздваивается.');
    }

    /**
     * Сборка возвращает копии на место: в готовой укладке вершина снова одна,
     * и никаких раздвоенных обломков рядом не остаётся.
     */
    public function testBuildBringsCopiesBackOntoTheirVertex(): void
    {
        [$trace, $frames, $connections] = $this->trace(self::getSmallTree());
        $scenes = (new StoryService())->build($trace, $frames, $connections);
        $spots = $this->spotsOf($scenes[count($scenes) - 1]);

        self::assertCount(count($frames[0]), $spots, 'В готовой укладке должны быть все вершины.');

        foreach ($spots as $vertex => $count) {
            self::assertSame(1, $count, sprintf('Вершина %d осталась раздвоенной.', $vertex));
        }
    }

    /**
     * Поле вырезается физически: кусок отходит целиком, а вершина, которая
     * ещё нужна соседям, раздваивается — как вырезанный кусок пирога.
     */
    public function testCutFieldTakesItsOwnCopyOfSharedVertexes(): void
    {
        [$trace, $frames, $connections] = $this->trace(self::getTriangleInTriangle());
        $scenes = (new StoryService())->build($trace, $frames, $connections);
        $scene = $this->findLastScene($scenes, StageKind::Field);
        $spots = $this->spotsOf($scene);

        self::assertGreaterThan(
            count($connections),
            array_sum($spots),
            'Общие вершины должны разъехаться, иначе кусок не отрезан.',
        );

        // Ребро на границе двух полей тоже раздваивается: каждому полю своё.
        $edges = [];

        foreach (array_keys($scene->edges) as $key) {
            $edges[explode(':', $key)[0]] = ($edges[explode(':', $key)[0]] ?? 0) + 1;
        }

        self::assertContains(2, $edges, 'Ребро между двумя полями должно достаться обоим.');
    }

    /**
     * Куски не наезжают друг на друга: у каждого своё место на столе.
     */
    public function testCutPiecesDoNotOverlap(): void
    {
        [$trace, $frames, $connections] = $this->trace(self::getTriangleInTriangle());
        $scenes = (new StoryService())->build($trace, $frames, $connections);
        $scene = $this->findLastScene($scenes, StageKind::Field);
        $seen = [];

        foreach ($scene->vertexes as $key => $point) {
            $place = self::pointKey($point);
            // В одной точке могут стоять только копии одной вершины: пока
            // кусок не отрезан, его копии лежат поверх своих оригиналов.
            self::assertSame(
                Scene::vertexOf($seen[$place] ?? $key),
                Scene::vertexOf($key),
                sprintf('Экземпляры %s и %s стоят в одной точке.', $seen[$place] ?? '', $key),
            );

            $seen[$place] = $key;
        }
    }

    /**
     * Ничто не возникает из воздуха и никуда не девается: набор экземпляров
     * один и тот же во всех кадрах. Отрезанный кусок отделяется от графа
     * на глазах, потому что до разреза он лежит поверх него.
     *
     * Единственное, что в графе прибавляется, — связки: их добавляет склейка,
     * чтобы односвязный граф стал двусвязным. Связка появляется на своей
     * склейке, рисуется пунктиром и больше не исчезает.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testNothingAppearsOutOfThinAir(array $connections): void
    {
        [$trace, $frames] = $this->trace($connections);
        $scenes = (new StoryService())->build($trace, $frames, $connections);
        $vertexes = array_keys($scenes[0]->vertexes);
        $edges = array_keys($scenes[0]->edges);
        sort($vertexes);
        $tied = [];

        foreach ($scenes as $number => $scene) {
            $sceneVertexes = array_keys($scene->vertexes);
            sort($sceneVertexes);

            self::assertSame($vertexes, $sceneVertexes, sprintf('В кадре %d состав вершин другой.', $number));
            self::assertSame(
                [],
                array_diff($edges, array_keys($scene->edges)),
                sprintf('В кадре %d пропало ребро.', $number),
            );

            foreach (array_diff(array_keys($scene->edges), $edges) as $key) {
                self::assertArrayHasKey(
                    $key,
                    $scene->ties,
                    sprintf('В кадре %d из воздуха возникло ребро %s.', $number, $key),
                );
                self::assertSame(
                    $scene->kind === StageKind::Tie || isset($tied[$key]),
                    true,
                    sprintf('Связка %s появилась не на склейке.', $key),
                );
                $tied[$key] = true;
            }

            foreach (array_keys($tied) as $key) {
                self::assertArrayHasKey(
                    $key,
                    $scene->edges,
                    sprintf('В кадре %d пропала связка %s.', $number, $key),
                );
            }
        }
    }

    /**
     * Едет только то, что режут: место куска, раз назначенное, больше
     * не меняется. Иначе при каждом разрезе едут все, и уследить невозможно.
     */
    public function testCutPieceKeepsItsPlaceForever(): void
    {
        [$trace, , $connections] = $this->trace(self::getBig1());
        $plan = (new StoryService())->getCutPlan($trace, $connections);
        $places = [];

        for ($number = 0; $number <= $plan->getLastState(); $number++) {
            foreach ($plan->getState($number)['places'] as $id => $place) {
                self::assertSame(
                    $places[$id] ?? $place,
                    $place,
                    sprintf('Кусок %d переехал на другое место.', $id),
                );
                $places[$id] = $place;
            }
        }

        self::assertNotSame([], $places, 'Хоть что-то за нарезку отрезать должны.');
    }

    /**
     * Место на столе есть у каждого, кому оно назначено, и занято оно одним.
     *
     * Мест на столе ровно столько, сколько их раздали. Если нумеровать куски,
     * а не места, номеров выйдет больше: одно место занимает целая цепочка —
     * ветвь, а за ней всё, что от неё остаётся после каждого разреза.
     * Тогда последним места не хватит, и они лягут в начало координат поверх
     * чужих полей.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testEveryPieceHasAPlaceOfItsOwn(array $connections): void
    {
        [$trace, , $links] = $this->trace($connections);
        $plan = (new StoryService())->getCutPlan($trace, $links);

        self::assertNotSame([], $plan->getState(0)['live'], 'В первом кадре лежит хотя бы сам граф.');

        for ($number = 0; $number <= $plan->getLastState(); $number++) {
            $taken = [];

            foreach ($plan->getState($number)['live'] as $id) {
                $place = $plan->getState($number)['places'][$id];

                if ($place === -1) {
                    continue;
                }

                $table = $plan->getTable($id);

                self::assertLessThan(
                    $plan->getSlotCount($table),
                    $place,
                    sprintf('В кадре %d куску %d досталось место %d, которого на столе %d нет.', $number, $id, $place, $table),
                );
                self::assertArrayNotHasKey(
                    $table . ':' . $place,
                    $taken,
                    sprintf('В кадре %d куски %d и %d стоят на одном месте.', $number, $taken[$table . ':' . $place] ?? 0, $id),
                );

                $taken[$table . ':' . $place] = $id;
            }
        }
    }

    /**
     * Куски не налезают друг на друга ни в одном кадре: что лежит отдельно,
     * то и выглядит отдельно. Кусок здесь — то, что связано на картинке,
     * поэтому ещё не отрезанная копия, лежащая поверх оригинала, считается
     * с ним заодно.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testPiecesDoNotLieOnTopOfEachOther(array $connections): void
    {
        [$trace, $frames, $links] = $this->trace($connections);
        $geometry = $this->getGeometryService();
        $scenes = (new StoryService())->build($trace, $frames, $links);

        self::assertNotSame([], $scenes, 'Рассказ не бывает пустым.');

        foreach ($scenes as $number => $scene) {
            $edges = array_values($scene->edges);
            $pieces = self::piecesOf($edges);
            $count = count($edges);

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($pieces[$i] === $pieces[$j]) {
                        continue;
                    }

                    self::assertFalse(
                        $geometry->segmentsIntersect($edges[$i][0], $edges[$i][1], $edges[$j][0], $edges[$j][1]),
                        sprintf('В кадре %d два разных куска налезли друг на друга.', $number),
                    );
                }
            }
        }
    }

    /**
     * Разбивает рёбра кадра на куски: ребро с ребром в одном куске, если они
     * сходятся концами в одной точке.
     *
     * @param array<int, array{Point2D, Point2D}> $edges
     *
     * @return int[] ребро => номер куска
     */
    private static function piecesOf(array $edges): array
    {
        $pieces = array_keys($edges);
        $find = static function (int $edge) use (&$pieces): int {
            while ($pieces[$edge] !== $edge) {
                $pieces[$edge] = $pieces[$pieces[$edge]];
                $edge = $pieces[$edge];
            }

            return $edge;
        };
        $same = static fn (Point2D $one, Point2D $two): bool => abs($one->x - $two->x) < 1e-9 && abs($one->y - $two->y) < 1e-9;
        $count = count($edges);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (
                    $same($edges[$i][0], $edges[$j][0]) || $same($edges[$i][0], $edges[$j][1])
                    || $same($edges[$i][1], $edges[$j][0]) || $same($edges[$i][1], $edges[$j][1])
                ) {
                    $pieces[$find($i)] = $find($j);
                }
            }
        }

        $result = [];

        for ($i = 0; $i < $count; $i++) {
            $result[$i] = $find($i);
        }

        return $result;
    }

    /**
     * У каждого несвязного куска свой стол: нарезка одного не лезет на другой,
     * и собираются они тоже врозь.
     *
     * @param true[][] $connections
     * @param int[] $first
     * @param int[] $second
     */
    #[DataProvider('getDisconnectedGraphs')]
    public function testDisconnectedGraphsDoNotShareTheTable(array $connections, array $first, array $second): void
    {
        [$trace, $frames] = $this->trace($connections);
        $scenes = (new StoryService())->build($trace, $frames, $connections);

        foreach ($scenes as $number => $scene) {
            if ($scene->kind === StageKind::Graph || $scene->kind === StageKind::Fill) {
                // Пока куски не разъехались, они и правда в одном клубке.
                continue;
            }

            // Куски раскладываются вокруг центрального, поэтому врозь они
            // не обязательно слева и справа: проверяется, что их прямоугольники
            // не налезают, в какую бы сторону кусок ни уехал.
            [$leftA, $rightA, $topA, $bottomA] = $this->boundsOf($scene, $first);
            [$leftB, $rightB, $topB, $bottomB] = $this->boundsOf($scene, $second);

            self::assertTrue(
                $rightA < $leftB || $rightB < $leftA || $bottomA < $topB || $bottomB < $topA,
                sprintf('В кадре %d куски налезли друг на друга.', $number),
            );
        }
    }

    /**
     * Рёбра разных несвязных кусков не пересекаются ни в одном кадре — в том
     * числе в клубке, где куски ещё лежат вперемешку.
     *
     * @param true[][] $connections
     * @param int[] $first
     * @param int[] $second
     */
    #[DataProvider('getDisconnectedGraphs')]
    public function testDisconnectedGraphsNeverCross(array $connections, array $first, array $second): void
    {
        [$trace, $frames] = $this->trace($connections);
        $geometry = $this->getGeometryService();
        $parts = array_replace(array_fill_keys($first, 0), array_fill_keys($second, 1));

        foreach ((new StoryService())->build($trace, $frames, $connections) as $number => $scene) {
            $edges = [];

            foreach ($scene->edges as $key => [$from, $to]) {
                $edges[] = [$parts[Scene::vertexOf(explode('-', $key)[0])] ?? 0, $from, $to];
            }

            for ($i = 0; $i < count($edges); $i++) {
                for ($j = $i + 1; $j < count($edges); $j++) {
                    if ($edges[$i][0] === $edges[$j][0]) {
                        continue;
                    }

                    self::assertFalse(
                        $geometry->segmentsIntersect($edges[$i][1], $edges[$i][2], $edges[$j][1], $edges[$j][2]),
                        sprintf('В кадре %d рёбра разных кусков пересеклись.', $number),
                    );
                }
            }
        }
    }

    /**
     * @return array<string, array{true[][], int[], int[]}>
     */
    public static function getDisconnectedGraphs(): array
    {
        return [
            'дерево с циклом и треугольник' => [
                self::merge(false, self::getSmallTree(), self::getSimpleTriangle()),
                range(0, 7),
                range(8, 10),
            ],
            'большой с перешейками' => [self::getBig2(), range(0, 16), range(17, 23)],
        ];
    }

    /**
     * За кольцом сразу идёт тот разрез, ради которого его зажгли: обведена
     * точка сочленения — следующим кадром по ней и режут, и она раздваивается.
     * Обвести разом все точки, а потом разом всё разрезать — светофор, а
     * не действие.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testEveryRingIsFollowedByItsCut(array $connections): void
    {
        [$trace, $frames, $links] = $this->trace($connections);
        $scenes = (new StoryService())->build($trace, $frames, $links);

        self::assertNotSame([], $scenes, 'Рассказ не бывает пустым.');

        foreach ($scenes as $number => $scene) {
            if ($scene->kind !== StageKind::ArticulationVertexes) {
                continue;
            }

            $next = $scenes[$number + 1] ?? null;

            // Кадр выделения идёт дважды подряд: сначала кольцо держится
            // неподвижно, и только потом начинается движение.
            if ($next !== null && $next->kind === $scene->kind && $next->highlight === $scene->highlight) {
                continue;
            }

            self::assertNotNull($next, sprintf('За кольцом в кадре %d ничего не следует.', $number));
            self::assertSame(
                StageKind::Branches,
                $next->kind,
                sprintf('За кольцом в кадре %d идёт не разрез.', $number),
            );

            $was = $this->spotsOf($scene);
            $now = $this->spotsOf($next);

            foreach (array_keys($scene->highlight) as $key) {
                $vertex = Scene::vertexOf($key);

                self::assertGreaterThan(
                    $was[$vertex] ?? 0,
                    $now[$vertex] ?? 0,
                    sprintf('Обвели вершину %d, а разрезали не по ней.', $vertex),
                );
            }
        }
    }

    /**
     * Разрез накапливается: когда режут один кусок, остальные остаются целыми
     * и никуда не деваются.
     */
    public function testCuttingOneComponentKeepsTheOtherWhole(): void
    {
        $connections = self::merge(false, self::getSmallTree(), self::getSimpleTriangle());
        [$trace, $frames] = $this->trace($connections);
        $scenes = (new StoryService())->build($trace, $frames, $connections);
        $scene = $this->findScene($scenes, StageKind::Branches);
        $vertexes = [];

        foreach (array_keys($scene->vertexes) as $key) {
            $vertexes[Scene::vertexOf($key)] = true;
        }

        foreach (array_keys($connections) as $vertex) {
            self::assertArrayHasKey($vertex, $vertexes, sprintf('Вершина %d пропала из кадра.', $vertex));
        }
    }

    /**
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testEverySceneKeepsEdgesAttachedToVertexes(array $connections): void
    {
        [$trace, $frames] = $this->trace($connections);

        foreach ((new StoryService())->build($trace, $frames, $connections) as $number => $scene) {
            $points = [];

            foreach ($scene->vertexes as $point) {
                $points[self::pointKey($point)] = true;
            }

            foreach ($scene->edges as $key => [$from, $to]) {
                self::assertArrayHasKey(
                    self::pointKey($from),
                    $points,
                    sprintf('Ребро %s в кадре %d висит в воздухе.', $key, $number),
                );
                self::assertArrayHasKey(self::pointKey($to), $points);
            }
        }
    }

    /**
     * В укладку поле встраивается по одному за кадр. На одном шаге построения
     * может сойтись сразу несколько готовых полей — поле, все вершины которого
     * уже лежат, готово вместе с тем, кто уложил последнюю. Показывать их разом
     * нельзя: непонятно, что откуда взялось.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testLayoutGrowsByOneFieldAtATime(array $connections): void
    {
        [$trace, $frames, $links] = $this->trace($connections);
        $scenes = (new StoryService())->build($trace, $frames, $links);

        self::assertNotSame([], $scenes, 'Рассказ не бывает пустым.');

        foreach ($scenes as $number => $scene) {
            if ($scene->kind !== StageKind::Build || ! isset($scenes[$number - 1])) {
                continue;
            }

            self::assertLessThanOrEqual(
                1,
                self::movedPieces($scenes[$number - 1], $scene),
                sprintf('В кадре %d в укладку встроилось больше одного поля.', $number),
            );
        }
    }

    /**
     * Куски отрезают по одному: за кадр уезжает одна часть, а с места сходит
     * только она и остаток, который перераспределяется по своему кругу.
     * Разом отпустить все нельзя — глаз не успевает за тем, как полграфа
     * разлетается в стороны.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testPartsAreCutOneAtATime(array $connections): void
    {
        [$trace, $frames, $links] = $this->trace($connections);
        $scenes = (new StoryService())->build($trace, $frames, $links);

        self::assertNotSame([], $scenes, 'Рассказ не бывает пустым.');

        foreach ($scenes as $number => $scene) {
            $cutting = $scene->kind === StageKind::Branches || $scene->kind === StageKind::Components
                || $scene->kind === StageKind::Field;

            if (! $cutting || ! isset($scenes[$number - 1])) {
                continue;
            }

            self::assertLessThanOrEqual(
                2,
                self::movedPieces($scenes[$number - 1], $scene),
                sprintf('В кадре %d разъехалось больше одной части за раз.', $number),
            );
        }
    }

    /**
     * Укладка растёт от куска к куску, а не островками: каждое следующее поле
     * держится за уже уложенное. Островков не больше, чем несвязных кусков
     * в самом графе, — иначе построение начинается посреди пустоты, например
     * с перемычки, оба конца которой попали на внешнюю грань.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testLayoutGrowsPieceByPiece(array $connections): void
    {
        [$trace, $frames, $links] = $this->trace($connections);
        $scenes = (new StoryService())->build($trace, $frames, $links);
        $allowed = self::countParts($links);
        $islands = [];

        foreach ($scenes as $number => $scene) {
            if ($scene->kind !== StageKind::Build || ! isset($scenes[$number - 1])) {
                continue;
            }

            $arrived = [];

            foreach ($scene->vertexes as $key => $point) {
                $was = $scenes[$number - 1]->vertexes[$key] ?? null;

                if ($was !== null && (abs($was->x - $point->x) > 1e-9 || abs($was->y - $point->y) > 1e-9)) {
                    $arrived[self::pointKey($point)] = true;
                }
            }

            if ($arrived === []) {
                continue;
            }

            $merged = $arrived;

            foreach ($islands as $island => $points) {
                if (array_intersect_key($points, $arrived) !== []) {
                    $merged += $points;
                    unset($islands[$island]);
                }
            }

            $islands[] = $merged;

            self::assertLessThanOrEqual(
                $allowed,
                count($islands),
                sprintf('В кадре %d поле легло в стороне от уложенного.', $number),
            );
        }

        // Граф из одного поля собирается, никуда не переезжая: оно уже лежит
        // там, где ему и быть. Островков тогда нет вовсе — и это не ошибка.
        self::assertLessThanOrEqual($allowed, count($islands));
    }

    /**
     * Поля встраиваются в том порядке, в каком их строил алгоритм.
     *
     * Шаг построения прокладывает обход и вместе с ним свои рёбра; поле готово,
     * когда проложено последнее его ребро. В этом порядке поля и лежат на
     * кольце — их оттуда и забирают. Отстать от очереди поле может только
     * по делу: пока ему не за что зацепиться, оно ждёт, иначе легло бы
     * островком. А вот обогнать того, кто готов раньше и уже держится за
     * уложенное, нельзя.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testFieldsAreBuiltInTheOrderTheAlgorithmLaidThem(array $connections): void
    {
        [$trace, , $links] = $this->trace($connections);
        $plan = (new StoryService())->getCutPlan($trace, $links);
        $steps = self::edgeSteps($trace);
        $tables = [];

        foreach ($plan->getState($plan->getLastState())['live'] as $id) {
            $piece = $plan->getCutout($id)->piece;
            $rank = -1;

            foreach ($piece->edges as [$vertexA, $vertexB]) {
                $rank = max($rank, $steps[Scene::edgeName($vertexA, $vertexB)] ?? -1);
            }

            $tables[$plan->getTable($id)][$plan->getPlace($id)] = ['rank' => $rank, 'vertexes' => $piece->vertexes];
        }

        foreach ($tables as $table => $pieces) {
            ksort($pieces);
            $built = [];
            $waiting = $pieces;
            $faces = array_filter($pieces, static fn (array $item): bool => count($item['vertexes']) > 2);
            $first = reset($pieces);

            if ($faces !== [] && $first !== false) {
                self::assertGreaterThan(
                    2,
                    count($first['vertexes']),
                    sprintf('Стол %d открывает перемычка, а не грань.', $table),
                );
            }

            foreach ($pieces as $place => $piece) {
                unset($waiting[$place]);

                foreach ($waiting as $other) {
                    if ($other['rank'] >= $piece['rank'] || $built === []) {
                        continue;
                    }

                    self::assertSame(
                        [],
                        array_intersect($other['vertexes'], array_keys($built)),
                        sprintf('На столе %d поле с места %d обогнало то, что готово раньше и уже держится за уложенное.', $table, $place),
                    );
                }

                foreach ($piece['vertexes'] as $vertex) {
                    $built[$vertex] = true;
                }
            }
        }
    }

    /**
     * Каким по счёту шагом построения алгоритм проложил каждое ребро.
     *
     * @return array<string, int>
     */
    private static function edgeSteps(Trace $trace): array
    {
        $result = [];
        $step = 0;

        foreach ($trace->getStages() as $stage) {
            if ($stage->kind !== StageKind::Build) {
                continue;
            }

            $walk = $stage->groups[1] ?? [];
            $count = count($walk);

            for ($number = 1; $number < $count; $number++) {
                $result[Scene::edgeName($walk[$number - 1], $walk[$number])] ??= $step;
            }

            $step++;
        }

        return $result;
    }

    /**
     * Заливка идёт по связям: краска ползёт от уже закрашенной вершины
     * к соседней и доходит до неё ровно к тому кадру, в котором та
     * закрашивается. Иначе видно только, что точки меняют цвет, а кто кого
     * закрасил — нет.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testPaintCrawlsAlongEdges(array $connections): void
    {
        [$trace, $frames, $links] = $this->trace($connections);
        $scenes = (new StoryService())->build($trace, $frames, $links);
        $flows = 0;

        foreach ($scenes as $number => $scene) {
            foreach ($scene->flows as $key => [$from, $group]) {
                $flows++;

                self::assertArrayHasKey($key, $scene->edges, sprintf('В кадре %d краска ползёт по ребру, которого нет.', $number));
                self::assertSame(
                    $group,
                    $scenes[$number - 1]->groups[$from] ?? null,
                    sprintf('В кадре %d краска пошла от незакрашенной вершины.', $number),
                );
                self::assertSame(
                    $group,
                    $scene->groups[$key] ?? null,
                    sprintf('В кадре %d краска дошла, а ребро осталось незакрашенным.', $number),
                );
            }
        }

        self::assertGreaterThan(0, $flows, 'Заливка должна хоть раз перетечь по связи.');
    }

    /**
     * @param true[][] $connections
     */
    private static function countParts(array $connections): int
    {
        $parts = [];
        $result = 0;

        foreach (array_keys($connections) as $vertex) {
            if (isset($parts[$vertex])) {
                continue;
            }

            $result++;
            $stack = [$vertex];
            $parts[$vertex] = true;

            while ($stack !== []) {
                $current = array_pop($stack);

                foreach (array_keys($connections[$current] ?? []) as $next) {
                    if (! isset($parts[$next])) {
                        $parts[$next] = true;
                        $stack[] = $next;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Сколько кусков сошло с места за переход. Куски считаются по кадру до
     * перехода: приехавшее сливается с тем, к чему приехало, и по кадру после
     * уже не разобрать, сколько всего ехало.
     */
    private static function movedPieces(Scene $before, Scene $after): int
    {
        $edges = array_values($before->edges);
        $pieces = self::piecesOf($edges);
        $at = [];

        foreach ($edges as $number => [$from, $to]) {
            $at[self::pointKey($from)] = $pieces[$number];
            $at[self::pointKey($to)] = $pieces[$number];
        }

        $moved = [];

        foreach ($after->vertexes as $key => $point) {
            $was = $before->vertexes[$key] ?? null;

            if ($was === null || (abs($was->x - $point->x) < 1e-9 && abs($was->y - $point->y) < 1e-9)) {
                continue;
            }

            // Одинокая вершина сама себе кусок: рёбер, по которым её можно
            // было бы к чему-то отнести, у неё нет.
            $moved[$at[self::pointKey($was)] ?? 'v' . $key] = true;
        }

        return count($moved);
    }

    /**
     * @return array<string, array{true[][]}>
     */
    public static function getGraphs(): array
    {
        return [
            'треугольник' => [self::getSimpleTriangle()],
            'дерево с циклом' => [self::getSmallTree()],
            'два куска' => [self::merge(false, self::getSimpleTriangle(), self::getRectangle())],
            'большой граф' => [self::getBig1()],
            'большой с перешейками' => [self::getBig2()],
        ];
    }

    /**
     * @param true[][] $connections
     *
     * @return array{Trace, Point2D[][], true[][]}
     */
    private function trace(array $connections): array
    {
        $trace = new Trace();
        $frames = $this->getPlanarService()->connectionsToFrames(
            $connections,
            new SliceAggregate(new ZeroSlice()),
            100.0,
            $trace,
        );

        return [$trace, $frames, $connections];
    }

    /**
     * @param Scene[] $scenes
     */
    private function findScene(array $scenes, StageKind $kind): Scene
    {
        foreach ($scenes as $scene) {
            if ($scene->kind === $kind) {
                return $scene;
            }
        }

        self::fail(sprintf('В рассказе нет кадра для этапа «%s».', $kind->value));
    }

    /**
     * Последний кадр разреза: после него кусок уже начинают собирать обратно.
     *
     * @param Scene[] $scenes
     */
    private function findLastScene(array $scenes, StageKind $kind): Scene
    {
        $result = null;

        foreach ($scenes as $scene) {
            if ($scene->kind === $kind) {
                $result = $scene;
            }
        }

        self::assertNotNull($result, sprintf('В рассказе нет кадра для этапа «%s».', $kind->value));

        return $result;
    }

    /**
     * @param int[] $vertexes
     *
     * @return array{float, float, float, float} слева, справа, сверху, снизу
     */
    private function boundsOf(Scene $scene, array $vertexes): array
    {
        $result = [INF, -INF, INF, -INF];

        foreach ($scene->vertexes as $key => $point) {
            if (in_array(Scene::vertexOf($key), $vertexes, true)) {
                $result = [
                    min($result[0], $point->x),
                    max($result[1], $point->x),
                    min($result[2], $point->y),
                    max($result[3], $point->y),
                ];
            }
        }

        return $result;
    }

    /**
     * Сколько мест в кадре занимает вершина. Экземпляры, лежащие друг на
     * друге, глазу неразличимы, поэтому считаются за одно место.
     *
     * @return array<int, int> вершина => сколько разных мест она занимает
     */
    private function spotsOf(Scene $scene): array
    {
        $spots = [];

        foreach ($scene->vertexes as $key => $point) {
            $spots[Scene::vertexOf($key)][self::pointKey($point)] = true;
        }

        return array_map(count(...), $spots);
    }

    /**
     * @return Point2D[] вершина => её место в кадре
     */
    /**
     * Собирают в укладку, а не в разметку.
     *
     * До настоящей укладки алгоритм проходит промежуточную: вершины дуги
     * раскладываются вплотную к краю, и ближайшая пара расходится на доли
     * пикселя. Если собирать туда, поле прилетает сплющенным в линию на
     * границу — не то что порядок, сами поля неразличимы, и кажется, что
     * укладка строится не в том порядке. Собирать надо в первую невырожденную.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testLayoutIsBuiltIntoARealDrawing(array $connections): void
    {
        [$trace, $frames, $links] = $this->trace($connections);
        $scenes = (new StoryService())->build($trace, $frames, $links);
        $built = null;

        foreach ($scenes as $scene) {
            $built = $scene->kind === StageKind::Build ? $scene : $built;
        }

        self::assertNotNull($built, 'Укладка нигде не собирается.');
        $near = self::nearest($this->pointsOf($built));
        $final = self::nearest($this->pointsOf($scenes[count($scenes) - 1]));

        if ($near === INF || $final === INF) {
            return;
        }

        // Мериться надо с готовой укладкой, а не с абсолютным числом: на графе
        // с хвостами алгоритм и в конце кладёт вершины тесно. Важно, что
        // собранное — уже укладка того же порядка, а не расплющенная разметка.
        self::assertGreaterThan(
            $final / 4,
            $near,
            'Вершины собранной укладки слиплись: собирают в разметку, а не в укладку.',
        );
    }

    /**
     * Насколько близко сходятся две разные вершины.
     *
     * @param Point2D[] $points
     */
    private static function nearest(array $points): float
    {
        $points = array_values($points);
        $count = count($points);
        $result = INF;

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $result = min($result, hypot($points[$i]->x - $points[$j]->x, $points[$i]->y - $points[$j]->y));
            }
        }

        return $result;
    }

    private function pointsOf(Scene $scene): array
    {
        $result = [];

        foreach ($scene->vertexes as $key => $point) {
            $result[Scene::vertexOf($key)] = $point;
        }

        return $result;
    }

    private static function pointKey(Point2D $point): string
    {
        return round($point->x, 6) . ',' . round($point->y, 6);
    }
}
