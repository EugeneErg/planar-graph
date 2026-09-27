<?php

declare(strict_types=1);

namespace Tests;

use EugeneErg\Graphs\Aggregates\SliceAggregate;
use EugeneErg\Graphs\Aggregates\Trace;
use EugeneErg\Graphs\Services\ScriptService;
use EugeneErg\Graphs\ValueObjects\Piece;
use EugeneErg\Graphs\ValueObjects\Scene;
use EugeneErg\Graphs\ValueObjects\Script\ActionInterface;
use EugeneErg\Graphs\ValueObjects\Script\Aside;
use EugeneErg\Graphs\ValueObjects\Script\Block;
use EugeneErg\Graphs\ValueObjects\Script\Build;
use EugeneErg\Graphs\ValueObjects\Script\Cut;
use EugeneErg\Graphs\ValueObjects\Script\Detach;
use EugeneErg\Graphs\ValueObjects\Script\Join;
use EugeneErg\Graphs\ValueObjects\Script\Paint;
use EugeneErg\Graphs\ValueObjects\Script\Relax;
use EugeneErg\Graphs\ValueObjects\Script\Script;
use EugeneErg\Graphs\ValueObjects\Script\Show;
use EugeneErg\Graphs\ValueObjects\Script\Step;
use EugeneErg\Graphs\ValueObjects\Script\Tie;
use EugeneErg\Graphs\ValueObjects\StageKind;
use EugeneErg\Graphs\ValueObjects\ZeroSlice;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Инструкция: что делают с клубком, по действию за раз.
 *
 * Проверять рассказ надо здесь, а не в кадрах. В кадрах видно только, что
 * получилось; порядок же — «сначала обвели, потом сделали», «по одному куску
 * за раз» — это свойство инструкции. Пока его проверяли по картинке, светофор
 * возвращался снова и снова: кадры-то каждый раз получались правдоподобные.
 */
