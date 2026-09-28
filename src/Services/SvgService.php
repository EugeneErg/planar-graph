<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\ValueObjects\Point2D;
use EugeneErg\Graphs\ValueObjects\Scene;
use LogicException;

/**
 * Рисует последовательность кадров в SVG.
 *
 * Граф виден целиком всё время: ничего не исчезает, всё только переезжает.
 * То, что сейчас не в работе, приглушается, и гаснет оно заранее — на время
 * переезда, а не по прибытии: иначе яркая линия летит через рисунок и на глаз
 * пересекает его.
 *
 * Все экземпляры вершин заводятся заранее — и те, что нужны, лишь когда граф
 * разрезан по точкам сочленения. Пока разреза нет, копии стоят поверх
 * оригинала, поэтому раздвоение выглядит как расхождение, а не как вспышка.
 *
 * Движение задаётся тегами SMIL прямо в документе, поэтому картинка
 * самодостаточна — ни скриптов, ни внешних файлов.
 */
final readonly class SvgService
{
    /** Цвета групп: заливки, куски графа, ветви, грани. */
    private const array PALETTE = [
        '#c96442',
        '#5a8f7b',
        '#7b6ea8',
        '#bf8a3d',
        '#4f7ca6',
        '#a2596f',
        '#6f8f45',
        '#8a6a5c',
    ];

    /** Отложенное в сторону не прячется, а приглушается: граф виден целиком. */
    private const string FADED = '0.22';

    /** Короче этого рассказ не бывает, даже если в нём всего пара шагов. */
    private const float MIN_DURATION = 30.0;

    /** Сколько секунд отводится на шаг обычного веса. */
    private const float SECONDS_PER_WEIGHT = 0.45;

    public function __construct(
        private float $vertexRadius = 11.0,
        private float $margin = 24.0,
        private float $minVertexGap = 3.2,
        private float $minEdgeGap = 1.2,
        private GeometryService $geometry = new GeometryService(),
    ) {
    }

    /**
     * Неподвижная картинка одной укладки.
     *
     * @param Point2D[] $coordinates
     * @param true[][] $connections
     */
    public function render(array $coordinates, array $connections): string
    {
        $vertexes = [];
        $edges = [];

        foreach ($coordinates as $vertex => $point) {
            $vertexes[Scene::vertexKey($vertex)] = $point;
        }

        foreach ($connections as $vertexA => $connection) {
            foreach (array_keys($connection) as $vertexB) {
                if ($vertexA < $vertexB && isset($coordinates[$vertexA], $coordinates[$vertexB])) {
                    $edges[Scene::edgeKey($vertexA, $vertexB)] = [$coordinates[$vertexA], $coordinates[$vertexB]];
                }
            }
        }

        return $this->animate([new Scene($vertexes, $edges)]);
    }

    /**
     * Картинка проигрывается один раз и замирает на готовой укладке: смотреть
     * на неё интереснее, чем на вечно начинающийся заново рассказ.
     *
     * Длительность считается по самому рассказу: чем больше в нём шагов,
     * тем дольше он идёт. Иначе у большого графа каждый шаг сжимается
     * до неразличимого мига — а показывать надо как раз большие.
     *
     * @param Scene[] $scenes
     * @param ?float $duration длительность в секундах; по умолчанию — по числу шагов
     * @param ?float $unit шаг между соседями, когда кусок лежит кругом. Если
     *                     он известен, масштаб постоянный: между соседями на
     *                     круге просвет ровно в одну вершину. Иначе масштаб
     *                     подбирается по готовой укладке
     */
    public function animate(array $scenes, ?float $duration = null, bool $repeat = false, ?float $unit = null): string
    {
        $scenes = $this->scale(array_values($scenes), $unit);
        $duration ??= $this->getDuration($scenes);

        if ($scenes === []) {
            throw new LogicException('Нужен хотя бы один кадр.');
        }

        $keyTimes = $this->getRoundedTimes($this->getKeyTimes($scenes));
        $body = '';
        $hiddenEdges = $this->getCovered($scenes, true);
        $hiddenVertexes = $this->getCovered($scenes, false);

        foreach ($this->getEdgeKeys($scenes) as $key) {
            $body .= $this->renderEdge($key, $scenes, $keyTimes, $duration, $repeat, $hiddenEdges[$key] ?? []);
        }

        $body .= $this->renderFlows($scenes, $keyTimes, $duration);

        foreach ($this->getVertexKeys($scenes) as $key) {
            $body .= $this->renderVertex($key, $scenes, $keyTimes, $duration, $repeat, $hiddenVertexes[$key] ?? []);
        }

        return $this->renderDocument($this->getViewBox($scenes), $this->renderCamera($scenes, $keyTimes, $duration, $repeat) . $body);
    }

    /**
     * Камера: рамка в каждом кадре подогнана под то, что на картинке сейчас,
     * и плавно едет от кадра к кадру. Рисунок всегда занимает всё окно:
     * пока граф разложен по столам, камера отъезжает, когда он собран —
     * наезжает на него.
     *
     * Рамка меняется с тем же сглаживанием и в те же моменты, что и
     * движение вершин, поэтому вершина в пути из рамки не выходит.
     *
     * @param Scene[] $scenes
     * @param float[] $keyTimes
     */
    private function renderCamera(array $scenes, array $keyTimes, float $duration, bool $repeat): string
    {
        $boxes = array_map(
            fn (Scene $scene): string => implode(' ', array_map(self::coord(...), $this->getViewBox([$scene]))),
            $scenes,
        );

        return $this->renderAnimate('viewBox', $boxes, $keyTimes, $duration, $repeat);
    }

    /**
     * Масштабирует рассказ так, чтобы в итоговом кадре ближайшие вершины
     * не налезали друг на друга: иначе картинку приходится приближать.
     *
     * @param Scene[] $scenes
     * @param ?float $unit шаг между соседями на круге, если известен
     *
     * @return Scene[]
     */
    private function scale(array $scenes, ?float $unit = null): array
    {
        if ($scenes === []) {
            return $scenes;
        }

        if ($unit !== null && $unit > GeometryService::EPSILON) {
            // Центры соседей — в двух поперечниках вершины: между шарами
            // остаётся ровно ещё один.
            $scale = 4 * $this->vertexRadius / $unit;
        } else {
            $final = $scenes[count($scenes) - 1];
            $distance = $this->getMinVertexDistance($final->vertexes);

            if ($distance === null || $distance < GeometryService::EPSILON) {
                return $scenes;
            }

            // Мало развести вершины: вершина, налезшая на чужое ребро, читается
            // как пересечение, которого нет. Поэтому масштаб берётся такой, чтобы
            // и между вершинами, и между вершиной и чужим ребром был просвет.
            $clearance = $this->getMinEdgeClearance($final);
            $scale = $this->vertexRadius * $this->minVertexGap / $distance;

            if ($clearance !== null && $clearance > GeometryService::EPSILON) {
                $scale = max($scale, $this->vertexRadius * $this->minEdgeGap / $clearance);
            }
        }

        $point = static fn (Point2D $item): Point2D => new Point2D($item->x * $scale, $item->y * $scale);

        return array_map(
            static fn (Scene $scene): Scene => new Scene(
                vertexes: array_map($point, $scene->vertexes),
                edges: array_map(
                    static fn (array $edge): array => [$point($edge[0]), $point($edge[1])],
                    $scene->edges,
                ),
                groups: $scene->groups,
                highlight: $scene->highlight,
                faded: $scene->faded,
                weight: $scene->weight,
                kind: $scene->kind,
                flows: $scene->flows,
                ties: $scene->ties,
                blocked: $scene->blocked,
            ),
            $scenes,
        );
    }

    /**
     * Насколько близко вершина подошла к чужому ребру в готовой укладке.
     */
    private function getMinEdgeClearance(Scene $scene): ?float
    {
        $result = null;

        foreach ($scene->vertexes as $point) {
            foreach ($scene->edges as [$from, $to]) {
                if ($this->geometry->distance($point, $from) < GeometryService::EPSILON
                    || $this->geometry->distance($point, $to) < GeometryService::EPSILON
                ) {
                    continue;
                }

                $distance = $this->geometry->distanceToSegment($point, $from, $to);
                $result = $result === null ? $distance : min($result, $distance);
            }
        }

        return $result;
    }

    /**
     * Расстояние между ближайшими соседями в кадре.
     *
     * Считается между разными вершинами: экземпляры одной вершины в готовой
     * укладке лежат друг на друге, и если их считать, расстояние всегда ноль
     * и масштабировать нечего.
     *
     * @param Point2D[] $vertexes
     */
    private function getMinVertexDistance(array $vertexes): ?float
    {
        $keys = array_keys($vertexes);
        $count = count($keys);
        $result = null;

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (Scene::vertexOf($keys[$i]) === Scene::vertexOf($keys[$j])) {
                    continue;
                }

                $distance = $this->geometry->distance($vertexes[$keys[$i]], $vertexes[$keys[$j]]);

                if ($result === null || $distance < $result) {
                    $result = $distance;
                }
            }
        }

        return $result;
    }

    /**
     * @param Scene[] $scenes
     *
     * @return string[]
     */
    private function getVertexKeys(array $scenes): array
    {
        $result = [];

        foreach ($scenes as $scene) {
            foreach (array_keys($scene->vertexes) as $key) {
                $result[$key] = true;
            }
        }

        return array_keys($result);
    }

    /**
     * @param Scene[] $scenes
     *
     * @return string[]
     */
    private function getEdgeKeys(array $scenes): array
    {
        $result = [];

        foreach ($scenes as $scene) {
            foreach (array_keys($scene->edges) as $key) {
                $result[$key] = true;
            }
        }

        return array_keys($result);
    }

    /**
     * @param Scene[] $scenes
     * @param float[] $keyTimes
     * @param array<int, true> $hidden кадры, где экземпляр скрыт под другим
     */
    private function renderEdge(string $key, array $scenes, array $keyTimes, float $duration, bool $repeat, array $hidden = []): string
    {
        $points = [];
        $shown = [];
        $classes = [];
        $last = null;

        foreach ($scenes as $scene) {
            $last = $scene->edges[$key] ?? $last;
            $points[] = $last;
            $shown[] = ! isset($scene->edges[$key]) ? '0' : (isset($scene->faded[$key]) ? self::FADED : '1');
            // Цвет ребра задаётся классом, а не атрибутом: правило стиля всё
            // равно перебило бы и атрибут, и его анимацию, а внутри анимации
            // переменная темы не работает.
            // Выделяется поле целиком — и шары, и палки между ними. Обвести
            // одни шары мало: в клубке они разбросаны по всей окружности,
            // и какое поле собрались резать, по ним не прочесть.
            $classes[] = $this->getEdgeClass($scene->groups[$key] ?? null)
                . (isset($scene->ties[$key]) ? ' tie' : '')
                . (isset($scene->highlight[$key]) ? ' mark' : '');
        }

        $first = $this->getFirstDefined($points);

        if ($first === null) {
            return '';
        }

        [$shown, $points] = $this->applyCovered($this->fadeWhileMoving($shown), $points, $hidden);
        $animations = '';

        // Ребро — путь из одного отрезка: оба конца едут одной анимацией,
        // а не четырьмя (x1, y1, x2, y2), у каждой из которых свой список
        // моментов и сглаживаний.
        $line = static fn (array $item): string => sprintf(
            'M%s %sL%s %s',
            self::coord($item[0]->x),
            self::coord($item[0]->y),
            self::coord($item[1]->x),
            self::coord($item[1]->y),
        );
        $animations = $this->renderAnimate(
            'd',
            array_map(static fn (?array $item): string => $line($item ?? $first), $points),
            $keyTimes,
            $duration,
            $repeat,
        );

        return sprintf(
            '<path class="%s" d="%s" opacity="%s">%s%s%s</path>',
            $classes[0],
            $line($first),
            $shown[0],
            $animations,
            $this->renderAnimate('opacity', $shown, $keyTimes, $duration, $repeat, discrete: true),
            count(array_unique($classes)) < 2
                ? ''
                : $this->renderAnimate('class', $classes, $keyTimes, $duration, $repeat, discrete: true),
        );
    }

    /**
     * Сколько идёт рассказ: столько, сколько в нём шагов, но не меньше минуты
     * пополам. Шаг держится примерно полсекунды — за меньшее не уследить.
     *
     * @param Scene[] $scenes
     */
    private function getDuration(array $scenes): float
    {
        $total = .0;

        foreach ($scenes as $number => $scene) {
            $total += $number === 0 ? .0 : $scene->weight;
        }

        return max(self::MIN_DURATION, $total * self::SECONDS_PER_WEIGHT);
    }

    /**
     * Краска, ползущая по ребру.
     *
     * Ребро прорисовывается от закрашенного конца к другому: линия того же
     * цвета лежит поверх ребра и «дорисовывается» пунктиром с уезжающим
     * сдвигом. Едет она ровно тот отрезок времени, за который кадр сменяется
     * следующим, — и к моменту, когда краска дошла, вершина на том конце уже
     * закрашена. Пока краска ползёт, граф стоит на месте, поэтому линии
     * хватает постоянных координат.
     *
     * @param Scene[] $scenes
     * @param float[] $keyTimes
     */
    private function renderFlows(array $scenes, array $keyTimes, float $duration): string
    {
        $result = '';

        foreach ($scenes as $number => $scene) {
            if ($number === 0) {
                continue;
            }

            foreach ($scene->flows as $key => [$from, $group]) {
                $edge = $scene->edges[$key] ?? null;
                $start = $scene->vertexes[$from] ?? null;

                if ($edge === null || $start === null) {
                    continue;
                }

                $result .= $this->renderFlow(
                    $edge,
                    $start,
                    $group,
                    $keyTimes[$number - 1] * $duration,
                    $keyTimes[$number] * $duration,
                );
            }
        }

        return $result;
    }

    /**
     * @param array{Point2D, Point2D} $edge
     */
    private function renderFlow(array $edge, Point2D $start, int $group, float $from, float $to): string
    {
        // Рисуем от того конца, с которого пришла краска.
        [$head, $tail] = hypot($edge[0]->x - $start->x, $edge[0]->y - $start->y)
            <= hypot($edge[1]->x - $start->x, $edge[1]->y - $start->y)
                ? [$edge[0], $edge[1]]
                : [$edge[1], $edge[0]];
        $length = hypot($tail->x - $head->x, $tail->y - $head->y);

        if ($length < GeometryService::EPSILON || $to - $from < GeometryService::EPSILON) {
            return '';
        }

        // Длина линии принята за единицу (pathLength): пунктир и его сдвиг
        // пишутся одной цифрой, а не длиной ребра в пикселях.
        return sprintf(
            '<line class="edge g%d flow" x1="%s" y1="%s" x2="%s" y2="%s" opacity="0"'
            . ' pathLength="1" stroke-dasharray="1" stroke-dashoffset="1">'
            . '<set attributeName="opacity" to="1" begin="%ss" fill="freeze"/>'
            . '<animate attributeName="stroke-dashoffset" begin="%ss" dur="%ss" values="1;0" fill="freeze"/>'
            . '<set attributeName="opacity" to="0" begin="%ss" fill="freeze"/>'
            . '</line>',
            $group % count(self::PALETTE),
            self::coord($head->x),
            self::coord($head->y),
            self::coord($tail->x),
            self::coord($tail->y),
            self::number($from),
            self::number($from),
            self::number($to - $from),
            self::number($to),
        );
    }

    /**
     * @param Scene[] $scenes
     * @param float[] $keyTimes
     * @param array<int, true> $hidden кадры, где экземпляр скрыт под другим
     */
    private function renderVertex(string $key, array $scenes, array $keyTimes, float $duration, bool $repeat, array $hidden = []): string
    {
        $points = [];
        $shown = [];
        $fills = [];
        $opacities = [];
        $rings = [];
        $locks = [];
        $last = null;

        foreach ($scenes as $scene) {
            $last = $scene->vertexes[$key] ?? $last;
            $points[] = $last;
            $shown[] = ! isset($scene->vertexes[$key]) ? '0' : (isset($scene->faded[$key]) ? self::FADED : '1');
            $group = $scene->groups[$key] ?? null;
            $fills[] = $this->getGroupColor($group);
            $opacities[] = $group === null ? '0' : '0.35';
            // Запертый шар обведён толстым тёмным кольцом: краска через него
            // не пройдёт. Заливкой его не пометить — под ней не видно номера,
            // а номер тут нужен: по нему и читается, что заперли.
            $locks[] = isset($scene->blocked[$key]) ? '1' : '0';
            $rings[] = isset($scene->highlight[$key]) ? '1' : '0';
        }

        $first = $this->getFirstDefined($points);

        if ($first === null) {
            return '';
        }

        [$shown, $points] = $this->applyCovered($this->fadeWhileMoving($shown), $points, $hidden);
        $positions = array_map(
            static fn (?Point2D $item): string => self::coord(($item ?? $first)->x) . ',' . self::coord(($item ?? $first)->y),
            $points,
        );
        $radius = self::number($this->vertexRadius);

        return sprintf(
            '<g class="vertex" transform="translate(%s)" opacity="%s">%s%s'
                . '<circle class="base" r="%s"/>'
                . '<circle class="group" r="%s" fill="%s" fill-opacity="%s">%s%s</circle>'
                . '<circle class="ring" r="%s" opacity="%s">%s</circle>'
                . '<circle class="lock" r="%s" opacity="%s">%s</circle>'
                . '<text dominant-baseline="central" text-anchor="middle">%d</text></g>',
            $positions[0],
            $shown[0],
            $this->renderAnimate('transform', $positions, $keyTimes, $duration, $repeat, 'animateTransform'),
            $this->renderAnimate('opacity', $shown, $keyTimes, $duration, $repeat, discrete: true),
            $radius,
            $radius,
            $fills[0],
            $opacities[0],
            $this->renderAnimate('fill', $fills, $keyTimes, $duration, $repeat, discrete: true),
            $this->renderAnimate('fill-opacity', $opacities, $keyTimes, $duration, $repeat, discrete: true),
            self::number($this->vertexRadius + 3),
            $rings[0],
            $this->renderAnimate('opacity', $rings, $keyTimes, $duration, $repeat, discrete: true),
            self::number($this->vertexRadius + 5),
            $locks[0],
            $this->renderAnimate('opacity', $locks, $keyTimes, $duration, $repeat, discrete: true),
            Scene::vertexOf($key),
        );
    }

    /**
     * Когда копия лежит ровно на другом экземпляре той же вершины (или того
     * же ребра) и выглядит так же, её не видно: до разреза копии лежат
     * поверх оригинала, после сборки снова сходятся. Хранить её путь на это
     * время незачем — она скрыта, а видимый экземпляр рисует то же самое.
     *
     * Скрыта она в кадре, если совпадает с видимым соседом и в этом кадре,
     * и в следующем: переход между ними тогда тоже один на двоих.
     *
     * @param Scene[] $scenes
     *
     * @return array<string, array<int, true>> экземпляр => кадры, где он скрыт
     */
    private function getCovered(array $scenes, bool $edges): array
    {
        $groups = [];

        foreach ($edges ? $this->getEdgeKeys($scenes) : $this->getVertexKeys($scenes) as $key) {
            [$name, $copy] = explode(':', $key) + [1 => '0'];
            $groups[$name][(int) $copy] = $key;
        }

        $result = [];
        $count = count($scenes);

        foreach ($groups as $keys) {
            if (count($keys) < 2) {
                continue;
            }

            ksort($keys);
            $keys = array_values($keys);

            for ($number = 0; $number < $count; $number++) {
                $next = min($number + 1, $count - 1);
                $shown = [];

                foreach ($keys as $key) {
                    $covered = false;

                    foreach ($shown as $leader) {
                        if ($this->isSame($scenes[$number], $key, $leader, $edges)
                            && $this->isSame($scenes[$next], $key, $leader, $edges)
                        ) {
                            $covered = true;

                            break;
                        }
                    }

                    if ($covered) {
                        $result[$key][$number] = true;
                    } else {
                        $shown[] = $key;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Лежат ли два экземпляра в кадре в одном месте и выглядят ли одинаково.
     */
    private function isSame(Scene $scene, string $key, string $other, bool $edges): bool
    {
        $same = static fn (Point2D $a, Point2D $b): bool => abs($a->x - $b->x) < 1e-6 && abs($a->y - $b->y) < 1e-6;

        if ($edges) {
            $a = $scene->edges[$key] ?? null;
            $b = $scene->edges[$other] ?? null;

            if ($a === null || $b === null || ! $same($a[0], $b[0]) || ! $same($a[1], $b[1])) {
                return false;
            }

            return isset($scene->ties[$key]) === isset($scene->ties[$other]);
        } else {
            $a = $scene->vertexes[$key] ?? null;
            $b = $scene->vertexes[$other] ?? null;

            if ($a === null || $b === null || ! $same($a, $b)) {
                return false;
            }

            if (isset($scene->blocked[$key]) !== isset($scene->blocked[$other])) {
                return false;
            }
        }

        return ($scene->groups[$key] ?? null) === ($scene->groups[$other] ?? null)
            && isset($scene->highlight[$key]) === isset($scene->highlight[$other])
            && isset($scene->faded[$key]) === isset($scene->faded[$other]);
    }

    /**
     * Скрытые кадры: не видно и путь не нужен. Первый кадр скрытого отрезка
     * держит настоящее место — в него экземпляр приезжает видимым; дальше
     * он стоит там, где снова покажется, чтобы из записи ушли лишние точки.
     *
     * @template T
     *
     * @param string[] $shown
     * @param array<int, T> $points
     * @param array<int, true> $hidden
     *
     * @return array{string[], array<int, T>}
     */
    private function applyCovered(array $shown, array $points, array $hidden): array
    {
        if ($hidden === []) {
            return [$shown, $points];
        }

        $count = count($shown);

        for ($number = 0; $number < $count; $number++) {
            if (! isset($hidden[$number])) {
                continue;
            }

            $end = $number;

            while (isset($hidden[$end + 1])) {
                $end++;
            }

            $target = $points[$end + 1] ?? $points[$number];

            for ($middle = $number; $middle <= $end; $middle++) {
                $shown[$middle] = '0';

                if ($middle > $number) {
                    $points[$middle] = $target;
                }
            }

            $number = $end;
        }

        return [$shown, $points];
    }

    /**
     * Гасит элемент на время переезда, а не по прибытии.
     *
     * Иначе отложенное в сторону летит через рисунок в полную яркость и
     * на глаз пересекает его. Элемент показывается ярко только там, где он
     * на месте и в этом кадре, и в следующем.
     *
     * @param string[] $values
     *
     * @return string[]
     */
    private function fadeWhileMoving(array $values): array
    {
        $count = count($values);

        for ($i = 0; $i < $count - 1; $i++) {
            // Именно приглушение, а не исчезновение: то, чего в кадре нет вовсе,
            // не должно гаснуть заранее и пропадать раньше времени.
            if ($values[$i + 1] === self::FADED && $values[$i] === '1') {
                $values[$i] = self::FADED;
            }
        }

        return $values;
    }

    /**
     * @template T
     *
     * @param array<int, T|null> $values
     *
     * @return T|null
     */
    private function getFirstDefined(array $values): mixed
    {
        foreach ($values as $value) {
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function getGroupColor(?int $group): string
    {
        return self::PALETTE[$group === null ? 0 : abs($group) % count(self::PALETTE)];
    }

    /**
     * Класс ребра: своего цвета у отрезанного поля, особый — у надрезанного
     * ребра, которое ждёт второго разреза.
     */
    private function getEdgeClass(?int $group): string
    {
        if ($group === null) {
            return 'edge';
        }

        return $group < 0 ? 'edge half' : 'edge g' . ($group % count(self::PALETTE));
    }

    /**
     * Доли времени, на которых стоит каждый кадр.
     *
     * @param Scene[] $scenes
     *
     * @return float[]
     */
    private function getKeyTimes(array $scenes): array
    {
        $count = count($scenes);

        if ($count < 2) {
            return [0.0];
        }

        $total = .0;

        for ($i = 1; $i < $count; $i++) {
            $total += $scenes[$i]->weight;
        }

        $result = [0.0];
        $passed = .0;

        for ($i = 1; $i < $count; $i++) {
            $passed += $scenes[$i]->weight;
            $result[] = min($passed / $total, 1.0);
        }

        $result[$count - 1] = 1.0;

        return $result;
    }


    /**
     * Оставляет только те моменты, в которых значение менялось.
     *
     * Кадров в рассказе сотни, а каждая вершина шевелится в считанных из них.
     * Пока в `values` писались все кадры подряд, один шар уносил по числу
     * на кадр — и на сотне вершин svg распухал до полусотни мегабайт, из
     * которых почти всё — повторение одного и того же. Браузер на таком
     * встаёт, а видно ровно то же самое.
     *
     * Середина постоянного участка ничего не задаёт: `discrete` держит
     * значение до следующего момента, а сплайн между двумя равными концами
     * даёт ту же прямую. Поэтому из каждого такого участка остаются только
     * его концы — картинка та же, а размер падает во столько раз, во сколько
     * кадров больше, чем движений.
     *
     * @param string[] $values
     * @param float[] $keyTimes
     *
     * @return array{string[], float[]}
     */
    private function getChanges(array $values, array $keyTimes): array
    {
        $count = count($values);
        $moments = [];
        $result = [];

        for ($number = 0; $number < $count; $number++) {
            if ($number !== 0
                && $number !== $count - 1
                && $values[$number] === $values[$number - 1]
                && $values[$number] === $values[$number + 1]
            ) {
                continue;
            }

            $result[] = $values[$number];
            $moments[] = $keyTimes[$number];
        }

        return [$result, $moments];
    }

    /**
     * @param string[] $values
     * @param float[] $keyTimes
     */
    private function renderAnimate(
        string $attribute,
        array $values,
        array $keyTimes,
        float $duration,
        bool $repeat,
        string $tag = 'animate',
        bool $discrete = false,
    ): string {
        if (count($values) < 2 || count(array_unique($values)) < 2) {
            return '';
        }

        [$values, $keyTimes] = $this->getChanges($values, $keyTimes);
        // Движение линейное: у SMIL нет общей кривой разгона и торможения,
        // её пришлось бы писать на каждый отрезок каждой анимации — это пятая
        // часть всего файла. Линейная интерполяция — поведение по умолчанию.
        $smoothing = $discrete ? ' calcMode="discrete"' : '';

        return sprintf(
            '<%s attributeName="%s"%s dur="%ss" values="%s" keyTimes="%s"%s%s fill="freeze"/>',
            $tag,
            $attribute,
            $tag === 'animateTransform' ? ' type="translate"' : '',
            self::number($duration),
            implode(';', $values),
            implode(';', array_map(self::moment(...), $keyTimes)),
            $smoothing,
            // Один раз — поведение по умолчанию, писать его незачем.
            $repeat ? ' repeatCount="indefinite"' : '',
        );
    }


    /**
     * @param Scene[] $scenes
     *
     * @return array{float, float, float, float}
     */
    private function getViewBox(array $scenes): array
    {
        $minX = $minY = INF;
        $maxX = $maxY = -INF;

        foreach ($scenes as $scene) {
            foreach ($scene->vertexes as $point) {
                $minX = min($minX, $point->x);
                $minY = min($minY, $point->y);
                $maxX = max($maxX, $point->x);
                $maxY = max($maxY, $point->y);
            }
        }

        $padding = $this->vertexRadius + $this->margin;

        return [
            $minX - $padding,
            $minY - $padding,
            max($maxX - $minX + $padding * 2, 1.0),
            max($maxY - $minY + $padding * 2, 1.0),
        ];
    }

    /**
     * Правила для цветных рёбер: по одному на цвет палитры.
     */
    private function renderEdgeColors(): string
    {
        $result = '';

        foreach (self::PALETTE as $number => $color) {
            $result .= sprintf('.edge.g%d{stroke:%s}', $number, $color);
        }

        return $result;
    }

    /**
     * @param array{float, float, float, float} $viewBox
     */
    private function renderDocument(array $viewBox, string $body): string
    {
        [$x, $y, $width, $height] = $viewBox;

        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="%s %s %s %s" width="%s" height="%s">'
            . '<style>'
            . ':root{color-scheme:light dark}'
            . 'svg{--paper:#fdfdfc;--line:#3d3929;--edge:#8a8781;--half:#bf8a3d;background:var(--paper)}'
            . '.edge{fill:none;stroke:var(--edge);stroke-width:2.4;stroke-linecap:round}'
            // Надрезанное ребро: одно поле его уже забрало, второе ещё нет.
            . '.edge.half{stroke:var(--half);stroke-width:2.4}'
            // Краска, ползущая по ребру: той же толщины, что и само ребро, —
            // она и есть ребро, закрашенное до сюда.
            . '.edge.flow{stroke-width:2.4;stroke-linecap:round}'
            // Связка: её в графе нет, её добавила склейка — поэтому пунктир.
            . '.edge.tie{stroke-dasharray:7 6;stroke-width:2.2;opacity:0.75}'
            // Выделенное поле: тем же цветом, что и кольцо на его шарах, —
            // обвод один, просто он идёт по всему полю, а не по точкам.
            . '.edge.mark{stroke:#c96442;stroke-width:3.6;opacity:1}'
            . $this->renderEdgeColors()
            . '.vertex .base{fill:var(--paper);stroke:var(--line);stroke-width:1.8}'
            . '.vertex .group{stroke:none}'
            . '.vertex .ring{fill:none;stroke:#c96442;stroke-width:2.4}'
            // Запертый шар: краска через него не пройдёт.
            . '.vertex .lock{fill:none;stroke:var(--line);stroke-width:3.4}'
            . '.vertex text{fill:var(--line);font:600 11px/1 ui-monospace,SFMono-Regular,Menlo,monospace}'
            . '@media (prefers-color-scheme:dark){'
            . 'svg{--paper:#1f1e1d;--line:#e8e6dc;--edge:#6b6862;--half:#d6a354}'
            . '}'
            . '</style>%s</svg>',
            self::coord($x),
            self::coord($y),
            self::coord($width),
            self::coord($height),
            self::coord($width),
            self::coord($height),
            $body,
        );
    }

    /**
     * Координата — целым пикселем: полпикселя глазу не видно, а каждая
     * цифра повторяется в записи пути сотни тысяч раз.
     */
    private static function coord(float $value): string
    {
        $result = (string) (int) round($value);

        return $result === '-0' ? '0' : $result;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * Доли времени — с тем числом знаков, при котором соседние ещё
     * различаются, и не больше: лишние знаки повторяются в каждой анимации
     * каждого шара и каждой палки.
     *
     * @param float[] $keyTimes
     *
     * @return float[]
     */
    private function getRoundedTimes(array $keyTimes): array
    {
        for ($digits = 3; $digits < 6; $digits++) {
            $rounded = array_map(static fn (float $time): float => round($time, $digits), $keyTimes);

            if (count(array_unique(array_map(strval(...), $rounded))) === count($rounded)) {
                return $rounded;
            }
        }

        return $keyTimes;
    }

    /**
     * Доля времени кадра: округлять её до сотых нельзя.
     *
     * Кадров в рассказе за сотню, доли идут через тысячные — при округлении
     * соседние сливаются в одну, и кадр между ними получает нулевую длину.
     * На картинке это выглядит так, будто выделение и действие слиплись:
     * сначала всё подсветилось, потом всё разом произошло.
     */
    private static function moment(float $value): string
    {
        // Ноль перед точкой не нужен: «.25» — такое же число, как «0.25»,
        // а повторяется оно в каждой анимации.
        return preg_replace('/^0(?=\.)/', '', rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.') ?: '0') ?? '0';
    }
}
