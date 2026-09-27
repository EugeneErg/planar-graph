<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Aggregates;

use EugeneErg\Graphs\ValueObjects\Cutout;
use EugeneErg\Graphs\ValueObjects\Piece;
use EugeneErg\Graphs\ValueObjects\Scene;
use EugeneErg\Graphs\ValueObjects\Script\ActionInterface;
use EugeneErg\Graphs\ValueObjects\Script\Cut;
use EugeneErg\Graphs\ValueObjects\Script\Detach;
use EugeneErg\Graphs\ValueObjects\Script\Join;
use EugeneErg\Graphs\ValueObjects\Script\Script;
use EugeneErg\Graphs\ValueObjects\Script\Tie;
use EugeneErg\Graphs\ValueObjects\Script\Together;

/**
 * План разреза: что из чего вырезано, куда легло и какими экземплярами.
 *
 * План — это исполнитель инструкции. Ему подают действия по одному
 * (`apply`), он делает ровно это одно действие и запоминает состояние. Отсюда
 * главное свойство рассказа: состояний ровно столько же, сколько действий,
 * и слить два действия в один кадр нельзя — их просто нечем слить.
 *
 * Место куска на столе и экземпляры его вершин назначаются один раз при
 * вырезании и больше не меняются: кусок никогда не переезжает из-за того, что
 * рядом отрезали другой, а экземпляр никогда не достаётся другому куску.
 * Иначе картинка превращается в паровозик, где при каждом разрезе едут все.
 */
final class CutPlan
{
    /** Кусок лежит там же, где лежал исходный граф. */
    public const int HOME = -1;

    /** @var Cutout[] */
    private array $cutouts = [];

    /** @var array<int, int> кусок => состояние, в котором он появился */
    private array $births = [];

    /** @var array<int, int> кусок => его место; CutPlan::HOME — исходное место */
    private array $places = [];

    /** @var array<int, int> кусок => стол, на котором он лежит */
    private array $tables = [];

    /** @var array<int, int> стол => сколько мест на нём уже занято */
    private array $slots = [];

    /** @var int[] номера живых кусков */
    private array $live = [];

    /** @var array<int, int> вершина => сколько её экземпляров уже заведено */
    private array $copies = [];

    /** @var array<string, int> ребро => сколько его экземпляров уже заведено */
    private array $edgeCopies = [];

    /** @var array<string, int> ребро => сколько разрезов ему ещё предстоит */
    private array $cuts;

    /** @var array<string, true> экземпляры рёбер, разрезанных один раз из двух */
    private array $half = [];

    /** @var array<string, true> экземпляры связей, добавленных склейкой */
    private array $ties = [];

    /** @var array<string, true> сами связи: у них, как у рёбер, бывают копии */
    private array $tieNames = [];

    /**
     * @var array<string, string> слившийся экземпляр => тот, в который он
     *                            слился: шар остаётся лежать поверх него
     */
    private array $absorbed = [];

    /** @var array<int, array{live: int[], places: array<int, int>, acted: int[], touched: int[], half: array<string, true>}> */
    private array $states = [];

    /** @var int[] куски, родившиеся на текущем действии */
    private array $acted = [];

    /** @var int[] куски, с которыми работает текущее действие */
    private array $touched = [];

    /**
     * @param array<string, int> $cuts сколько раз каждое ребро ещё разрежут
     */
    public function __construct(Piece $whole, array $cuts)
    {
        $this->cuts = $cuts;
        $this->live = [$this->create($whole, null, 0, self::HOME, [], [])];
        $this->snapshot();
    }

    /**
     * Проиграть всю инструкцию: действие за действием.
     *
     * @param array<string, int> $cuts
     */
    public static function of(Piece $whole, array $cuts, Script $script): self
    {
        $result = new self($whole, $cuts);

        foreach ($script->steps as $step) {
            $result->apply($step->action);
        }

        return $result;
    }

    /**
     * Сделать ровно одно действие и запомнить, что получилось.
     *
     * Состояние на действие — это и есть то правило, ради которого заведена
     * инструкция: кадров столько же, сколько действий, и склеить два действия
     * в один кадр невозможно. Действия, которые клубок не трогают (показать,
     * закрасить, уложить), состояние всё равно получают: рассказ идёт по ним
     * так же, как по разрезам.
     */
    public function apply(ActionInterface $action): void
    {
        $this->touched = [];
        $this->run($action);
        $this->snapshot();
    }

