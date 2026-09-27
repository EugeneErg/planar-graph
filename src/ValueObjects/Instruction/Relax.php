<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Instruction;

/**
 * Собранная укладка расслабляется: внешняя грань стоит, внутренние
 * вершины расходятся, пока рисунок не станет читаемым
 * (`CoordinateService::relaxSteps`).
 */
final readonly class Relax implements ActionInterface
{
    public function describe(): string
    {
        return 'расслабить';
    }
}
