<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Aggregates;

use EugeneErg\Graphs\ValueObjects\Instruction\Cut;
use EugeneErg\Graphs\ValueObjects\Scene;
use LogicException;

/**
 * Доска отрисовки: какими экземплярами что лежит на каждой арене.
 *
 * Вынимаемое переезжает на новую арену тем же экземпляром — это тот же шар,
 * его просто унесли. Раздваиваемое получает новый экземпляр для новой арены,
 * а старый остаётся у остатка. Больше доска ничего не знает: ни где арены
 * лежат, ни когда что показывать.
 *
 * Ребро помнит не только, какие вершины соединяет, но и какие их
 * экземпляры: при склейке у точки сочленения два угла, и её рёбра
 * расходятся по разным копиям.
 *
 * Каждый новый экземпляр помнит, от какого он раздвоился: до своего
 * рождения он лежит поверх него — так раздвоение и видно как расхождение,
 * а не как появление из ниоткуда.
 */
final class Board
{
    /** Арена исходного графа. */
    public const string ROOT = '';

    /** @var array<string, array<int, string>> арена => вершина => экземпляр */
    private array $vertexes = [];

    /** @var array<string, array<string, array{string, string, string}>> арена => ребро => [экземпляр, экземпляры концов] */
    private array $edges = [];

    /** @var array<int, int> вершина => сколько у неё экземпляров */
    private array $copies = [];

    /** @var array<string, int> ребро => сколько у него экземпляров */
    private array $edgeCopies = [];

    /** @var array<string, string> экземпляр => от какого раздвоился */
    private array $origins = [];

    /** @var array<string, string> экземпляр ребра => от какого раздвоился */
    private array $edgeOrigins = [];

    /** @var array<string, array{string, string}> новое ребро => концы, с которыми родилось */
    private array $edgeEnds = [];

    /** @var array<string, true> связки: рёбра, которых в графе нет */
    private array $ties = [];

    /**
     * @param int[] $vertexes
     * @param array<int, array{int, int}> $edges
     */
    public function __construct(array $vertexes, array $edges)
    {
        foreach ($vertexes as $vertex) {
            $this->vertexes[self::ROOT][$vertex] = Scene::vertexKey($vertex);
            $this->copies[$vertex] = 1;
        }

        foreach ($edges as [$vertexA, $vertexB]) {
            $name = Scene::edgeName($vertexA, $vertexB);
            $this->edges[self::ROOT][$name] = [
                Scene::edgeKey($vertexA, $vertexB),
                Scene::vertexKey($vertexA),
                Scene::vertexKey($vertexB),
            ];
            $this->edgeCopies[$name] = 1;
        }
    }

    /**
     * Отрезать с арены: вынимаемое переезжает, раздваиваемое копируется.
     */
    public function cut(string $from, Cut $cut): void
    {
        if (! isset($this->vertexes[$from])) {
            throw new LogicException(sprintf('Режут с арены «%s», которой нет.', $from));
        }

        $target = $cut->arena;
        $this->vertexes[$target] = [];
        $this->edges[$target] = [];

        foreach ($cut->vertexes as $vertex) {
            $this->vertexes[$target][$vertex] = $this->takeVertex($from, $vertex, $cut->describe());
            unset($this->vertexes[$from][$vertex]);
        }

        foreach ($cut->twinVertexes as $vertex) {
            $this->vertexes[$target][$vertex] = $this->twinVertex($this->takeVertex($from, $vertex, $cut->describe()), $vertex);
        }

        foreach ($cut->edges as [$vertexA, $vertexB]) {
            $name = Scene::edgeName($vertexA, $vertexB);
            $key = $this->takeEdge($from, $name, $cut->describe())[0];
            $this->edges[$target][$name] = [$key, $this->vertexes[$target][$vertexA], $this->vertexes[$target][$vertexB]];
            unset($this->edges[$from][$name]);
        }

        foreach ($cut->twinEdges as [$vertexA, $vertexB]) {
            $name = Scene::edgeName($vertexA, $vertexB);
            $origin = $this->takeEdge($from, $name, $cut->describe())[0];
            $this->edges[$target][$name] = [
                $this->twinEdge($origin, $vertexA, $vertexB),
                $this->vertexes[$target][$vertexA],
                $this->vertexes[$target][$vertexB],
            ];
        }
    }

    /**
     * Остаток и есть последняя часть: арена просто получает своё имя.
     */
    public function rename(string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        $this->vertexes[$to] = $this->vertexes[$from];
        $this->edges[$to] = $this->edges[$from];

        unset($this->vertexes[$from], $this->edges[$from]);
    }