    /**
     * Сделать действие, не запоминая состояния.
     *
     * Одновременные действия (`Together`) — это по-прежнему один кадр:
     * состояние снимается один раз на всех. Одновременны только чужие друг
     * другу куски, поэтому порядок внутри ничего не решает.
     */
    private function run(ActionInterface $action): void
    {
        match (true) {
            $action instanceof Detach => $this->detach($action->part, $action->rest, $action->table),
            $action instanceof Cut => $this->cut(Piece::ofWalk($action->walk)),
            $action instanceof Join => $this->join(Piece::ofWalk($action->first), Piece::ofWalk($action->second)),
            $action instanceof Tie => $this->tie($action->edge, $action->walks),
            $action instanceof Together => $this->runAll($action->actions),
            default => null,
        };
    }

    /**
     * @param ActionInterface[] $actions
     */
    private function runAll(array $actions): void
    {
        foreach ($actions as $action) {
            $this->run($action);
        }
    }

    /**
     * Запомнить, как всё выглядит после очередного действия.
     */
    public function snapshot(): void
    {
        $this->states[] = [
            'live' => $this->live,
            'places' => $this->places,
            'acted' => $this->acted,
            'touched' => $this->touched,
            'half' => $this->half,
        ];
        $this->acted = [];
    }

    /**
     * @return array{live: int[], places: array<int, int>, acted: int[], touched: int[], half: array<string, true>}
     */
    public function getState(int $number): array
    {
        return $this->states[$number] ?? $this->states[count($this->states) - 1];
    }

    public function getLastState(): int
    {
        return count($this->states) - 1;
    }

    /**
     * Изменилось ли что-нибудь на действии, которое привело в это состояние.
     */
    public function isChanged(int $number): bool
    {
        $before = $this->getState($number - 1);
        $after = $this->getState($number);

        return $before['live'] !== $after['live'] || $before['places'] !== $after['places'];
    }

    /**
     * Куски, с которыми работало действие, приведшее в это состояние, — те,
     * какими они были до него. На них и загорается кольцо: обводят ровно то,
     * с чем сейчас будут работать, и ничего кроме.
     *
     * @return int[]
     */
    public function getTouched(int $number): array
    {
        return $this->getState($number)['touched'];
    }

    /**
     * @return Cutout[]
     */
    public function getCutouts(): array
    {
        return $this->cutouts;
    }

    public function getCutout(int $id): Cutout
    {
        return $this->cutouts[$id];
    }

    public function getBirth(int $id): int
    {
        return $this->births[$id];
    }

    public function getSlotCount(int $table): int
    {
        return $this->slots[$table] ?? 0;
    }

    public function getTable(int $id): int
    {
        return $this->tables[$id];
    }

    /**
     * Место куска на столе; CutPlan::HOME — там же, где лежал сам граф.
     */
    public function getPlace(int $id): int
    {
        return $this->places[$id];
    }

    /**
     * Столы: у каждого несвязного куска свой. Куски одного стола лежат вокруг
     * него и с чужим столом не пересекаются.
     *
     * @return int[]
     */
    public function getTables(): array
    {
        return array_values(array_unique($this->tables));
    }

    /**
     * Куски, которым досталось место на столе: по ним и меряют, какой шириной
     * раскладывать стол. Остаток лежит там же, где лежал граф, и места
     * не занимает.
     *
     * @return Cutout[]
     */
    public function getSlotted(int $table): array
    {
        $result = [];

        foreach ($this->cutouts as $id => $cutout) {
            if ($this->places[$id] !== self::HOME && $this->tables[$id] === $table) {
                $result[$id] = $cutout;
            }
        }

        return $result;
    }

    /**
     * Вершины стола: по ним считается, где стол стоит и какого он размера.
     *
     * @return int[]
     */
    public function getTableVertexes(int $table): array
    {
        $result = [];

        foreach ($this->cutouts as $id => $cutout) {
            if ($this->tables[$id] === $table) {
                foreach ($cutout->piece->vertexes as $vertex) {
                    $result[$vertex] = $vertex;
                }
            }
        }

        return array_values($result);
    }

