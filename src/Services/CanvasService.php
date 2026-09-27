<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\Aggregates\Canvas;
use EugeneErg\Graphs\Aggregates\Trace;
use EugeneErg\Graphs\ValueObjects\StageKind;

readonly class CanvasService
{
    /**
     * Заливка по связям: начинаем с одной вершины и волна за волной
     * захватываем соседей того же цвета.
     *
     * Каждая волна попадает в журнал отдельным шагом — на картинке видно,
     * как заливка расползается. Волна из одной вершины, которая никуда
     * не разошлась, ничего не показывает и в журнал не пишется.
     *
     * @return int[] закрашенные вершины в порядке обхода
     */
    public function fill(Canvas $canvas, int $vertex, int $color, ?Trace $trace = null): array
    {
        $oldColor = $canvas->getPixel($vertex);
        $canvas->setPixel($vertex, $color);
        $result = [$vertex];
        $wave = [$vertex];
        // Снимок холста целиком, а не только этой заливки: иначе на картинке
        // стирается всё, что закрасили раньше.
        $steps = [['wave' => $wave, 'pixels' => $canvas->getPixels(), 'flows' => []]];

        while ($wave !== []) {
            $next = [];
            // Откуда в вершину пришла краска: по этой связи она и переползёт
            // на картинке, и видно, какая точка какую закрасила.
            $flows = [];

            foreach ($wave as $current) {
                foreach (array_keys($canvas->graph->getConnection($current) ?? []) as $connected) {
                    if ($canvas->isPixel($connected, $oldColor)) {
                        $canvas->setPixel($connected, $color);
                        $next[] = $connected;
                        $flows[$connected] = $current;
                        $result[] = $connected;
                    }
                }
            }

            if ($next !== []) {
                $steps[] = ['wave' => $next, 'pixels' => $canvas->getPixels(), 'flows' => $flows];
            }

            $wave = $next;
        }

        if (count($result) > 1) {
            $this->traceFill($trace, $vertex, $steps);
        }

        return $result;
    }

    /**
     * @param int[] $vertexes
     */
    public function setPixels(Canvas $canvas, array $vertexes, int $color): void
    {
        foreach ($vertexes as $vertex) {
            $canvas->setPixel($vertex, $color);
        }
    }

    /**
     * @param array<int, array{wave: int[], pixels: array<int, int>, flows: array<int, int>}> $steps волны заливки по порядку
     */
    private function traceFill(?Trace $trace, int $start, array $steps): void
    {
        if ($trace === null) {
            return;
        }

        $total = 0;

        foreach ($steps as $number => ['wave' => $wave, 'pixels' => $pixels, 'flows' => $flows]) {
            $total += count($wave);
            $trace->add(
                StageKind::Fill,
                $number === 0
                    ? sprintf('Заливка: начинаем с вершины %d', $start)
                    : sprintf('Заливка: шаг %d, закрашено %d', $number, $total),
                $this->getGroups($pixels),
                $wave,
                $flows,
            );
        }
    }

    /**
     * Закрашенное на этот момент, разложенное по цветам.
     *
     * @param array<int, int> $pixels
     *
     * @return array<int, int[]>
     */
    private function getGroups(array $pixels): array
    {
        $result = [];

        foreach ($pixels as $vertex => $color) {
            $result[$color][] = $vertex;
        }

        return $result;
    }
}