final class ScriptServiceTest extends AbstractTestCase
{
    /**
     * Каждое выделение принадлежит своему действию.
     *
     * Обводить можно только то, с чем сейчас работают: шары, которых действие
     * не касается, в выделение не попадают. Это и есть то самое «выделил —
     * сделал то, ради чего выделял», записанное так, что нарушить его нельзя
     * случайно: выделение живёт внутри шага, а не само по себе.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testEveryMarkBelongsToItsOwnAction(array $connections): void
    {
        foreach ($this->compile($connections)->steps as $number => $step) {
            if ($step->marks === []) {
                continue;
            }

            $touched = $step->action->vertexes();

            self::assertNotSame([], $touched, sprintf(
                'Шаг %d обводит шары, но ничего с ними не делает: %s',
                $number,
                $step->describe(),
            ));
            self::assertSame([], array_values(array_diff($step->marks, $touched)), sprintf(
                'Шаг %d обводит лишнее: %s',
                $number,
                $step->describe(),
            ));
        }
    }

    /**
     * От каждого куска за раз отделяют одну часть.
     *
     * Разом отпустить все части одного куска нельзя: сначала обводится
     * десяток точек сочленения, потом полграфа разлетается в стороны — и не
     * видно, что от чего отрезали. Поэтому у «отделить» и нет множественного
     * числа: часть там ровно одна.
     *
     * А вот разные куски друг другу не мешают, и их отделяют одновременно
     * (`Together`). Правило от этого не слабеет, а становится точнее: одна
     * часть за раз — но от каждого куска своя. Две ветви одного куска делят
     * остаток, поэтому одновременными стать не могут.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testPartsLeaveOneAtATime(array $connections): void
    {
        $bad = [];

        foreach ($this->compile($connections)->of(Detach::class) as $step) {
            $seen = [];

            foreach ($step->actions() as $action) {
                self::assertInstanceOf(Detach::class, $action);

                if ($action->part === [] || $action->rest === []) {
                    $bad[] = 'пустая часть: ' . $step->describe();
                }

                // Ветвь держится за остаток точкой сочленения — её и обводят.
                // А несвязный кусок ни за что не держался: обводить нечего,
                // и кольцо на нём было бы обманом.
                if (! $action->table && array_intersect($action->part, $action->rest) === []) {
                    $bad[] = 'ветвь ни за что не держалась: ' . $step->describe();
                }

                // Одновременно — только чужие друг другу куски.
                if (array_intersect($seen, $action->rest) !== []) {
                    $bad[] = 'от одного куска отделяют дважды разом: ' . $step->describe();
                }

                $seen = array_merge($seen, $action->rest);
            }
        }

        self::assertSame([], $bad);
    }

    /**
     * Между находкой и разрезом ничего не стоит.
     *
     * Заливка встала — значит, граница найдена, и режут прямо сейчас. Если
     * между последней волной заливки и разрезом успевает вклиниться следующее
     * запирание, рассказ снова превращается в «нашли все, потом отрезали все»,
     * и смотреть на него невозможно.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testEveryFindIsCutRightAway(array $connections): void
    {
        $steps = $this->compile($connections)->steps;
        $bad = [];
        $painting = false;

        foreach ($steps as $number => $step) {
            if ($step->action instanceof Paint) {
                $painting = true;

                continue;
            }

            if (! $painting) {
                continue;
            }

            $painting = false;

            // После заливки либо режут найденное, либо резать нечего: краска
            // накрыла всё, и тогда дальше идёт следующий вопрос или просто
            // снимается выделение.
            // Заливка ответила: либо режут найденное, либо выносят наружу
            // то, что внутрь не поместилось, либо резать нечего вовсе.
            // Одновременное считается по отдельности: каждое из действий
            // обязано быть ответом.
            foreach ($step->actions() as $next) {
                if (! $next instanceof Detach && ! $next instanceof Block
                    && ! $next instanceof Cut && ! $next instanceof Show
                    && ! $next instanceof Aside
                ) {
                    $bad[] = sprintf('за заливкой на шаге %d идёт %s', $number, $step->describe());
                }
            }
        }

        self::assertSame([], $bad);
    }

    /**
     * Отрезают ровно те ветви, которые нашёл алгоритм.
     *
     * Рассказ показывает их в другом порядке — в том, в каком видна граница,
     * а не в том, в каком алгоритм записывает их на обратном ходу рекурсии.
     * Но сами ветви обязаны совпадать: показывать надо его работу, а не свою.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testCutBranchesAreTheOnesTheAlgorithmFound(array $connections): void
    {
        $trace = new Trace();
        $this->getPlanarService()->connectionsToFrames(
            $connections,
            new SliceAggregate(new ZeroSlice()),
            100.0,
            $trace,
        );
        $found = [];

        foreach ($trace->getStages() as $stage) {
            if ($stage->kind === StageKind::Branches) {
                foreach ($stage->groups as $branch) {
                    $found[] = self::sorted($branch);
                }
            }
        }

        if ($found === []) {
            self::assertTrue(true);

            return;
        }

        // Разрезы проигрываются как есть: кусок заменяется своими частями.
        // Что останется в конце — то алгоритм ветвями и назвал.
        $pieces = [self::sorted(array_map(intval(...), array_keys($connections)))];

        foreach ((new ScriptService())->compile($trace, $connections)->of(Detach::class) as $step) {
            foreach ($step->actions() as $action) {
                self::assertInstanceOf(Detach::class, $action);
                $whole = self::sorted(array_merge($action->part, $action->rest));
                $at = array_search($whole, $pieces, true);

                self::assertNotFalse($at, 'Режут то, чего на столе нет: ' . $step->describe());
                array_splice($pieces, (int) $at, 1, [
                    self::sorted($action->part),
                    self::sorted($action->rest),
                ]);
            }
        }

        sort($pieces);
        sort($found);
        self::assertSame($found, $pieces, 'Режут не те ветви, которые нашёл алгоритм.');
    }

    /**
     * @param int[] $vertexes
     *
     * @return int[]
     */
    private static function sorted(array $vertexes): array
    {
        $result = array_values(array_unique($vertexes));
        sort($result);

        return $result;
    }