    /**
     * Отделить от куска одну часть.
     *
     * Ровно одну: разом отпустить все нельзя — глаз не успевает за тем, как
     * полграфа одновременно разлетается в стороны, и непонятно, что именно
     * от чего отрезали. Общая вершина достаётся и части, и остатку своим
     * экземпляром, поэтому связи между ними не остаётся.
     *
     * Когда части разъезжаются по столам, свой стол получает и остаток —
     * но только если он уже сам по себе целый кусок, а не набор тех, кого
     * ещё предстоит развезти.
     *
     * @param int[] $part
     * @param int[] $rest
     */
    private function detach(array $part, array $rest, bool $table): void
    {
        $parent = $part === [] || $rest === [] ? null : $this->findByVertexes(array_merge($part, $rest));

        if ($parent === null) {
            return;
        }

        $this->touched = [$parent];
        $from = $this->cutouts[$parent];
        $piece = $this->getInduced($parent, $part);
        $left = $this->getInduced($parent, $rest);
        // Остаток держит свои экземпляры, а всё, чего в нём не осталось,
        // уезжает вместе с отрезанной частью.
        $taken = $this->getKeysOutside($from, $left);
        $cut = $this->create(
            $piece,
            $parent,
            $table ? $this->nextTable() : $this->tables[$parent],
            $table ? self::HOME : $this->nextSlot($this->tables[$parent]),
            $taken[0],
            $taken[1],
        );
        $kept = $this->getKeysInside($from, $left);
        $keep = $this->create(
            $left,
            $parent,
            $table && $this->isWhole($left) ? $this->nextTable() : $this->tables[$parent],
            $table ? self::HOME : $this->places[$parent],
            $kept[0],
            $kept[1],
        );

        $this->kill($parent);
        $this->live[] = $cut;
        $this->live[] = $keep;
        $this->acted[] = $cut;
    }

    /**
     * Цел ли кусок: одна связная штука, а не несколько, которые ещё развезут.
     */
    private function isWhole(Piece $piece): bool
    {
        $seen = [];
        $queue = array_slice($piece->vertexes, 0, 1);
        $neighbours = [];

        foreach ($piece->edges as [$vertexA, $vertexB]) {
            $neighbours[$vertexA][] = $vertexB;
            $neighbours[$vertexB][] = $vertexA;
        }

        while (($vertex = array_pop($queue)) !== null) {
            if (isset($seen[$vertex])) {
                continue;
            }

            $seen[$vertex] = true;

            foreach ($neighbours[$vertex] ?? [] as $next) {
                $queue[] = $next;
            }
        }

        return count($seen) === count($piece->vertexes);
    }

    /**
     * Разрезать кусок по замкнутому обходу.
     *
     * Ребро лежит ровно между двумя полями, поэтому из куска оно уходит только
     * после второго разреза: до тех пор у куска остаётся своя копия. Всё, чего
     * у куска после разреза не осталось, поле забирает вместе с экземплярами.
     */
    private function cut(Piece $field): void
    {
        $parent = $field->edges === [] ? null : $this->findByEdges($field);

        if ($parent === null) {
            return;
        }

        $this->touched = [$parent];

        foreach ($field->edges as [$vertexA, $vertexB]) {
            $name = Scene::edgeName($vertexA, $vertexB);
            $this->cuts[$name] = ($this->cuts[$name] ?? 1) - 1;
        }

        $from = $this->cutouts[$parent];
        $rest = Piece::ofEdges(array_values(array_filter(
            $from->piece->edges,
            fn (array $edge): bool => ($this->cuts[Scene::edgeName($edge[0], $edge[1])] ?? 0) > 0,
        )));
        $taken = $this->getKeysOutside($from, $rest);
        $table = $this->tables[$parent];
        $cut = $this->create($field, $parent, $table, $this->nextSlot($table), $taken[0], $taken[1]);

        $this->kill($parent);

        if (! $rest->isEmpty()) {
            $kept = $this->getKeysInside($from, $rest);
            $this->live[] = $this->create($rest, $parent, $table, $this->places[$parent], $kept[0], $kept[1]);
        }

        $this->live[] = $cut;
        $this->acted[] = $cut;

        // Ребро лежит между двумя полями, и разрезают его дважды. После
        // первого разреза оно помечается: видно, какие рёбра уже надрезаны,
        // а какие ещё целы.
        foreach ($this->cutouts[$cut]->edges as $key) {
            unset($this->half[$key]);
        }

        if ($rest->isEmpty()) {
            return;
        }

        foreach ($this->cutouts[count($this->cutouts) - 1]->edges as $name => $key) {
            if (($this->cuts[$name] ?? 0) === 1) {
                $this->half[$key] = true;
            }
        }
    }

