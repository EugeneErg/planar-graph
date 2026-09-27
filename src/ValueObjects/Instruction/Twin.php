<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Instruction;

/**
 * Поле-цикл раздваивается: одиночный цикл ограничивает плоскость с двух
 * сторон, у него два поля — внутри и снаружи. В склейку идёт одно из них,
 * второе остаётся на месте.
 */
final readonly class Twin implements ActionInterface
{
    public function __construct(
        public string $arena,
        public string $copy,
    ) {
    }

    public function describe(): string
    {
        return sprintf('раздвоить поле-цикл арены %s → арена %s', $this->arena, $this->copy);
    }
}
