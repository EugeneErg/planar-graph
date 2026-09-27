<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Instruction;

/**
 * Укладка из дуг выпрямляется: внешняя грань стоит, каждая внутренняя
 * вершина садится в среднее своих соседей
 * (`CoordinateService::getBarycentricCoordinates`).
 */
final readonly class Straighten implements ActionInterface
{
    public function describe(): string
    {
        return 'выпрямить';
    }
}
