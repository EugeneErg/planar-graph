<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Instruction;

/**
 * Инструкция: последовательность действий над графом.
 *
 * Отвечает только за «что и в каком порядке». Читается как конспект
 * алгоритма (`describe`), и проверять её надо по этому тексту.
 */
final readonly class Instruction
{
    /**
     * @param Step[] $steps
     */
    public function __construct(public array $steps)
    {
    }

    public function describe(): string
    {
        $result = [];

        foreach ($this->steps as $step) {
            array_push($result, ...$step->describe());
            $result[] = '';
        }

        return rtrim(implode("\n", $result)) . "\n";
    }
}