    /**
     * В укладку встраивается по одному куску за действие.
     *
     * На одном шаге алгоритма кусков сходится несколько; если дать им общее
     * действие, они появятся в кадре разом, и не видно, что к чему пристроилось.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testLayoutGrowsPieceByPiece(array $connections): void
    {
        $built = [];

        foreach ($this->compile($connections)->of(Build::class) as $step) {
            self::assertInstanceOf(Build::class, $step->action);
            self::assertArrayNotHasKey($step->action->piece, $built, 'Кусок укладывают дважды.');
            $built[$step->action->piece] = true;
        }
    }

    /**
     * Склейка — три действия подряд: сдвинуть, связать, разрезать.
     *
     * Одним шагом это делать нельзя: грани обменяются шарами прямо на глазах,
     * и не видно ни что с чем склеили, ни откуда взялась новая палка.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testGlueIsThreeActions(array $connections): void
    {
        $steps = $this->compile($connections)->steps;
        $glues = [];

        foreach ($steps as $number => $step) {
            if (! $step->action instanceof Join) {
                continue;
            }

            $glues[] = array_map(
                static fn (int $shift): string => ($steps[$number + $shift] ?? null)?->action::class ?? '',
                [0, 1, 2],
            );
        }

        self::assertSame(
            array_fill(0, count($glues), [Join::class, Tie::class, Cut::class]),
            $glues,
            'Склейка — это сдвинуть, связать, разрезать; каждое своим действием.',
        );
    }

    /**
     * Связку вяжут между теми шарами, которых в сшиваемых гранях ещё не
     * связывали: она и есть то единственное, что склейка добавляет к графу.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testTieAddsAnEdgeThatWasNotThere(array $connections): void
    {
        $steps = $this->compile($connections)->steps;
        $bad = [];

        foreach ($steps as $number => $step) {
            if (! $step->action instanceof Tie) {
                continue;
            }

            $join = $steps[$number - 1]->action ?? null;

            self::assertInstanceOf(Join::class, $join);
            $name = Scene::edgeName($step->action->edge[0], $step->action->edge[1]);

            if ($step->action->edge[0] === $step->action->edge[1]) {
                $bad[] = 'связывают шар сам с собой: ' . $step->describe();
            }

            foreach ([$join->first, $join->second] as $walk) {
                foreach (Piece::ofWalk($walk)->edges as [$vertexA, $vertexB]) {
                    if (Scene::edgeName($vertexA, $vertexB) === $name) {
                        $bad[] = 'связывают то, что и так связано: ' . $step->describe();
                    }
                }
            }
        }

        self::assertSame([], $bad);
    }

    /**
     * У связки видна причина: её концы — это то, куда дошли, отмеряя
     * по половине каждой из двух сошедшихся граней от точки сочленения.
     *
     * Без этого связка на картинке берётся ниоткуда: только что были две
     * грани — и вдруг между двумя случайными на вид шарами протянут пунктир.
     * Алгоритм — чистая логика, и правило тут простое; значит, и на картинке
     * оно должно читаться целиком, без подсказок.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testTieShowsWhereItCameFrom(array $connections): void
    {
        $bad = [];

        foreach ($this->compile($connections)->of(Tie::class) as $step) {
            self::assertInstanceOf(Tie::class, $step->action);
            $action = $step->action;

            if (count($action->paths) !== 2) {
                $bad[] = 'связку тянут без отмера: ' . $step->describe();

                continue;
            }

            $ends = [];

            foreach ($action->paths as $path) {
                if (($path[0] ?? null) !== $action->at) {
                    $bad[] = 'отмеряют не от точки сочленения: ' . $step->describe();
                }

                $ends[] = $path[count($path) - 1] ?? null;
            }

            sort($ends);
            $edge = $action->edge;
            sort($edge);

            if ($ends !== $edge) {
                $bad[] = 'связка тянется не туда, куда дошли: ' . $step->describe();
            }
        }

        self::assertSame([], $bad);
    }

    /**
     * Рассказ идёт по порядку: сначала граф, в конце — расслабление, и оно
     * ровно одно. Между ними резать и складывать можно только в таком порядке:
     * укладывают то, что уже нарезано.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testStoryRunsInOrder(array $connections): void
    {
        $steps = $this->compile($connections)->steps;
        $kinds = array_map(static fn (Step $step): string => $step->action::class, $steps);

        self::assertSame(Show::class, $kinds[0] ?? null, 'Рассказ начинается не с графа.');
        self::assertSame(Relax::class, array_pop($kinds), 'Рассказ кончается не расслаблением.');
        self::assertNotContains(Relax::class, $kinds, 'Расслабление не одно.');

        $building = false;

        foreach ($steps as $step) {
            $building = $building || $step->action instanceof Build;

            self::assertFalse(
                $building && ($step->action instanceof Cut || $step->action instanceof Detach),
                'Режут то, что уже уложили: ' . $step->describe(),
            );
        }
    }

    /**
     * Инструкция читается глазами: у каждого шага есть человеческое описание.
     *
     * Ради этого она и заведена. Порядок рассказа проверяют по ней, а не по
     * кадрам, — значит, читать её должно быть можно.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testInstructionReads(array $connections): void
    {
        foreach ($this->compile($connections)->describe() as $number => $line) {
            self::assertNotSame('', trim($line), sprintf('Шаг %d нечем прочитать.', $number));
        }
    }


    /**
     * Графы, на которых видно все действия сразу: на одном связном резать
     * нечего, на одном двусвязном нечего склеивать.
     *
     * @return array<string, array{true[][]}>
     */
    /**
     * Этап можно рассказать отдельно от остальных.
     *
     * Так смотрят разбиение: несвязные куски и двусвязные ветви показываем,
     * а поиск полей не показываем вовсе. Раньше для этого резались готовые
     * кадры, и выходила неправда: алгоритм занимается кусками по очереди,
     * поэтому «до последнего разреза ветви» — это ещё и все поля кусков
     * до него.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testStageCanBeToldWithoutTheRest(array $connections): void
    {
        $whole = $this->compile($connections);
        $dropped = [StageKind::Field, StageKind::Tie, StageKind::Faces, StageKind::OuterFace, StageKind::Order, StageKind::Build, StageKind::Relax];
        $part = $whole->without(...$dropped);

        foreach ($part->steps as $step) {
            self::assertNotContains($step->kind, $dropped, sprintf(
                'Выкинутый этап всё равно рассказывается: %s',
                $step->describe(),
            ));
        }

        self::assertSame(
            count($whole->of(Detach::class)),
            count($part->of(Detach::class)),
            'Разбиение выкинулось вместе с полями, хотя его не трогали.',
        );
    }

    /**
     * Вопрос уезжает вместе со своим ответом.
     *
     * Запирание и заливка сами по себе ничего не значат: это вопрос, который
     * задают перед действием. Если оставить вопрос, а ответ выкинуть, на
     * картинке останется запертый обход, про который так и не сказали, зачем
     * его запирали.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testQuestionLeavesWithItsAnswer(array $connections): void
    {
        $part = $this->compile($connections)->without(
            StageKind::Field,
            StageKind::Tie,
            StageKind::Faces,
            StageKind::OuterFace,
            StageKind::Order,
            StageKind::Build,
            StageKind::Relax,
        );
        $asked = null;

        foreach ($part->steps as $step) {
            if ($step->action->isQuestion()) {
                $asked ??= $step;

                continue;
            }

            $asked = null;
        }

        self::assertNull($asked, sprintf(
            'Рассказ кончается вопросом без ответа: %s',
            $asked?->describe() ?? '',
        ));
    }

    /**
     * Каждый кусок разбирается до конца и своим порядком.
     *
     * Семь несвязных кусков, в каждом семь двусвязных. Значит, отделений
     * ровно шесть (седьмой кусок — то, что осталось) и разрезов ветвей
     * ровно шесть на кусок. Если где-то сорвалось, здесь это и видно:
     * по кадрам такое не прочесть, там сотни кружков.
     */
    public function testEveryPartIsTakenApartToTheEnd(): void
    {
        $script = $this->compile(self::getSevenBySeven());
        $parts = 0;
        $branches = 0;
        $cuts = 0;

        foreach ($script->steps as $step) {
            $parts += $step->kind === StageKind::Components ? 1 : 0;

            if ($step->kind !== StageKind::Branches) {
                continue;
            }

            $branches++;
            $cuts += count($step->actions());
        }

        self::assertSame(6, $parts, 'Несвязных кусков нашлось не семь.');
        // Круги общие для всех кусков: шесть кругов по семь разрезов в каждом.
        self::assertSame(6, $branches, 'Кругов разбора вышло не шесть.');
        self::assertSame(6 * 7, $cuts, 'Двусвязных в кусках нашлось не по семь.');
    }

