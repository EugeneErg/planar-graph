<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Instruction;

/**
 * Склеить два поля в точке сочленения: поле корня и поле ветви, оба
 * проходят через неё. Копии точки сходятся в одну.
 */
final readonly class Glue implements ActionInterface
{
    public function __construct(
        public int $vertex,
        public string $arena,
        public string $other,
    ) {
    }

    public function describe(): string
    {
        return sprintf('склеить в точке %d поля арен %s и %s', $this->vertex, $this->arena, $this->other);
    }
}
