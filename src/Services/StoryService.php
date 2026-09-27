<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\Aggregates\CutPlan;
use EugeneErg\Graphs\Aggregates\Trace;
use EugeneErg\Graphs\ValueObjects\Piece;
use EugeneErg\Graphs\ValueObjects\Places;
use EugeneErg\Graphs\ValueObjects\Point2D;
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
use EugeneErg\Graphs\ValueObjects\Script\Together;
use EugeneErg\Graphs\ValueObjects\StageKind;
use EugeneErg\Graphs\ValueObjects\Table;
use EugeneErg\Graphs\ValueObjects\TableSize;

/**
 * Исполняет инструкцию: превращает действия в кадры.
 *
 * Инструкцию собирает `ScriptService` — она говорит, что делают с клубком,
 * по одному действию за раз. Здесь решается только одно: где это нарисовать.
 *
 * Рассказ держится на трёх правилах.
 *
 * Первое: за выделением сразу следует то, ради чего выделяли. Правило не
 * соблюдается, а обеспечивается: выделение живёт внутри действия (`Step`),
 * поэтому кадры выделения и кадр действия выдаются вместе и разъединить их
 * нечем. Пока кадры собирались по видам шагов, раз за разом получался
 * светофор — сначала обводилось всё, потом всё разом происходило.
 *
 * Второе: резать по-настоящему. Отрезанный кусок уезжает в сторону целиком, а
 * вершины и рёбра на границе разреза раздваиваются, чтобы связи с бывшим
 * соседом не осталось: так же отрезают кусок пирога.
 *
 * Третье: едет только то, что режут. Инструкция проиграна целиком до первого
 * кадра (`CutPlan`), поэтому у куска одно место на столе на всю анимацию, а его
 * экземпляры лежат поверх оригиналов ещё до разреза. Отрезанное отделяется от
 * графа на глазах, а не появляется из воздуха, и соседние куски при этом
 * стоят на месте.
 *
 * Подписей нет: что происходит, должно быть видно по самому происходящему.
 */
