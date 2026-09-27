<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Instruction;

/**
 * Круг разрезается по связке на два поля. У каждого — своя копия точки
 * склейки; концы связки и сама связка раздваиваются. Первое поле ложится
 * на место первого из склеенных, второе — на место второго.
 */
final readonly class Split implements ActionInterface
{
    /**
     * @param int[] $first
     * @param int[] $second
     */
    public function __construct(
        public int $vertex,
        public array $first,
        public string $arena,
        public array $second,
        public string $other,
    ) {
    }

    public function describe(): string
    {
        return sprintf(
            'разрезать по связке: %s → арена %s, %s → арена %s',
            implode('-', $this->first),
            $this->arena,
            implode('-', $this->second),
            $this->other,
        );
    }
}
