<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Layout;

use EugeneErg\Graphs\ValueObjects\Instruction\ActionInterface;
use EugeneErg\Graphs\ValueObjects\Point2D;

/**
 * Действие инструкции вместе с тем, куда после него всё легло.
 *
 * Места даны только для арен, которых действие коснулось: своей и той,
 * куда уехало отрезанное. Остальные арены лежат как лежали. Так одновременные
 * дорожки не мешают друг другу: каждая двигает только своё.
 */
final readonly class Placement
{
    /**
     * @param string $arena на какой арене действие
     * @param array<string, array<int, Point2D>> $places арена => вершина => место
     * @param PlacementLane[] $children одновременные дорожки, как в инструкции
     * @param array<string, string> $frames арена => вокруг чьей середины
     *        разложены её места, если не вокруг своей: поле, легшее в укладку,
     *        лежит на середине куска и едет вместе с ним
     */
    public function __construct(
        public ActionInterface $action,
        public string $arena,
        public array $places,
        public array $children = [],
        public array $frames = [],
    ) {
    }
}