    /**
     * Сдвинуть две грани в одну: общий шар снова один, а тот его экземпляр,
     * что достался второй грани, остаётся лежать поверх первого — ничто
     * не пропадает.
     */
    private function join(Piece $firstPiece, Piece $secondPiece): void
    {
        $first = $this->findByPiece($firstPiece);
        $second = $this->findByPiece($secondPiece);

        if ($first === null || $second === null || $first === $second) {
            return;
        }

        $this->touched = [$first, $second];
        $edges = array_merge($this->cutouts[$first]->piece->edges, $this->cutouts[$second]->piece->edges);
        $vertexes = [];
        $keptEdges = [];

        foreach ([$first, $second] as $source) {
            foreach ($this->cutouts[$source]->vertexes as $vertex => $key) {
                $vertexes[$vertex] ??= $key;
            }

            foreach ($this->cutouts[$source]->edges as $name => $key) {
                $keptEdges[$name] ??= $key;
            }
        }

        $id = $this->create(
            Piece::ofEdges($edges),
            $first,
            $this->tables[$first],
            $this->places[$first],
            $vertexes,
            $keptEdges,
        );

        // Экземпляр, которого в объединённом куске не осталось, ложится поверх
        // того, с которым он слился: шаров стало меньше, а из кадра ничего
        // не пропало.
        foreach ($this->cutouts[$second]->vertexes as $vertex => $key) {
            if (($vertexes[$vertex] ?? null) !== $key) {
                $this->absorbed[$key] = $vertexes[$vertex] ?? $key;
            }
        }

        $this->kill($first);
        $this->kill($second);
        $this->live[] = $id;
        $this->acted[] = $id;
    }

    /**
     * Связать два шара новой палкой: у куска прибавляется ребро, которого
     * в графе нет. Дальше по нему и режут, поэтому резать его предстоит
     * столько раз, в скольких гранях оно лежит.
     *
     * @param array{int, int} $tie
     * @param array<int, int[]> $walks грани, которые должны получиться
     */
    private function tie(array $tie, array $walks): void
    {
        $id = $this->findByVertexes($tie);

        if ($id === null || isset($this->cutouts[$id]->edges[Scene::edgeName($tie[0], $tie[1])])) {
            return;
        }

        $this->touched = [$id];
        $results = array_map(static fn (array $walk): Piece => Piece::ofWalk($walk), $walks);
        $edges = [];

        foreach ($this->cutouts[$id]->piece->edges as [$vertexA, $vertexB]) {
            $edges[Scene::edgeName($vertexA, $vertexB)] = [$vertexA, $vertexB];
        }

        $piece = Piece::ofEdges(array_merge(array_values($edges), [$tie]));
        $tied = $this->create(
            $piece,
            $id,
            $this->tables[$id],
            $this->places[$id],
            $this->cutouts[$id]->vertexes,
            $this->cutouts[$id]->edges,
        );

        $this->kill($id);
        $this->live[] = $tied;
        $this->acted[] = $tied;
        $this->tieNames[Scene::edgeName($tie[0], $tie[1])] = true;
        $this->ties[$this->cutouts[$tied]->edges[Scene::edgeName($tie[0], $tie[1])]] = true;

        // Сколько раз ещё резать каждое ребро склеенного куска: столько,
        // в скольких из получившихся граней оно лежит.
        foreach ($piece->edges as [$vertexA, $vertexB]) {
            $name = Scene::edgeName($vertexA, $vertexB);
            $this->cuts[$name] = 0;

            foreach ($results as $result) {
                foreach ($result->edges as [$oneA, $oneB]) {
                    if (Scene::edgeName($oneA, $oneB) === $name) {
                        $this->cuts[$name]++;
                    }
                }
            }
        }
    }

    /**
     * Экземпляры, слившиеся с другими при объединении: каждый лежит поверх
     * того, в который слился, и потому из кадра не пропадает.
     *
     * @return array<string, string>
     */
    public function getAbsorbed(): array
    {
        return $this->absorbed;
    }

    /**
     * Связи, добавленные склейкой: их рисуют пунктиром.
     *
     * @return array<string, true>
     */
    public function getTies(): array
    {
        return $this->ties;
    }