    /**
     * Поле-цикл раздваивается целиком: копия каждой вершины и каждого ребра.
     */
    public function twin(string $from, string $to): void
    {
        $this->vertexes[$to] = [];
        $this->edges[$to] = [];

        foreach ($this->vertexes[$from] ?? [] as $vertex => $key) {
            $this->vertexes[$to][$vertex] = $this->twinVertex($key, $vertex);
        }

        foreach ($this->edges[$from] ?? [] as $name => [$key, $keyA, $keyB]) {
            [$vertexA, $vertexB] = [Scene::vertexOf($keyA), Scene::vertexOf($keyB)];
            $this->edges[$to][$name] = [
                $this->twinEdge($key, $vertexA, $vertexB),
                $this->vertexes[$to][$vertexA],
                $this->vertexes[$to][$vertexB],
            ];
        }
    }

    /**
     * Склеенные поля раскрываются в круг. Точка склейки в его обходе
     * встречается дважды: первый угол достаётся копии первого поля, второй —
     * копии второго, и каждое ребро у точки переходит к той копии, у угла
     * которой оно лежит. Если сторон круга больше, чем рёбер, — у моста его
     * единственное ребро становится двумя сторонами, — ребро раздваивается.
     *
     * @param int[] $walk
     */
    public function open(int $vertex, array $walk, string $first, string $second): void
    {
        $corners = [$this->vertexes[$first][$vertex], $this->vertexes[$second][$vertex]];
        $instances = $this->getInstances([$first, $second]);
        $pool = $this->getEdgePool([$first, $second]);
        $count = count($walk);
        $seen = 0;
        $keys = [];

        foreach ($walk as $position => $current) {
            $keys[$position] = $current === $vertex ? $corners[min($seen++, 1)] : $instances[$current];
        }

        foreach ($walk as $position => $current) {
            $next = ($position + 1) % $count;
            $name = Scene::edgeName($current, $walk[$next]);
            $ends = [$keys[$position], $keys[$next]];
            $arena = $current === $vertex || $walk[$next] === $vertex
                ? ($ends[0] === $corners[1] || $ends[1] === $corners[1] ? $second : $first)
                : $first;
            $found = $this->pickEdge($pool, $name);

            if ($found !== null) {
                [$at, $key] = $found;
                $this->edges[$at][$name] = [$key, ...$ends];

                continue;
            }

            if (isset($this->edges[$arena][$name])) {
                $arena = $arena === $first ? $second : $first;
            }

            $origin = $this->findEdge([$first, $second], $name)
                ?? throw new LogicException(sprintf('У стороны %s круга нет ребра.', $name));
            $this->edges[$arena][$name] = [
                $this->twinEdge($origin, Scene::vertexOf($ends[0]), Scene::vertexOf($ends[1])),
                ...$ends,
            ];
        }
    }

    /**
     * Новая связь — единственное, что в графе прибавляется.
     */
    public function tie(int $vertexA, int $vertexB, string $first, string $second): void
    {
        $instances = $this->getInstances([$first, $second]);
        $name = Scene::edgeName($vertexA, $vertexB);
        $copy = $this->edgeCopies[$name] ?? 0;
        $this->edgeCopies[$name] = $copy + 1;
        $key = Scene::edgeKey($vertexA, $vertexB, $copy);
        $this->edges[$first][$name] = [$key, $instances[$vertexA], $instances[$vertexB]];
        $this->edgeEnds[$key] = [$instances[$vertexA], $instances[$vertexA]];
        $this->ties[$key] = true;
    }

    /**
     * Круг разрезается по связке. Первое поле остаётся на первой арене со
     * своей копией точки склейки, второе — на второй со своей. Концы связки
     * и сама связка раздваиваются: они есть в обоих полях.
     *
     * @param int[] $firstWalk
     * @param int[] $secondWalk
     */
    public function split(int $vertex, array $firstWalk, string $first, array $secondWalk, string $second): void
    {
        $corners = [$first => $this->vertexes[$first][$vertex], $second => $this->vertexes[$second][$vertex]];
        $instances = $this->getInstances([$first, $second]);
        $pool = $this->getEdgePool([$first, $second]);
        $taken = [];
        $result = [];

        foreach ([$first => $firstWalk, $second => $secondWalk] as $arena => $walk) {
            $keys = [];

            foreach (array_unique($walk) as $current) {
                if ($current === $vertex) {
                    $keys[$current] = $corners[$arena];
                } elseif (isset($taken[$current])) {
                    $keys[$current] = $this->twinVertex($instances[$current], $current);
                } else {
                    $keys[$current] = $instances[$current];
                    $taken[$current] = true;
                }
            }

            $edges = [];
            $count = count($walk);

            foreach ($walk as $position => $current) {
                $next = $walk[($position + 1) % $count];
                $name = Scene::edgeName($current, $next);
                $found = $this->pickEdge($pool, $name);
                $key = $found !== null
                    ? $found[1]
                    : $this->twinEdge(
                        $this->findEdge([$first, $second], $name) ?? throw new LogicException('Нет ребра ' . $name),
                        $current,
                        $next,
                    );
                $edges[$name] = [$key, $keys[$current], $keys[$next]];
            }

            $result[$arena] = [$keys, $edges];
        }

        foreach ($result as $arena => [$keys, $edges]) {
            $this->vertexes[$arena] = $keys;
            $this->edges[$arena] = $edges;
        }
    }

