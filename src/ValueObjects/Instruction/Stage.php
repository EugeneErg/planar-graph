<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Instruction;

/**
 * Этап алгоритма. Сам ничего не делает — ждёт, пока закончатся дочерние
 * дорожки, и этим отделяет один этап от следующего.
 */
final readonly class Stage implements ActionInterface
{
    public function __construct(public string $title)
    {
    }

    public function describe(): string
    {
        return $this->title;
    }
}
