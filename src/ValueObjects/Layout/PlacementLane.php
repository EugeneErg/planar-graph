<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Layout;

/**
 * Дорожка с местами: цепочка действий одной арены.
 */
final readonly class PlacementLane
{
    /**
     * @param Placement[] $placements
     */
    public function __construct(
        public string $arena,
        public array $placements,
    ) {
    }
}