    /**
     * @return array<string, array<int, string>> арена => вершина => экземпляр
     */
    public function getVertexes(): array
    {
        return $this->vertexes;
    }

    /**
     * @return array<string, array<string, array{string, string, string}>>
     */
    public function getEdges(): array
    {
        return $this->edges;
    }

    /**
     * @return array<string, string>
     */
    public function getOrigins(): array
    {
        return $this->origins;
    }

    /**
     * @return array<string, string>
     */
    public function getEdgeOrigins(): array
    {
        return $this->edgeOrigins;
    }

    /**
     * Концы новых рёбер, с которыми они родились.
     *
     * @return array<string, array{string, string}>
     */
    public function getEdgeEnds(): array
    {
        return $this->edgeEnds;
    }

    /**
     * @return array<string, true>
     */
    public function getTies(): array
    {
        return $this->ties;
    }

    /**
     * Экземпляры вершин на этих аренах; у каждой вершины, кроме точки
     * склейки, он один.
     *
     * @param string[] $arenas
     *
     * @return array<int, string>
     */
    private function getInstances(array $arenas): array
    {
        $result = [];

        foreach ($arenas as $arena) {
            $result += $this->vertexes[$arena] ?? [];
        }

        return $result;
    }

    /**
     * Все рёбра этих арен, ещё не разобранные по сторонам.
     *
     * @param string[] $arenas
     *
     * @return array<string, array<int, array{string, string}>> ребро => [арена, экземпляр]
     */
    private function getEdgePool(array $arenas): array
    {
        $result = [];

        foreach ($arenas as $arena) {
            foreach ($this->edges[$arena] ?? [] as $name => [$key]) {
                $result[$name][] = [$arena, $key];
            }
        }

        return $result;
    }

    /**
     * @param array<string, array<int, array{string, string}>> $pool
     *
     * @return array{string, string}|null
     */
    private function pickEdge(array &$pool, string $name): ?array
    {
        if (($pool[$name] ?? []) === []) {
            return null;
        }

        return array_shift($pool[$name]);
    }

    /**
     * @param string[] $arenas
     */
    private function findEdge(array $arenas, string $name): ?string
    {
        foreach ($arenas as $arena) {
            if (isset($this->edges[$arena][$name])) {
                return $this->edges[$arena][$name][0];
            }
        }

        return null;
    }

    private function takeVertex(string $from, int $vertex, string $what): string
    {
        return $this->vertexes[$from][$vertex] ?? throw new LogicException(sprintf(
            'На арене «%s» нет вершины %d: %s',
            $from,
            $vertex,
            $what,
        ));
    }

    /**
     * @return array{string, string, string}
     */
    private function takeEdge(string $from, string $name, string $what): array
    {
        return $this->edges[$from][$name] ?? throw new LogicException(sprintf(
            'На арене «%s» нет ребра %s: %s',
            $from,
            $name,
            $what,
        ));
    }

    private function twinVertex(string $origin, int $vertex): string
    {
        $key = Scene::vertexKey($vertex, $this->copies[$vertex]++);
        $this->origins[$key] = $origin;

        return $key;
    }

    private function twinEdge(string $origin, int $vertexA, int $vertexB): string
    {
        $name = Scene::edgeName($vertexA, $vertexB);
        $copy = $this->edgeCopies[$name] ?? 0;
        $this->edgeCopies[$name] = $copy + 1;
        $key = Scene::edgeKey($vertexA, $vertexB, $copy);
        $this->edgeOrigins[$key] = $origin;

        // Копия связки — тоже связка: её тоже нет в графе.
        if (isset($this->ties[$origin])) {
            $this->ties[$key] = true;
        }

        return $key;
    }
}
