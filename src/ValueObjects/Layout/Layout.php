<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Layout;

/**
 * Результат геометрии: инструкция, у каждого действия которой известно,
 * где после него что лежит.
 */
final readonly class Layout
{
    /**
     * @param Placement[] $placements
     * @param Packing $packing как кольца арен подгоняются под то, что на них
     *                        лежит в каждый момент
     */
    public function __construct(
        public array $placements,
        public Packing $packing = new Packing(),
    ) {
    }
}