    /**
     * Живой кусок ровно с такими же рёбрами.
     */
    private function findByPiece(Piece $piece): ?int
    {
        $names = [];

        foreach ($piece->edges as [$vertexA, $vertexB]) {
            $names[Scene::edgeName($vertexA, $vertexB)] = true;
        }

        foreach ($this->live as $id) {
            if (array_keys($this->cutouts[$id]->edges) === array_keys($names)
                || (count($this->cutouts[$id]->edges) === count($names)
                    && array_diff_key($this->cutouts[$id]->edges, $names) === [])
            ) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Раздать места в порядке сборки.
     *
     * Куски не переставляются на глазах: очередь видна сразу, потому что
     * вырезанное сразу ложится на то место, с которого его и возьмут.
     * Перестановка уже разложенных кусков — лишнее движение, за которым
     * не уследить.
     *
     * Нумеруются места, а не куски: одно и то же место занимает целая цепочка
     * кусков — ветвь, а потом всё, что от неё остаётся после каждого разреза.
     * Если раздавать номера кускам, их выйдет больше, чем мест на столе,
     * и последним ляжет некуда — они окажутся в начале координат поверх чужих
     * полей. Место достаётся цепочке, а очередь его — по самому раннему в ней.
     *
     * Место, с которого в укладку так никто и не уедет, — временное: на нём
     * ветвь лежала, пока её резали. Такие уходят в конец кольца, чтобы
     * не разрывать очередь тех, кого и правда будут забирать.
     *
     * @param int[] $order куски в том порядке, в каком они лягут в укладку
     */
    public function orderSlots(array $order): void
    {
        $queue = array_flip(array_values($order));
        $ranks = [];

        foreach ($this->places as $id => $place) {
            if ($place === self::HOME) {
                continue;
            }

            $table = $this->tables[$id];
            $ranks[$table][$place] = min($ranks[$table][$place] ?? PHP_INT_MAX, $queue[$id] ?? PHP_INT_MAX);
        }

        $moved = [];

        foreach ($ranks as $table => $places) {
            asort($places);
            $number = 0;

            foreach (array_keys($places) as $place) {
                $moved[$table][$place] = $number;
                $number++;
            }
        }

        $places = [];

        foreach ($this->places as $id => $place) {
            if ($place !== self::HOME) {
                $places[$id] = $moved[$this->tables[$id]][$place];
            }
        }

        foreach ($places as $id => $place) {
            $this->places[$id] = $place;
        }

        foreach ($this->states as $number => $state) {
            foreach ($places as $id => $place) {
                if (isset($state['places'][$id])) {
                    $this->states[$number]['places'][$id] = $place;
                }
            }
        }
    }

    /**
     * Кусок готов, когда уложена последняя его вершина: по этому шагу он
     * и встаёт в очередь.
     *
     * @param array<int, int> $order
     */
    public function getRank(int $id, array $order): int
    {
        $result = -1;

        foreach ($this->cutouts[$id]->piece->vertexes as $vertex) {
            $result = max($result, $order[$vertex] ?? PHP_INT_MAX);
        }

        return $result;
    }

    /**
     * Живой кусок, на котором сейчас лежит ещё не отрезанный: пока разреза
     * нет, копии стоят поверх своих оригиналов.
     *
     * @param array<int, mixed> $live
     */
    public function getHost(int $id, array $live): ?int
    {
        $result = $this->cutouts[$id]->parent;

        while ($result !== null && ! isset($live[$result])) {
            $result = $this->cutouts[$result]->parent;
        }

        return $result;
    }

    /**
     * @param array<int, string> $keptVertexes вершина => ключ, доставшийся от родителя
     * @param array<string, string> $keptEdges
     */
    private function create(Piece $piece, ?int $parent, int $table, int $place, array $keptVertexes, array $keptEdges): int
    {
        $id = count($this->cutouts);
        $vertexes = [];

        foreach ($piece->vertexes as $vertex) {
            if (isset($keptVertexes[$vertex])) {
                $vertexes[$vertex] = $keptVertexes[$vertex];

                continue;
            }

            $copy = $this->copies[$vertex] ?? 0;
            $this->copies[$vertex] = $copy + 1;
            $vertexes[$vertex] = Scene::vertexKey($vertex, $copy);
        }

        $edges = [];

        foreach ($piece->edges as [$vertexA, $vertexB]) {
            $name = Scene::edgeName($vertexA, $vertexB);

            if (isset($keptEdges[$name])) {
                $edges[$name] = $keptEdges[$name];

                continue;
            }

            $copy = $this->edgeCopies[$name] ?? 0;
            $this->edgeCopies[$name] = $copy + 1;
            $edges[$name] = Scene::edgeKey($vertexA, $vertexB, $copy);

            // У связи копии такие же, как у обычного ребра, и все они связи:
            // рисуются пунктиром, потому что в графе их нет.
            if (isset($this->tieNames[$name])) {
                $this->ties[$edges[$name]] = true;
            }
        }

        $this->cutouts[$id] = new Cutout($piece, $parent, $vertexes, $edges);
        $this->births[$id] = count($this->states);
        $this->places[$id] = $place;
        $this->tables[$id] = $table;

        return $id;
    }

    private function kill(int $id): void
    {
        $this->live = array_values(array_filter($this->live, static fn (int $item): bool => $item !== $id));
    }

    private function nextSlot(int $table): int
    {
        $result = $this->slots[$table] ?? 0;
        $this->slots[$table] = $result + 1;

        return $result;
    }

    /**
     * Новый стол: столько же, сколько уже есть.
     */
    private function nextTable(): int
    {
        return count(array_unique($this->tables));
    }

    /**
     * Часть куска, попавшая в заданный набор вершин.
     *
     * @param int[] $vertexes
     */
    private function getInduced(int $parent, array $vertexes): Piece
    {
        $inside = array_flip($vertexes);
        $edges = [];

        foreach ($this->cutouts[$parent]->piece->edges as $edge) {
            if (isset($inside[$edge[0]], $inside[$edge[1]])) {
                $edges[] = $edge;
            }
        }

        return $edges === [] ? new Piece(array_values($vertexes), []) : Piece::ofEdges($edges);
    }

    /**
     * Экземпляры родителя, которых в остатке больше нет: их забирает
     * отрезанное.
     *
     * @return array{array<int, string>, array<string, string>}
     */
    private function getKeysOutside(Cutout $from, Piece $rest): array
    {
        [$inside, $insideEdges] = $this->getKeysInside($from, $rest);
        $vertexes = [];

        foreach ($from->vertexes as $vertex => $key) {
            if (! isset($inside[$vertex])) {
                $vertexes[$vertex] = $key;
            }
        }

        $edges = [];

        foreach ($from->edges as $name => $key) {
            if (! isset($insideEdges[$name])) {
                $edges[$name] = $key;
            }
        }

        return [$vertexes, $edges];
    }

    /**
     * Экземпляры родителя, которые у него остались.
     *
     * @return array{array<int, string>, array<string, string>}
     */
    private function getKeysInside(Cutout $from, Piece $rest): array
    {
        $vertexes = [];

        foreach ($rest->vertexes as $vertex) {
            if (isset($from->vertexes[$vertex])) {
                $vertexes[$vertex] = $from->vertexes[$vertex];
            }
        }

        $edges = [];

        foreach ($rest->edges as [$vertexA, $vertexB]) {
            $name = Scene::edgeName($vertexA, $vertexB);

            if (isset($from->edges[$name])) {
                $edges[$name] = $from->edges[$name];
            }
        }

        return [$vertexes, $edges];
    }

    /**
     * Живой кусок, в котором целиком лежит это поле.
     */
    private function findByEdges(Piece $piece): ?int
    {
        foreach ($this->live as $id) {
            $edges = $this->cutouts[$id]->edges;
            $inside = true;

            foreach ($piece->edges as [$vertexA, $vertexB]) {
                if (! isset($edges[Scene::edgeName($vertexA, $vertexB)])) {
                    $inside = false;

                    break;
                }
            }

            if ($inside) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Живой кусок, которому принадлежат все эти вершины.
     *
     * @param int[] $vertexes
     */
    private function findByVertexes(array $vertexes): ?int
    {
        foreach ($this->live as $id) {
            $inside = true;

            foreach ($vertexes as $vertex) {
                if (! isset($this->cutouts[$id]->vertexes[$vertex])) {
                    $inside = false;

                    break;
                }
            }

            if ($inside) {
                return $id;
            }
        }

        return null;
    }
}