final readonly class StoryService
{
    /** Сколько времени держится выделение перед действием. */
    private const float MARK_WEIGHT = 0.7;

    /** Действие: кусок отъезжает, поле встаёт на место. */
    private const float ACT_WEIGHT = 1.0;

    /**
     * Волна заливки: за это время краска переползает по ребру до следующей
     * вершины. Слишком коротко — и видно только, что точки меняют цвет,
     * а как одна закрашивает другую, уследить нельзя.
     */
    private const float WAVE_WEIGHT = 1.0;

    /** Столько времени занимает целиком шаг, на котором вершины едут. */
    private const float MOVING_WEIGHT = 4.0;

    /** Насколько круг мест отодвинут от исходного рисунка. */
    private const float RING_GAP = 1.5;

    /** Надрезанное ребро: одно поле его уже забрало, второе ещё нет. */
    private const int HALF_GROUP = -1;

    /** Первый кадр расслабления повторяет собранную укладку: держать его незачем. */
    private const float BEAT_WEIGHT = 0.15;

    /** Стол, на котором лежит весь граф, пока он не распался на куски. */
    private const int WHOLE_TABLE = 0;

    /**
     * В какой кадр укладки собирают.
     *
     * Кадр 0 — клубок, кадр 1 — разметка по дугам: там вершины дуги лежат
     * вплотную к краю, ближайшая пара расходится на треть пикселя. Собирать
     * туда нельзя: поле прилетает сплющенным в линию на границу, и не то что
     * порядок — сами поля неразличимы. Первая настоящая укладка — следующая,
     * барицентрическая: она уже плоская и невырожденная, с неё и начинается
     * расслабление.
     */
    private const int BUILT_FRAME = 2;

    public function __construct(
        private MotionService $motion = new MotionService(),
        private int $targetFrames = 2,
        private ScriptService $script = new ScriptService(),
        private PlaceService $place = new PlaceService(),
    ) {
    }

    /**
     * @param Point2D[][] $frames кадры укладки: клубок, начальная раскладка, расслабление
     * @param array<int, array<int, mixed>> $connections
     * @param ?Script $script рассказать не всю инструкцию, а эту: так
     *                        смотрят один этап отдельно от остальных
     *
     * @return Scene[]
     */
    public function build(Trace $trace, array $frames, array $connections, ?Script $script = null): array
    {
        $frames = array_values($frames);
        $tangled = $frames[0] ?? [];
        $edges = $this->motion->getEdges($connections);
        $whole = Piece::ofEdges($edges);
        $script ??= $this->script->compile($trace, $connections);

        if ($trace->getStages() === [] || $tangled === []) {
            return $this->getMovingScenes(new CutPlan($whole, []), $frames, $edges, 0, count($frames) - 1);
        }

        $plan = $this->script->execute($trace, $connections, $script);
        $spacing = $this->getSpacing($tangled);
        $frame = min(self::BUILT_FRAME, count($frames) - 1);
        $tables = $this->getTables($plan, $tangled, array_slice($frames, $frame), $spacing);
        $where = $this->getVertexTables($plan);
        // Каждый кусок собирается у себя на столе, поэтому готовая укладка
        // переносится туда же.
        $building = $this->getShifted($frames[$frame] ?? $tangled, $plan, $tables);
        $moving = array_map(
            fn (array $frame): array => $this->getShifted($frame, $plan, $tables),
            $frames,
        );
        $result = [];
        $built = [];
        $next = [];
        // Заливка красит куски графа, и краска с них не сходит: зелёное —
        // это «один кусок», и оно должно остаться зелёным, пока кусок цел.
        $painted = [];
        // Запертый обход, по порядку: пока он заперт, видно, про что спрашивают.
        $locked = [];
        // Запертые обходы: по ним столы и разложены по трём кругам. Их две
        // пары — «как было» и «как стало»: раскладка меняется вместе с самим
        // действием, а не на коротком кадре выделения перед ним. Иначе полстола
        // перепрыгивает за долю секунды.
        $examined = [];
        $moved = [];
        // Вынесенное наружу: не поместилось внутрь запертого обхода.
        $aside = [];
        $moved = [];

        foreach ($script->steps as $number => $step) {
            if ($step->action instanceof Build) {
                $built[] = $step->action->piece;
            }

            if ($step->action instanceof Paint) {
                $painted = $step->action->groups + $painted;
            }

            $blocked = false;

            foreach ($step->actions() as $action) {
                // Запирание задаёт новый вопрос: что накроет заливка, упёршись
                // в эти шары. Прежние ответы к нему отношения не имеют,
                // поэтому краска сбрасывается, и в кадре остаётся только
                // запертое.
                if ($action instanceof Block) {
                    // Порядок обхода важен: запертое ложится на средний круг
                    // многоугольником, а не сбивается в дугу там, где лежало.
                    $locked = $blocked ? array_merge($locked, $action->vertexes) : $action->vertexes;
                    $painted = $blocked ? $painted : [];
                    $blocked = true;

                    // Круги — про поиск полей: там запирают целый обход.
                    // Запертая точка сочленения раскладку не трогает: кусок
                    // лежит как лежал, а спрашивают только, что на ней висит.
                    if ($action->walk) {
                        // Раскладки копятся: стол, уже разложенный по кругам,
                        // так и остаётся разложенным. Иначе стоит алгоритму
                        // заняться другим куском, и первый разом схлопнется.
                        $next[] = $action->vertexes;
                        $moved = [];
                    }
                }

                // Проверка решила: этот кусок внутрь не помещается. Он уезжает
                // на внешний круг и ждёт там до конца разбора этого обхода.
                if ($action instanceof Aside) {
                    $moved += array_fill_keys($action->vertexes, true);
                }
            }


            $scenes = $this->getStepScenes($plan, $number + 1, $step, $built, $painted, $locked, [$examined, $next], [$aside, $moved], $tangled, $tables, $spacing, $building, $moving, $edges, $frame, $where);
            $last = count($scenes) - 1;

            foreach ($scenes as $position => $scene) {
                // Ответ снимает запор, и в том же кадре, в котором отвечают.
                // Запирают, чтобы спросить: что накроет заливка, упёршись
                // в эти шары. Отрезали по ответу — вопроса больше нет,
                // и колец тоже.
                //
                // Пока запор держался до следующего запирания, последний так
                // и оставался висеть до конца: готовая укладка стояла
                // с кольцами на шарах, про которые давно всё решено.
                $result[] = $this->getLocked(
                    $scene,
                    $step->action->isQuestion() || $position < $last ? $locked : [],
                );
            }

            $examined = $next;
            $aside = $moved;

            if (! $step->action->isQuestion()) {
                $locked = [];
            }


        }

        return $result;
    }

    /**
     * Отметить в кадре запертые шары — все их экземпляры.
     *
     * Заперт шар, а не его копия: копии стоят друг на друге и на картинке
     * неразличимы, поэтому запереть одну и оставить другую открытой значило бы
     * показать неправду.
     *
     * @param int[] $locked
     */
    private function getLocked(Scene $scene, array $locked): Scene
    {
        if ($locked === []) {
            return $scene;
        }

        $inside = array_flip($locked);
        $keys = [];

        foreach (array_keys($scene->vertexes) as $key) {
            if (isset($inside[Scene::vertexOf($key)])) {
                $keys[$key] = true;
            }
        }

        return $keys === [] ? $scene : new Scene(
            vertexes: $scene->vertexes,
            edges: $scene->edges,
            groups: $scene->groups,
            highlight: $scene->highlight,
            faded: $scene->faded,
            weight: $scene->weight,
            kind: $scene->kind,
            flows: $scene->flows,
            ties: $scene->ties,
            blocked: $keys,
        );
    }

    /**
     * Инструкция, проигранная целиком до первого кадра.
     *
     * @param array<int, array<int, mixed>> $connections
     */
    public function getCutPlan(Trace $trace, array $connections): CutPlan
    {
        return $this->script->execute($trace, $connections, $this->script->compile($trace, $connections));
    }

    /**
     * Кадры одного действия: сначала выделение, потом само действие.
     *
     * Ровно в таком порядке и без исключений — выделение и действие приходят
     * одним шагом, поэтому выдать одно без другого нельзя. Состояние — это то,
     * что стало после действия; предыдущее — то, что было до: выделение
     * показывается на «до», действие — на «после».
     *
     * Действие, которое клубок не изменило, кадров не получает вовсе: обводить
     * нечего и показывать нечего.
     *
     * @param int[] $built куски, которые уже уложены
     * @param array<int, int> $painted шар => каким цветом его закрасила заливка
     * @param int[] $locked запертый обход: краска через него не идёт
     * @param array{array<int, int[]>, array<int, int[]>} $examined запертые
     *        обходы до действия и после: раскладка меняется вместе с действием
     * @param array{array<int, true>, array<int, true>} $aside вынесенное наружу
     *        до действия и после
     * @param Point2D[] $tangled
     * @param array<int, Table> $tables
     * @param Point2D[] $building
     * @param Point2D[][] $moving
     * @param array<int, array{int, int}> $edges
     * @param int $frame кадр укладки, в который собирают
     * @param array<int, int[]> $where шар => столы, на которых он побывал
     *
     * @return Scene[]
     */
    private function getStepScenes(
        CutPlan $plan,
        int $state,
        Step $step,
        array $built,
        array $painted,
        array $locked,
        array $examined,
        array $aside,
        array $tangled,
        array $tables,
        float $spacing,
        array $building,
        array $moving,
        array $edges,
        int $frame,
        array $where,
    ): array {
        // Расслабление начинается ровно там, где кончилась сборка: иначе
        // картинка скакнёт назад, в разметку по дугам.
        if ($step->action instanceof Relax) {
            return $this->getMovingScenes($plan, $moving, $edges, $frame, count($moving) - 1);
        }

        // Пока обход заперт, он лежит на среднем круге: видно, про что
        // спрашивают. Разрез запор снимает, поэтому после действия запертого
        // уже нет — и грань уезжает с того самого круга.
        $before = $this->getPlaces($plan, $state - 1, $tangled, $tables, $spacing, $examined[0], $aside[0], $where);
        $after = $this->getPlaces($plan, $state, $tangled, $tables, $spacing, $examined[1], $aside[1], $where);
        // Надрез виден в тот момент, когда режут, а не когда отрезанное
        // доехало до своего места: это же и есть разрез.
        $shift = $painted === [] ? 0 : max($painted) + 1;
        $isHalf = $this->getHalfGroups($plan, $state, $after)
            + $this->getFieldGroups($plan, $state, $after, $shift)
            + $this->getFillGroups($after, $painted);
        $wasHalf = $this->getHalfGroups($plan, $state, $before)
            + $this->getFieldGroups($plan, $state, $before, $shift)
            + $this->getFillGroups($before, $painted);
        $ties = $plan->getTies();
        $kind = $step->kind ?? StageKind::Graph;

        if ($step->action instanceof Build) {
            return [$this->getBuildScene($plan, $after, $kind, $built, $building, $isHalf)];
        }

        if ($step->action instanceof Paint) {
            return [$this->getPaintedScene($after, $step->action, $isHalf, $ties)];
        }

        // Вопрос клубок не меняет, но кадр ему нужен: без запирания заливка
        // потом останавливается непонятно почему.
        $shows = $step->action->isQuestion();

        if (! $shows && ! $plan->isChanged($state)) {
            return [];
        }

        $marked = $this->getMarkKeys($plan, $state, $before, $step);
        // Поле не просто обводится — оно заливается своим цветом, и краска
        // приходит по связям, как в самой первой заливке. Заливка в рассказе
        // одна, меняется только то, что заливают.
        $cuts = [];
        $tie = null;

        foreach ($step->actions() as $action) {
            if ($action instanceof Cut) {
                $cuts[] = $action->walk;
            }

            // Связку тянут не куда попало: от точки сочленения отмеряют
            // по половине каждой грани. Отмер и показывается — иначе палка
            // берётся ниоткуда, и вместо логики выходит фокус.
            $tie ??= $action instanceof Tie ? $action : null;
        }

        $filling = match (true) {
            $cuts !== [] => $this->getFillingScenes($plan, $state, $before, $cuts, $marked, $wasHalf, $ties, $shift),
            $tie !== null => $this->getMeasuringScenes($plan, $state, $before, $tie, $wasHalf, $ties),
            default => [],
        };

        return array_merge(
            $filling !== [] ? $filling : ($marked === [] ? [] : $this->getMarkedScenes($before, $marked, $step->marked ?? $kind, $wasHalf, $ties)),
            [$this->getScene($after, $kind, $isHalf, [], [], self::ACT_WEIGHT, [], $ties)],
        );
    }

    /**
     * Как поле заливается своим цветом: краска обходит контур по связям.
     *
     * Это та же заливка, что искала куски графа в начале, — просто теперь
     * заливают поле. Шар закрашен, дальше по палке от него ползёт полоса того
     * же цвета и доходит до соседа ровно к следующему кадру, тогда сосед и
     * становится закрашенным. Когда обойдён весь контур, поле стоит целиком
     * закрашенным — и следующим кадром его вырезают.
     *
     * Мгновенная перекраска не годится: граф на глазах красится второй раз,
     * и непонятно, при чём тут первая заливка. А так видно, что это одно
     * и то же действие над разными вещами.
     *
     * @param Places $places
     * @param array<int, int[]> $walks обходы полей, которые режут прямо сейчас
     * @param array<string, true> $marked
     * @param array<string, int> $groups
     * @param array<string, true> $ties
     *
     * @return Scene[]
     */
    private function getFillingScenes(
        CutPlan $plan,
        int $state,
        Places $places,
        array $walks,
        array $marked,
        array $groups,
        array $ties,
        int $shift,
    ): array {
        // Полей режут столько, сколько кусков разбирают одновременно, и у
        // каждого свой цвет: тот, с каким оно ляжет на своё место.
        $colors = [];

        foreach ($plan->getState($state)['acted'] as $number => $acted) {
            $place = $plan->getState($state)['places'][$acted] ?? null;

            if ($place !== null && $place !== CutPlan::HOME && isset($walks[$number])) {
                $colors[$number] = $place + $shift;
            }
        }

        $waves = [];
        $length = 0;

        foreach ($colors as $number => $color) {
            $waves[$number] = $this->getWaves($walks[$number]);
            $length = max($length, count($waves[$number]));
        }

        if ($length === 0) {
            return [];
        }

        $painted = [];
        $result = [];
        // Кольца остаются на шарах, а палки обводкой не закрашиваются: обводка
        // перебила бы цвет, ради которого всё и делается. Контур поля и так
        // виден — по краске, которая по нему ползёт.
        $rings = array_filter(
            $marked,
            static fn (string $key): bool => ! str_contains($key, '-'),
            ARRAY_FILTER_USE_KEY,
        );

        for ($number = 0; $number < $length; $number++) {
            $flows = [];

            foreach ($colors as $track => $color) {
                $wave = $waves[$track][$number] ?? null;

                if ($wave === null) {
                    continue;
                }

                foreach ($wave as $vertex) {
                    $painted[$vertex] = $color;
                }

                // Краска ползёт по тем палкам, по которым она в этот кадр и
                // пришла: от волны, закрашенной в прошлом кадре, к той, что
                // закрашивается сейчас. Так же, как в первой заливке.
                $flows = array_merge(
                    $flows,
                    $this->getWaveFlows($places, $marked, $waves[$track][$number - 1] ?? [], $wave, $color),
                );
            }

            $result[] = $this->getScene(
                $places,
                StageKind::Field,
                $this->getPaintedKeys($places, $painted, $marked) + $groups,
                $rings,
                [],
                self::MARK_WEIGHT,
                $flows,
                $ties,
            );
        }

        return $result;
    }

    /**
     * Как отмеряют место для связки: от точки сочленения обвод ползёт
     * по половине каждой из двух сошедшихся граней, шаг за шагом, и там,
     * где обе дорожки останавливаются, в следующем кадре появляется палка.
     *
     * Без этого связка берётся ниоткуда: только что были две грани — и вдруг
     * между двумя случайными на вид шарами протянут пунктир. А правило тут
     * простое и целиком видимое: полграни туда, полграни сюда, соединить
     * концы. Алгоритм — чистая логика, и она должна читаться с картинки.
     *
     * @param Places $places
     * @param array<string, int> $groups
     * @param array<string, true> $ties
     *
     * @return Scene[]
     */
    private function getMeasuringScenes(CutPlan $plan, int $state, Places $places, Tie $action, array $groups, array $ties): array
    {
        $touched = $plan->getTouched($state);
        $length = 0;

        foreach ($action->paths as $path) {
            $length = max($length, count($path));
        }

        if ($touched === [] || $length < 2) {
            return [];
        }

        $result = [];

        for ($step = 1; $step < $length; $step++) {
            $marked = [];

            foreach ($action->paths as $path) {
                $walked = array_slice($path, 0, $step + 1);

                foreach ($walked as $number => $vertex) {
                    $this->addKey($marked, $plan, $places, $touched, $vertex, $walked[$number - 1] ?? null);
                }
            }

            $result[] = $this->getScene(
                $places,
                StageKind::Tie,
                $groups,
                $marked,
                [],
                self::MARK_WEIGHT,
                [],
                $ties,
            );
        }

        return $result;
    }

    /**
     * Отметить шар, а заодно и палку, по которой до него дошли.
     *
     * @param array<string, true> $marked
     * @param Places $places
     * @param int[] $touched
     */
    private function addKey(array &$marked, CutPlan $plan, Places $places, array $touched, int $vertex, ?int $previous): void
    {
        foreach ($touched as $id) {
            $key = $places->pieces[$id][$vertex] ?? null;

            if ($key !== null) {
                $marked[$key] = true;
            }

            if ($previous === null) {
                continue;
            }

            $edge = $plan->getCutout($id)->edges[Scene::edgeName($vertex, $previous)] ?? null;

            if ($edge !== null && isset($places->edges[$edge])) {
                $marked[$edge] = true;
            }
        }
    }

    /**
     * Волны обхода контура: с какого шара начали и куда краска доползла
     * на каждом шаге. Идёт она в обе стороны сразу, как по любому графу.
     *
     * @param int[] $walk
     *
     * @return array<int, int[]>
     */
    private function getWaves(array $walk): array
    {
        $neighbours = [];

        foreach (Piece::ofWalk($walk)->edges as [$vertexA, $vertexB]) {
            $neighbours[$vertexA][$vertexB] = $vertexB;
            $neighbours[$vertexB][$vertexA] = $vertexA;
        }

        if ($neighbours === []) {
            return [];
        }

        $seen = [];
        $wave = [$walk[0] ?? array_key_first($neighbours)];
        $result = [];

        while ($wave !== []) {
            $result[] = $wave;
            $next = [];

            foreach ($wave as $vertex) {
                $seen[$vertex] = true;
            }

            foreach ($wave as $vertex) {
                foreach ($neighbours[$vertex] ?? [] as $neighbour) {
                    if (! isset($seen[$neighbour])) {
                        $next[$neighbour] = $neighbour;
                    }
                }
            }

            $wave = array_values($next);
        }

        return $result;
    }

    /**
     * Закрашенные экземпляры: шары и палки между двумя закрашенными.
     * Берутся только те, что принадлежат заливаемому полю, — красят его,
     * а не все копии этих шаров по столу.
     *
     * @param Places $places
     * @param array<int, int> $painted
     * @param array<string, true> $marked
     *
     * @return array<string, int>
     */
    private function getPaintedKeys(Places $places, array $painted, array $marked): array
    {
        $result = [];

        foreach ($places->pieces as $vertexes) {
            foreach ($vertexes as $vertex => $key) {
                if (isset($marked[$key], $painted[$vertex])) {
                    $result[$key] = $painted[$vertex];
                }
            }
        }

        foreach ($places->edges as $key => [$from, $to]) {
            if (isset($marked[$key], $painted[Scene::vertexOf($from)], $painted[Scene::vertexOf($to)])) {
                $result[$key] = $painted[Scene::vertexOf($from)];
            }
        }

        return $result;
    }

    /**
     * Куда краска ползёт на этом кадре: по палкам от уже закрашенного шара
     * к тому, который закрасится следующим.
     *
     * @param Places $places
     * @param array<string, true> $marked
     * @param int[] $was шары, закрашенные прошлым кадром: оттуда краска и идёт
     * @param int[] $now шары, которые закрашиваются сейчас
     *
     * @return array<string, array{string, int}>
     */
    private function getWaveFlows(Places $places, array $marked, array $was, array $now, int $color): array
    {
        $source = array_flip($was);
        $target = array_flip($now);
        $result = [];

        foreach ($places->edges as $key => [$from, $to]) {
            if (! isset($marked[$key])) {
                continue;
            }

            $one = Scene::vertexOf($from);
            $two = Scene::vertexOf($to);

            if (isset($source[$one], $target[$two])) {
                $result[$key] = [$from, $color];
            } elseif (isset($source[$two], $target[$one])) {
                $result[$key] = [$to, $color];
            }
        }

        return $result;
    }

    /**
     * Что обводят перед действием: названные шары и палки между ними — у тех
     * кусков, с которыми действие и работает.
     *
     * Палки обводятся вместе с шарами, и без них обвод бесполезен: в клубке
     * шары одного поля разбросаны по всей окружности, и четыре кольца на них
     * читаются как четыре случайные точки. Поле видно только тогда, когда
     * загорается его контур.
     *
     * Обводятся именно тронутые куски, а не все: тот же шар может лежать копией
     * в другом куске на другом конце стола, и с ним сейчас ничего не делают.
     *
     * @param Places $places
     *
     * @return array<string, true>
     */
    private function getMarkKeys(CutPlan $plan, int $state, Places $places, Step $step): array
    {
        $marks = $step->marks;
        $touched = $plan->getTouched($state);
        $result = $touched === [] ? $this->getKeysOf($places, $marks) : [];

        foreach ($touched as $id) {
            foreach ($marks as $vertex) {
                $key = $places->pieces[$id][$vertex] ?? null;

                if ($key !== null) {
                    $result[$key] = true;
                }
            }

            foreach ($this->getMarkedEdges($step->action) as [$vertexA, $vertexB]) {
                $key = $plan->getCutout($id)->edges[Scene::edgeName($vertexA, $vertexB)] ?? null;

                if ($key !== null && isset($places->edges[$key])) {
                    $result[$key] = true;
                }
            }
        }

        return $result === [] ? $this->getKeysOf($places, $marks) : $result;
    }

    /**
     * Какие палки входят в обводимое: контур грани, которую сейчас режут или
     * сдвигают. У «отделить» и «связать» контура нет — там обводят сам шов
     * и сами два шара.
     *
     * @return array<int, array{int, int}>
     */
    private function getMarkedEdges(ActionInterface $action): array
    {
        if ($action instanceof Together) {
            $result = [];

            foreach ($action->actions as $item) {
                $result = array_merge($result, $this->getMarkedEdges($item));
            }

            return $result;
        }

        return match (true) {
            $action instanceof Cut => Piece::ofWalk($action->walk)->edges,
            $action instanceof Join => array_merge(
                Piece::ofWalk($action->first)->edges,
                Piece::ofWalk($action->second)->edges,
            ),
            $action instanceof Show => Piece::ofWalk($action->vertexes)->edges,
            default => [],
        };
    }

    /**
     * Где что лежит в этом состоянии.
     *
     * Живые куски стоят каждый на своём месте, а ещё не отрезанные — поверх
     * того куска, из которого их вырежут. Уже разрезанные не рисуются: их
     * экземпляры достались частям.
     *
     * @param Point2D[] $tangled
     * @param array<int, Table> $tables
     * @param array<int, int[]> $locked запертые обходы по столам: каждый
     *                                   уходит в середину своего стола
     * @param array<int, true> $aside вынесенное наружу: на внешний круг
     * @param array<int, int[]> $visited шар => столы, на которых он побывал
     */
    private function getPlaces(CutPlan $plan, int $number, array $tangled, array $tables, float $spacing, array $locked = [], array $aside = [], array $visited = []): Places
    {
        $state = $plan->getState($number);
        $live = array_flip($state['live']);
        $positions = [];
        $pieces = [];
        $hosts = [];
        $waiting = [];

        foreach ($state['live'] as $id) {
            $cutout = $plan->getCutout($id);
            $place = $state['places'][$id];
            $table = $tables[$plan->getTable($id)] ?? null;
            $points = $table === null || $place === CutPlan::HOME
                ? $this->getHomePoints($cutout->piece, $tangled, $table)
                : $this->place->getWheel(
                    $cutout->piece->vertexes,
                    $table->slot($place),
                    $this->place->getPieceRadius(count($cutout->piece->vertexes), $spacing),
                );
            $hosts[$id] = $id;

            foreach ($points as $vertex => $point) {
                $positions[$cutout->vertexes[$vertex]] = $point;
                $pieces[$id][$vertex] = $cutout->vertexes[$vertex];
            }
        }

        // У какого живого куска сейчас лежит каждый шар. Считается один раз
        // на состояние: раньше это был перебор живых кусков на каждый шар
        // каждой копии, то есть куб от размера графа. На семи колёсах в семи
        // кусках он и съедал всё время — двадцать минут на кадры при десяти
        // секундах на сам алгоритм.
        $holders = [];

        foreach ($state['live'] as $id) {
            foreach ($plan->getCutout($id)->vertexes as $vertex => $key) {
                $holders[$vertex] ??= $id;
            }
        }

        foreach ($plan->getCutouts() as $id => $cutout) {
            if (isset($live[$id]) || $plan->getBirth($id) <= $number) {
                continue;
            }

            $host = $plan->getHost($id, $live);

            if ($host === null) {
                continue;
            }

            $hosts[$id] = $host;

            foreach ($cutout->piece->vertexes as $vertex) {
                $own = $cutout->vertexes[$vertex];
                $key = $plan->getCutout($host)->vertexes[$vertex] ?? null;
                $where = $key !== null && isset($positions[$key]) ? $host : null;
                // Копия, заведённая для будущего разреза, ждёт поверх своего
                // шара — где бы он сейчас ни лежал.
                $where ??= $holders[$vertex] ?? null;

                if ($where === null) {
                    continue;
                }

                $point = $positions[$plan->getCutout($where)->vertexes[$vertex]] ?? null;

                if ($point === null) {
                    continue;
                }

                $positions[$own] ??= $point;
                $pieces[$id][$vertex] = $own;
                $waiting[$id][$vertex] = $where;
            }
        }

        // Слившийся при объединении шар остаётся лежать поверх того, в который
        // слился: шаров стало меньше, а из кадра ничего не пропало.
        foreach ($plan->getAbsorbed() as $key => $into) {
            $point = $positions[$into] ?? null;

            while ($point === null && isset($plan->getAbsorbed()[$into])) {
                $into = $plan->getAbsorbed()[$into];
                $point = $positions[$into] ?? null;
            }

            if ($point !== null) {
                $positions[$key] ??= $point;
            }
        }

        $edges = [];
        $ties = $plan->getTies();

        foreach ($pieces as $id => $vertexes) {
            $cutout = $plan->getCutout($id);
            $born = $plan->getBirth($id) <= $number;

            foreach ($cutout->piece->edges as [$vertexA, $vertexB]) {
                $key = $cutout->edges[Scene::edgeName($vertexA, $vertexB)];

                // Связка появляется вместе со склейкой, которая её и добавила:
                // раньше её в графе просто нет. А ребро, которое уже рисует
                // живой кусок, ему и принадлежит: грань, которую только
                // склеят, иначе растянула бы его через весь стол.
                if (! isset($vertexes[$vertexA], $vertexes[$vertexB])
                    || isset($edges[$key])
                    || (isset($ties[$key]) && ! $born)
                ) {
                    continue;
                }

                // Кусок, которого ещё нет, ждёт поверх живых. Если его концы
                // ждут на разных кусках, ребро между ними растянулось бы через
                // весь стол, поэтому оно сжимается в точку и прячется под
                // своим шаром: из кадра не пропало, а показывать его нечего.
                $split = ! $born && ($waiting[$id][$vertexA] ?? null) !== ($waiting[$id][$vertexB] ?? null);
                $edges[$key] = [$vertexes[$vertexA], $split ? $vertexes[$vertexA] : $vertexes[$vertexB]];
            }
        }

        return new Places(
            positions: $this->getLifted($positions, $pieces, $hosts, $plan, $state, $tables, $locked, $aside, $visited),
            edges: $edges,
            pieces: $pieces,
            hosts: $hosts,
        );
    }

    /**
     * На каких столах побывал каждый шар.
     *
     * Считается один раз на весь рассказ. Раньше стол запертого обхода искали
     * перебором: на каждый кадр — по всем кускам, по всем их шарам, да ещё
     * `array_intersect`. Выходил четвёртый порядок от размера графа: на трёх
     * кусках по семь колёс кадры считались две минуты, из них девяносто
     * процентов в этом переборе.
     *
     * @return array<int, int[]>
     */
    private function getVertexTables(CutPlan $plan): array
    {
        $result = [];

        foreach ($plan->getCutouts() as $id => $cutout) {
            $table = $plan->getTable($id);

            foreach ($cutout->piece->vertexes as $vertex) {
                $result[$vertex][$table] = $table;
            }
        }

        return $result;
    }

    /**
     * Запертый обход уходит на средний круг, вынесенное — на внешний.
     *
     * Без этого запирание и заливка сливаются в кашу: непонятно, про что
     * спрашивают и что именно ответила краска. А стоит вынуть обход из куска
     * и положить отдельно, и видно, что заливка отвечает про него: вот это
     * на нём висит, и вот это придётся вынести наружу.
     *
     * Где именно лежат эти круги, знает стол; здесь решается только, кто
     * на каком из них оказался.
     *
     * @param array<string, Point2D> $positions
     * @param array<int, array<int, string>> $pieces
     * @param array<int, int> $hosts
     * @param array{live: int[], places: array<int, int>, acted: int[], touched: int[], half: array<string, true>} $state
     * @param array<int, Table> $tables
     * @param array<int, int[]> $locked
     * @param array<int, true> $aside
     * @param array<int, int[]> $where шар => столы, на которых он побывал
     *
     * @return array<string, Point2D>
     */
    private function getLifted(array $positions, array $pieces, array $hosts, CutPlan $plan, array $state, array $tables, array $locked, array $aside, array $where): array
    {
        if ($locked === []) {
            return $positions;
        }

        // У каждого стола своя раскладка: по тому обходу, который на нём
        // запирали последним. Стол ищется по всему плану, а не по тому, что
        // сейчас на нём лежит: после разреза запертого обхода на столе уже
        // нет, и стол разом вернулся бы с кругов по домам.
        $walks = [];

        foreach ($locked as $walk) {
            foreach ($walk as $vertex) {
                foreach ($where[$vertex] ?? [] as $table) {
                    $walks[$table] = $walk;
                }
            }
        }
        // Раскладывается по кругам только тот кусок, про который спрашивают.
        // Остальные столы к этому вопросу отношения не имеют и стоят как
        // стояли: иначе на каждом запирании перетряхивается вся картинка.
        // Спрашивают про стол, а не про кусок: после разреза кусок заменяется
        // остатком, и если привязываться к куску, остаток тут же прыгнет
        // обратно по домам — а на кадре разреза должно ехать только
        // отрезанное.

        // Копии, ещё не отрезанные, лежат поверх своих хозяев — значит,
        // и переезжать должны вместе с ними. Иначе оригинал уедет в середину,
        // копия останется на кольце, и между ними протянется ребро через
        // весь стол.
        foreach ($pieces as $id => $vertexes) {
            $host = $hosts[$id] ?? $id;

            $walk = $walks[$plan->getTable($host)] ?? null;
            $table = $tables[$plan->getTable($host)] ?? null;

            if ($walk === null || $table === null
                || ($state['places'][$host] ?? CutPlan::HOME) !== CutPlan::HOME
            ) {
                continue;
            }

            $order = array_flip(array_values(array_unique($walk)));
            $count = max(count($order), 1);

            foreach ($vertexes as $vertex => $key) {
                if (! isset($positions[$key])) {
                    continue;
                }

                if (isset($order[$vertex])) {
                    $positions[$key] = $table->locked($order[$vertex], $count);

                    continue;
                }

                // Остальные остаются где лежали: их никто не трогает, пока
                // не решат, что они снаружи. Тогда — и только тогда — они
                // уезжают наружу.
                if (isset($aside[$vertex])) {
                    $positions[$key] = $this->place->getOnRing($positions[$key], $table->center, $table->around());
                }
            }
        }

        return $positions;
    }

    /**
     * Кусок у себя дома: ровным кругом на середине своего стола.
     *
     * Раньше остаток раскладывался по кругу заново после каждого разреза,
     * чтобы не оставалось дыр. Но дыра — это как раз то, что надо показать:
     * на её месте только что было поле, его и вырезали. А перекладка стоила
     * дорого: на каждом разрезе уезжали четыре шара и шевелились сорок, и
     * разрез тонул в этой ряби — «отрезали одно поле» читалось как «поехало
     * всё». Едет только то, что режут; остальное стоит.
     *
     * Круг считается по тому, что в куске осталось, поэтому оставшиеся
     * расходятся равномерно. Порядок берётся из клубка, чтобы вершины
     * не перемешались, а поворот — из стола: он посчитан раз по всем его
     * вершинам, иначе весь остаток проворачивался бы после каждого разреза.
     *
     * @param Point2D[] $tangled
     *
     * @return Point2D[]
     */
    private function getHomePoints(Piece $piece, array $tangled, ?Table $table): array
    {
        if ($table === null) {
            return $this->place->getPoints($piece->vertexes, $tangled);
        }

        return $this->place->getCircle(
            array_keys($this->place->getAngles($piece->vertexes, $tangled)),
            $table->center,
            $table->radius,
            $table->turn,
        );
    }

    /**
     * Переносит укладку на стол своего куска.
     *
     * Вершина числится за всеми столами, на которых побывала, — и за общим,
     * с которого всё начиналось, тоже. Считается самый поздний: столы заводят
     * по мере того, как граф распадается, поэтому у куска стол новее, чем
     * у целого графа. Если взять общий, оба куска уедут в одно место, а их
     * поля останутся лежать вокруг чужих столов.
     *
     * @param Point2D[] $frame
     * @param array<int, Table> $tables
     *
     * @return Point2D[]
     */
    private function getShifted(array $frame, CutPlan $plan, array $tables): array
    {
        $hosts = [];
        ksort($tables);

        foreach ($tables as $table => $item) {
            foreach ($plan->getTableVertexes($table) as $vertex) {
                $hosts[$vertex] = $item;
            }
        }

        $result = [];

        foreach ($frame as $vertex => $point) {
            $table = $hosts[$vertex] ?? null;
            $result[$vertex] = $table === null ? $point : $table->shifted($point);
        }

        return $result;
    }

    /**
     * Столы: у каждого несвязного куска свой.
     *
     * Сначала меряются все — размер стола зависит только от того, что на нём
     * будет лежать. Потом решается, где какой стоит: это уже зависит от всех
     * размеров сразу, потому что столы раскладываются по кольцу вокруг
     * центрального и радиус кольца подбирается по ним. И только потом столы
     * ставятся на места.
     *
     * @param Point2D[] $tangled
     * @param Point2D[][] $layouts укладки: собранная, потом расслабление
     *
     * @return array<int, Table>
     */
    private function getTables(CutPlan $plan, array $tangled, array $layouts, float $spacing): array
    {
        $sizes = [];

        foreach ($plan->getTables() as $table) {
            $sizes[$table] = $this->getTableSize($plan, $table, $tangled, $layouts, $spacing);
        }

        // Стол, с которого всё началось, стоит там же, где лежал клубок.
        // Если граф распался на несвязные куски, этот стол пустеет — каждый
        // кусок уезжает на свой, и вокруг центрального встают уже они.
        $center = $this->place->getCenter($tangled);
        $centers = count($sizes) > 1
            ? $this->getRingCenters(array_diff_key($sizes, [self::WHOLE_TABLE => true]), $center, $spacing)
            : $this->getRowCenters($sizes, $center, $spacing);
        $centers[self::WHOLE_TABLE] ??= $center;
        $result = [];

        foreach ($centers as $table => $point) {
            $result[$table] = $this->getTable($plan, $table, $sizes[$table], $tangled, $point);
        }

        return $result;
    }

    /**
     * Сколько места надо одному столу.
     *
     * @param Point2D[] $tangled
     * @param Point2D[][] $layouts
     */
    private function getTableSize(CutPlan $plan, int $table, array $tangled, array $layouts, float $spacing): TableSize
    {
        $vertexes = $plan->getTableVertexes($table);
        $final = $layouts[count($layouts) - 1] ?? [];
        $built = $this->place->getCenter($this->place->getPoints($vertexes, $final));
        // Дома вершины стоят по кругу — ровно так, чтобы держать то же
        // расстояние, что и в исходном клубке.
        $home = $this->place->getPieceRadius(count($vertexes), $spacing);
        // У каждого места свой размер — по самому большому куску из тех, кто
        // на нём побывает: место занимает целая цепочка, и ветвь на нём шире
        // вырезанного из неё поля.
        $radii = [];

        foreach ($plan->getSlotted($table) as $id => $cutout) {
            $place = $plan->getPlace($id);
            $radii[$place] = max(
                $radii[$place] ?? .0,
                $this->place->getPieceRadius(count($cutout->piece->vertexes), $spacing),
            );
        }

        ksort($radii);
        // Круг мест должен обходить и домашний круг, и собранную укладку.
        $outside = $home;

        foreach ($layouts as $layout) {
            $outside = max($outside, $this->place->getRadius($built, $this->place->getPoints($vertexes, $layout)));
        }

        $gap = $spacing * self::RING_GAP;
        $ring = $this->place->getRing($radii, $outside, $gap);

        return new TableSize(
            built: $built,
            tangled: $this->place->getCenter($this->place->getPoints($vertexes, $tangled)),
            // Поворот домашнего круга считается один раз по всем вершинам
            // стола: иначе остаток проворачивается после каждого разреза.
            turn: $this->place->getTurn($this->place->getAngles($vertexes, $tangled)),
            radius: $home,
            ring: $ring,
            angles: $this->place->getPlaces($radii, $ring, $gap),
            extent: $radii === [] ? $outside : max($outside, $ring + max($radii)),
        );
    }

    /**
     * Один стол на своём месте.
     *
     * @param Point2D[] $tangled
     */
    private function getTable(CutPlan $plan, int $table, TableSize $size, array $tangled, Point2D $point): Table
    {
        $vertexes = $plan->getTableVertexes($table);
        $slots = [];

        foreach ($size->angles as $place => $angle) {
            $slots[$place] = $this->place->getOnAngle($point, $size->ring, $angle);
        }

        return $size->at(
            $point,
            $this->place->getCircle(
                array_keys($this->place->getAngles($vertexes, $tangled)),
                $point,
                $size->radius,
                $size->turn,
            ),
            $slots,
        );
    }

    /**
     * Где стоят столы, когда их несколько: вокруг центрального.
     *
     * Так же, как вокруг ветви раскладываются вырезанные из неё поля: радиус
     * подбирается так, чтобы все поместились, а угол берётся из клубка, чтобы
     * по дороге куски не прошли друг сквозь друга. Центральный — тот, что
     * остался последним: из него ничего не увозили, он и лежит там же, где
     * лежал клубок.
     *
     * @param array<int, TableSize> $sizes
     *
     * @return array<int, Point2D>
     */
    private function getRingCenters(array $sizes, Point2D $center, float $spacing): array
    {
        $middle = max(array_keys($sizes));
        $around = array_diff_key($sizes, [$middle => true]);
        $radii = [];
        $angles = [];

        foreach ($around as $table => $size) {
            $radii[$table] = $size->extent;
            // Угол по клубку: кусок уезжает туда, где он и лежал.
            $angles[$table] = atan2($size->tangled->y - $center->y, $size->tangled->x - $center->x);
        }

        // Между столами зазор шире, чем между полями на одном столе: столы
        // живут дольше и на них ещё вырастут укладки.
        $gap = $spacing * self::RING_GAP * 2;
        $ring = $this->place->getRing($radii, $sizes[$middle]->extent + $gap, $gap);
        asort($angles);
        $places = array_values($this->place->getPlaces(array_intersect_key($radii, $angles), $ring, $gap));
        $result = [$middle => $center];
        $number = 0;

        foreach (array_keys($angles) as $table) {
            $result[$table] = $this->place->getOnAngle($center, $ring, $places[$number] ?? .0);
            $number++;
        }

        return $result;
    }

    /**
     * Где стоят столы, когда он один: там же, где лежал клубок.
     *
     * Ряд остался с тех пор, когда несвязные куски разъезжались в строку.
     * Порядок берётся из клубка, а не из готовой укладки: куски разъезжаются
     * по столам прямо из клубка, и если стол левого куска окажется справа,
     * они поменяются местами и на ходу пройдут друг сквозь друга.
     *
     * @param array<int, TableSize> $sizes
     *
     * @return array<int, Point2D>
     */
    private function getRowCenters(array $sizes, Point2D $center, float $spacing): array
    {
        uasort($sizes, static fn (TableSize $a, TableSize $b): int => $a->tangled->x <=> $b->tangled->x);
        $width = .0;

        foreach ($sizes as $size) {
            $width += $size->extent * 2 + $spacing;
        }

        $offset = $center->x - ($width - $spacing) / 2;
        $result = [];

        foreach ($sizes as $table => $size) {
            $result[$table] = new Point2D($offset + $size->extent, $center->y + $size->extent);
            $offset += $size->extent * 2 + $spacing;
        }

        return $result;
    }

    /**
     * Заливка: цвет расползается по связям, куски пока целы.
     *
     * Краска переползает по ребру от закрашенной вершины к следующей и в конце
     * перехода доходит до неё — тогда-то вершина и становится закрашенной.
     * Иначе видно только, что точки меняют цвет, а кто кого закрасил — нет.
     * Закрашенным остаётся и само ребро: по нему краска и прошла.
     *
     * @param Places $places
     * @param array<string, int> $groups
     * @param array<string, true> $ties
     */
    private function getPaintedScene(Places $places, Paint $action, array $groups, array $ties = []): Scene
    {
        $painted = $action->groups;

        foreach ($painted as $vertex => $group) {
            foreach ($this->getVertexKeys($places, $vertex) as $key) {
                $groups[$key] = $group;
            }
        }

        foreach ($places->edges as $key => [$from, $to]) {
            $group = $painted[Scene::vertexOf($from)] ?? null;

            if ($group !== null && $group === ($painted[Scene::vertexOf($to)] ?? null)) {
                $groups[$key] = $group;
            }
        }

        $flows = [];

        foreach ($action->flows as $target => $source) {
            $edge = $this->getFlowEdge($places, $source, $target);

            if ($edge !== null) {
                $flows[$edge[0]] = [$edge[1], $painted[$target] ?? 0];
            }
        }

        return $this->getScene($places, StageKind::Fill, $groups, [], [], self::WAVE_WEIGHT, $flows, $ties);
    }

    /**
     * Ребро между двумя вершинами и тот его конец, с которого пришла краска.
     *
     * @param Places $places
     *
     * @return ?array{string, string} ребро и вершина, от которой красят
     */
    private function getFlowEdge(Places $places, int $source, int $target): ?array
    {
        foreach ($places->edges as $key => [$from, $to]) {
            $one = Scene::vertexOf($from);
            $two = Scene::vertexOf($to);

            if ($one === $source && $two === $target) {
                return [$key, $from];
            }

            if ($one === $target && $two === $source) {
                return [$key, $to];
            }
        }

        return null;
    }

    /**
     * Отрезанные поля лежат раскрашенными: каждое своим цветом, по месту
     * на столе — так соседние поля различимы, и видно, сколько их получилось.
     *
     * Красится поле не мгновенно: цвет приходит к нему заливкой, по связям,
     * ровно так же, как красились куски графа в начале. Заливка в рассказе
     * одна — меняется только то, что заливают.
     *
     * @param Places $places
     *
     * @return array<string, int>
     */
    private function getFieldGroups(CutPlan $plan, int $number, Places $places, int $shift): array
    {
        $live = array_intersect_key($places->pieces, array_flip($plan->getState($number)['live']));
        $result = [];

        foreach ($live as $id => $vertexes) {
            $place = $plan->getState($number)['places'][$id] ?? CutPlan::HOME;

            if ($place === CutPlan::HOME) {
                continue;
            }

            // Цвета полей идут после цветов заливки, иначе поле получит тот же
            // цвет, каким закрашен кусок, и разрез сольётся с фоном.
            $place += $shift;

            foreach ($vertexes as $key) {
                $result[$key] = $place;
            }

            foreach ($plan->getCutout($id)->piece->edges as [$vertexA, $vertexB]) {
                if (isset($vertexes[$vertexA], $vertexes[$vertexB])) {
                    $result[$plan->getCutout($id)->edges[Scene::edgeName($vertexA, $vertexB)]] = $place;
                }
            }
        }

        return $result;
    }

    /**
     * Краска первой заливки — той, что искала куски графа.
     *
     * Цвет с куска не сходит: зелёное значит «весь граф оказался одним
     * куском», и оно остаётся зелёным, пока кусок цел. Иначе заливка выходит
     * бессмысленной: покрасили, а на первом же разрезе краска пропала.
     *
     * @param Places $places
     * @param array<int, int> $painted
     *
     * @return array<string, int>
     */
    private function getFillGroups(Places $places, array $painted): array
    {
        if ($painted === []) {
            return [];
        }

        $result = [];

        foreach ($places->pieces as $vertexes) {
            foreach ($vertexes as $vertex => $key) {
                if (isset($painted[$vertex])) {
                    $result[$key] = $painted[$vertex];
                }
            }
        }

        foreach ($places->edges as $key => [$from, $to]) {
            $group = $painted[Scene::vertexOf($from)] ?? null;

            if ($group !== null && $group === ($painted[Scene::vertexOf($to)] ?? null)) {
                $result[$key] = $group;
            }
        }

        return $result;
    }

    /**
     * Выделение: отмечаем то, с чем сейчас будем работать.
     *
     * @param Places $places
     * @param array<string, true> $marked
     * @param array<string, int> $groups
     * @param array<string, true> $ties
     *
     * @return Scene[]
     */
    private function getMarkedScenes(Places $places, array $marked, StageKind $kind, array $groups, array $ties = []): array
    {
        // Кадр выделения идёт дважды подряд. Между двумя соседними кадрами
        // картинка едет, поэтому одного кадра мало: кольцо зажглось бы ровно
        // в тот момент, когда кусок уже поехал, и «выделил, потом сделал»
        // превратилось бы в «выделил и сделал разом». Первый кадр — короткая
        // пауза после прошлого действия, второй держит кольцо неподвижно,
        // и только потом начинается движение.
        return [
            $this->getScene($places, $kind, $groups, $marked, [], self::BEAT_WEIGHT, [], $ties),
            $this->getScene($places, $kind, $groups, $marked, [], self::MARK_WEIGHT, [], $ties),
        ];
    }

    /**
     * Надрезанные рёбра: те, которые уже отошли одному полю и ждут второго
     * разреза. Ребро всегда режут ровно дважды, и по цвету видно, сколько
     * работы с ним осталось.
     *
     * @param Places $places
     *
     * @return array<string, int>
     */
    private function getHalfGroups(CutPlan $plan, int $number, Places $places): array
    {
        $result = [];

        foreach (array_keys($plan->getState($number)['half']) as $key) {
            if (isset($places->edges[$key])) {
                $result[$key] = self::HALF_GROUP;
            }
        }

        return $result;
    }

    /**
     * @param Places $places
     * @param int[] $vertexes
     *
     * @return array<string, true>
     */
    private function getKeysOf(Places $places, array $vertexes): array
    {
        $result = [];

        foreach ($vertexes as $vertex) {
            foreach ($this->getVertexKeys($places, $vertex) as $key) {
                $result[$key] = true;
            }
        }

        return $result;
    }

    /**
     * Сборка: очередной кусок уезжает на своё место в укладке целиком,
     * остальные ждут на столе приглушёнными.
     *
     * @param Places $places
     * @param int[] $built куски, которые уже уложены
     * @param Point2D[] $building
     * @param array<string, int> $groups
     */
    private function getBuildScene(CutPlan $plan, Places $places, StageKind $kind, array $built, array $building, array $groups): Scene
    {
        $positions = $places->positions;
        $faded = [];
        $ready = array_flip($built);

        foreach ($places->pieces as $id => $vertexes) {
            $built = isset($ready[$places->hosts[$id] ?? $id]);

            foreach ($vertexes as $vertex => $key) {
                if ($built && isset($building[$vertex])) {
                    $positions[$key] = $building[$vertex];
                    // Поле, вернувшееся в укладку, цвет теряет: оно снова
                    // часть графа, а не отрезанный кусок на столе.
                    unset($groups[$key]);

                    continue;
                }

                $faded[$key] = true;
            }

            if ($built) {
                foreach ($plan->getCutout($id)->piece->edges as [$vertexA, $vertexB]) {
                    unset($groups[$plan->getCutout($id)->edges[Scene::edgeName($vertexA, $vertexB)]]);
                }
            }
        }

        $edges = [];

        foreach ($places->edges as $key => [$from, $to]) {
            $edges[$key] = [$positions[$from], $positions[$to]];

            if (isset($faded[$from]) || isset($faded[$to])) {
                $faded[$key] = true;
            }
        }

        return new Scene(
            vertexes: $positions,
            edges: $edges,
            groups: $groups,
            faded: $faded,
            weight: self::ACT_WEIGHT,
            kind: $kind,
            ties: $plan->getTies(),
        );
    }

    /**
     * @param Places $places
     *
     * @return string[]
     */
    private function getVertexKeys(Places $places, int $vertex): array
    {
        $result = [];

        foreach ($places->pieces as $piece) {
            if (isset($piece[$vertex])) {
                $result[] = $piece[$vertex];
            }
        }

        return $result;
    }

    /**
     * @param Places $places
     * @param array<string, int> $groups
     * @param array<string, true> $marked
     * @param array<string, true> $faded
     * @param array<string, array{string, int}> $flows
     * @param array<string, true> $ties
     */
    private function getScene(
        Places $places,
        StageKind $kind,
        array $groups,
        array $marked,
        array $faded,
        float $weight,
        array $flows = [],
        array $ties = [],
    ): Scene {
        $edges = [];

        foreach ($places->edges as $key => [$from, $to]) {
            $edges[$key] = [$places->positions[$from], $places->positions[$to]];
        }

        return new Scene(
            vertexes: $places->positions,
            edges: $edges,
            groups: $groups,
            highlight: $marked,
            faded: $faded,
            weight: $weight,
            kind: $kind,
            flows: $flows,
            ties: $ties,
        );
    }

    /**
     * Кадры расслабления: промежуточные состояния остаются только там,
     * без чего рисунок по дороге пересёкся бы.
     *
     * Копии к этому времени уже съехались на свои вершины, поэтому здесь они
     * просто едут вместе с ними — и ничто не исчезает.
     *
     * @param Point2D[][] $frames
     * @param array<int, array{int, int}> $edges
     *
     * @return Scene[]
     */
    private function getMovingScenes(CutPlan $plan, array $frames, array $edges, int $from, int $to): array
    {
        $slice = array_slice($frames, $from, $to - $from + 1);

        if (count($slice) > $this->targetFrames) {
            $slice = $this->motion->thin($slice, $edges, $this->targetFrames);
        }

        $count = count($slice);
        $weight = self::MOVING_WEIGHT / max($count - 1, 1);
        $result = [];

        foreach ($slice as $position => $frame) {
            $places = $this->getPlaces($plan, $plan->getLastState(), $frame, [], 1.0);
            $result[] = $this->getScene(
                $this->getMergedPlaces($places, $frame),
                StageKind::Relax,
                [],
                [],
                [],
                // Первый кадр — та же собранная укладка, что и в конце сборки:
                // держать её ещё раз значит заморозить картинку перед самым
                // интересным.
                $position === 0 ? self::BEAT_WEIGHT : $weight,
                [],
                $plan->getTies(),
            );
        }

        return $result;
    }

    /**
     * Все экземпляры на местах своих вершин: разрезанное собрано обратно.
     *
     * @param Point2D[] $frame
     */
    private function getMergedPlaces(Places $places, array $frame): Places
    {
        $positions = $places->positions;

        foreach ($places->pieces as $vertexes) {
            foreach ($vertexes as $vertex => $key) {
                if (isset($frame[$vertex])) {
                    $positions[$key] = $frame[$vertex];
                }
            }
        }

        return new Places($positions, $places->edges, $places->pieces, $places->hosts);
    }

    /**
     * Насколько далеко друг от друга стоят вершины: по нему считаются
     * размеры разъехавшихся кусков.
     *
     * @param Point2D[] $points
     */
    private function getSpacing(array $points): float
    {
        $points = array_values($points);
        $count = count($points);

        if ($count < 2) {
            return 1.0;
        }

        $result = INF;

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $result = min($result, sqrt(($points[$i]->x - $points[$j]->x) ** 2 + ($points[$i]->y - $points[$j]->y) ** 2));
            }
        }

        return $result === INF ? 1.0 : $result;
    }
}
