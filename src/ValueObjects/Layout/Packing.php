<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Layout;

use EugeneErg\Graphs\ValueObjects\Point2D;

/**
 * Кольца арен, подогнанные под то, что на них лежит сейчас.
 *
 * Геометрия раскладывает каждую арену вокруг её собственной середины, а
 * детей — кольцом вокруг родителя. Кольцо подгоняется под то, что на нём
 * лежит сейчас: расширяется, когда на него что-то легло, и сужается, когда
 * оттуда что-то забрали. Поэтому место заранее
 * не держится ни под что: ни под поле, которое вырежут потом, ни под
 * рисунок, который вырастет в середине.
 *
 * Дети на кольце стоят вплотную, каждый в своём секторе и в прежнем порядке
 * по кругу; пришёл новый — соседи расступаются, ушёл — смыкаются.
 */
final readonly class Packing
{
    
    /**
     * @param array<string, Point2D> $centers арена => середина, вокруг которой
     *                                        геометрия раскладывала её точки
     * @param array<string, array<string, float>> $angles арена => ребёнок =>
     *                                                    направление от родителя
     * @param float $gap зазор между соседями на кольце
     * @param array<string, string> $aliases прежнее имя арены => новое: остаток
     *                                       потом становится последней частью
     */
    public function __construct(
        public array $centers = [],
        public array $angles = [],
        public float $gap = .0,
        public array $aliases = [],
    ) {
    }

    /**
     * Под каким именем арена стоит в кольцах.
     */
    public function resolve(string $arena): string
    {
        $seen = [];

        while (isset($this->aliases[$arena]) && ! isset($seen[$arena])) {
            $seen[$arena] = true;
            $arena = $this->aliases[$arena];
        }

        return $arena;
    }

    /**
     * Где сейчас середины арен.
     *
     * @param array<string, float> $extents арена => как далеко от своей
     *                                      середины лежит то, что на ней сейчас
     *
     * @return array<string, Point2D>
     */
    public function place(array $extents): array
    {
        $children = [];

        foreach ($this->angles as $parent => $list) {
            foreach (array_keys($list) as $child) {
                $children[(string) $child] = true;
            }
        }

        $sizes = [];
        $rings = [];
        $result = $this->centers;

        foreach (array_keys($this->centers + $extents) as $arena) {
            $arena = (string) $arena;

            if (! isset($children[$arena])) {
                $this->measure($arena, $extents, $sizes, $rings);
                $this->put($arena, $this->centers[$arena] ?? new Point2D(), $rings, $result);
            }
        }

        return $result;
    }

    /**
     * Круг, в который помещается арена вместе с детьми, — середина
     * относительно середины арены и радиус; null — на ней и под ней сейчас
     * ничего нет. Мерится по кругу, а не от середины арены: кольцо детей
     * бывает незамкнутым, и тогда рисунок лежит на одну сторону — мерить
     * его от середины значит держать пустой вторую.
     *
     * @param array<string, float> $extents
     * @param array<string, array{float, float, float}|null> $sizes
     * @param array<string, array<string, array{float, float}>> $rings арена => ребёнок => сдвиг его середины
     *
     * @return array{float, float, float}|null
     */
    private function measure(string $arena, array $extents, array &$sizes, array &$rings): ?array
    {
        if (array_key_exists($arena, $sizes)) {
            return $sizes[$arena];
        }

        $home = $extents[$arena] ?? null;
        $circles = [];

        foreach (array_keys($this->angles[$arena] ?? []) as $child) {
            $circle = $this->measure((string) $child, $extents, $sizes, $rings);

            if ($circle !== null) {
                $circles[(string) $child] = $circle;
            }
        }

        if ($circles === []) {
            return $sizes[$arena] = $home === null ? null : [.0, .0, $home];
        }

        // Пустой стол с одним куском: кусок и лежит посередине.
        if ($home === null && count($circles) === 1) {
            [$ox, $oy, $radius] = reset($circles);
            $rings[$arena][(string) key($circles)] = [-$ox, -$oy];

            return $sizes[$arena] = [.0, .0, $radius];
        }

        $radii = array_map(static fn (array $circle): float => $circle[2], $circles);
        $parts = $home === null ? [] : [[.0, .0, $home]];

        $inner = $home ?? .0;

        foreach ($this->getShells($radii, $home ?? .0) as [, $shell]) {
            $outer = $inner;

            foreach ($this->getShellPlaces($arena, $shell, $radii, $inner) as $child => $at) {
                [$ox, $oy, $radius] = $circles[$child];
                $rings[$arena][$child] = [$at[0] - $ox, $at[1] - $oy];
                $parts[] = [$at[0], $at[1], $radius];
                $outer = max($outer, hypot($at[0], $at[1]) + $radius);
            }

            $inner = $outer;
        }

        return $sizes[$arena] = $this->getEnclosing($parts);
    }

    /**
     * Дети по слоям. Мелкие ложатся вплотную к середине, сколько поместится
     * по кругу; кто не поместился — следующим слоем, снаружи первого. Иначе
     * пара крупных соседей, которым на одном круге с мелкими не хватает
     * места, отогнала бы весь круг далеко от середины.
     *
     * @param array<string, float> $radii
     *
     * @return array<int, array{float, array<string, float>}> слой — докуда
     *         занято внутри него и кто в нём
     */
    private function getShells(array $radii, float $home): array
    {
        asort($radii);
        $result = [];
        $inner = $home;

        while ($radii !== []) {
            $shell = [];
            $sum = .0;

            foreach ($radii as $child => $radius) {
                $sector = 2 * asin(min(1.0, ($radius + $this->gap / 2) / ($inner + $this->gap + $radius)));

                if ($shell !== [] && $sum + $sector > 2 * M_PI) {
                    break;
                }

                $shell[(string) $child] = $radius;
                $sum += $sector;
            }

            $result[] = [$inner, $shell];
            $outer = $inner;

            foreach ($shell as $child => $radius) {
                unset($radii[$child]);
                $outer = max($outer, $inner + $this->gap + 2 * $radius);
            }

            $inner = $outer;
        }

        return $result;
    }

    /**
     * Места одного слоя: вплотную друг к другу, в прежнем порядке по кругу,
     * и поворот — такой, чтобы каждый стоял как можно ближе к своему
     * направлению, туда, где его вершины лежали у родителя.
     *
     * @param array<string, float> $shell
     * @param array<string, float> $radii
     *
     * @return array<string, array{float, float}>
     */
    private function getShellPlaces(string $arena, array $shell, array $radii, float $inner): array
    {
        $children = $this->getAround($arena, $shell);
        $count = count($children);
        // Слой — правильный многоугольник: три поля — треугольник, четыре —
        // квадрат. Все на одном расстоянии: не ближе к середине, чем позволяет
        // каждое, и так, чтобы соседи не налезали друг на друга.
        $distance = .0;

        foreach ($children as $number => $child) {
            $distance = max($distance, $inner + $this->gap + $radii[$child]);

            if ($count > 1) {
                $next = $children[($number + 1) % $count];
                $distance = max($distance, ($radii[$child] + $radii[$next] + $this->gap) / (2 * sin(M_PI / $count)));
            }
        }

        $distances = array_fill_keys($children, $distance);
        $angles = [];

        foreach ($children as $number => $child) {
            $angles[$child] = 2 * M_PI * $number / $count;
        }

        $x = .0;
        $y = .0;

        foreach ($children as $child) {
            $delta = $this->angles[$arena][$child] - $angles[$child];
            $x += sin($delta);
            $y += cos($delta);
        }

        $turn = atan2($x, $y);
        $result = [];

        foreach ($children as $child) {
            $result[$child] = [
                $distances[$child] * sin($angles[$child] + $turn),
                -$distances[$child] * cos($angles[$child] + $turn),
            ];
        }

        return $result;
    }

    /**
     * Круг вокруг нескольких кругов: середина — посередине их общей рамки.
     *
     * @param array<int, array{float, float, float}> $circles
     *
     * @return array{float, float, float}
     */
    private function getEnclosing(array $circles): array
    {
        $left = min(array_map(static fn (array $c): float => $c[0] - $c[2], $circles));
        $right = max(array_map(static fn (array $c): float => $c[0] + $c[2], $circles));
        $top = min(array_map(static fn (array $c): float => $c[1] - $c[2], $circles));
        $bottom = max(array_map(static fn (array $c): float => $c[1] + $c[2], $circles));
        [$x, $y] = [($left + $right) / 2, ($top + $bottom) / 2];
        $radius = .0;

        foreach ($circles as [$cx, $cy, $r]) {
            $radius = max($radius, hypot($cx - $x, $cy - $y) + $r);
        }

        return [$x, $y, $radius];
    }

    /**
     * Живые дети по кругу, начиная сразу за самым широким просветом между
     * их направлениями: там кольцо и останется незамкнутым.
     *
     * @param array<string, float> $radii
     *
     * @return string[]
     */
    private function getAround(string $arena, array $radii): array
    {
        $angles = [];

        foreach (array_keys($radii) as $child) {
            $angle = fmod($this->angles[$arena][$child], 2 * M_PI);
            $angles[(string) $child] = $angle < 0 ? $angle + 2 * M_PI : $angle;
        }

        asort($angles);
        $list = array_map('strval', array_keys($angles));
        $count = count($list);
        $start = 0;
        $widest = -1.0;

        foreach ($list as $number => $child) {
            $next = $list[($number + 1) % $count];
            $gap = $angles[$next] - $angles[$child] + ($number === $count - 1 ? 2 * M_PI : .0);

            if ($gap > $widest) {
                [$widest, $start] = [$gap, ($number + 1) % $count];
            }
        }

        return array_merge(array_slice($list, $start), array_slice($list, 0, $start));
    }

    /**
     * @param array<string, array<string, array{float, float}>> $rings
     * @param array<string, Point2D> $result
     */
    private function put(string $arena, Point2D $center, array $rings, array &$result): void
    {
        $result[$arena] = $center;

        foreach ($rings[$arena] ?? [] as $child => [$dx, $dy]) {
            $this->put(
                (string) $child,
                new Point2D($center->x + $dx, $center->y + $dy),
                $rings,
                $result,
            );
        }
    }
}