    /**
     * Все три уровня разбора на одной фикстуре.
     *
     * Три несвязных куска, в каждом три двусвязных, и каждый двусвязный —
     * колесо с семью спицами. Значит: два отделения кусков (третий — то, что
     * осталось), по два разреза ветвей на кусок и по восемь граней на ветвь —
     * семь внутри колеса и обод.
     *
     * Приём на всех трёх уровнях один и тот же: запереть и залить. Здесь
     * видно, что он и правда один, и что ни один уровень не потерялся.
     */
    public function testAllThreeLevelsAreToldToTheEnd(): void
    {
        $counts = [];

        foreach ($this->compile(self::getSevenFields())->steps as $step) {
            $kind = $step->kind?->value ?? '-';
            $counts[$kind] = ($counts[$kind] ?? 0) + count($step->actions());
        }

        self::assertSame(2, $counts['components'] ?? 0, 'Несвязных кусков нашлось не три.');
        self::assertSame(3 * 2, $counts['branches'] ?? 0, 'Двусвязных в кусках нашлось не по три.');
        self::assertSame(9 * 8, $counts['field'] ?? 0, 'Полей в двусвязных нашлось не по восемь.');
    }

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
     */
    private function compile(array $connections): Script
    {
        $trace = new Trace();
        $this->getPlanarService()->connectionsToFrames(
            $connections,
            new SliceAggregate(new ZeroSlice()),
            100.0,
            $trace,
        );

        return (new ScriptService())->compile($trace, $connections);
    }
}
