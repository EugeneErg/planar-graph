<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Instruction;

/**
 * Залить из вершины: краска волна за волной расходится по рёбрам и
 * останавливается там, где упирается в край или в запертое.
 */
final readonly class Fill implements ActionInterface
{
    /**
     * @param array<int, int[]> $waves волны после первой вершины, по порядку
     */
    public function __construct(
        public int $from,
        public array $waves,
    ) {
    }

    public function describe(): string
    {
        $waves = array_map(Format::vertexes(...), $this->waves);

        return 'залить от ' . $this->from . ': ' . ($waves === [] ? '—' : implode(' → ', $waves));
    }
}
