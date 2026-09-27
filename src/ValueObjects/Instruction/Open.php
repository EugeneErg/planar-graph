<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Instruction;

/**
 * Склеенное раскрывается в одно поле — круг. Его обход проходит через
 * точку склейки дважды, поэтому в круге у неё два угла: в каждом по ребру
 * от каждого из двух полей.
 */
final readonly class Open implements ActionInterface
{
    /**
     * @param int[] $walk обход круга; точка склейки встречается в нём дважды
     */
    public function __construct(
        public int $vertex,
        public array $walk,
        public string $arena,
        public string $other,
    ) {
    }

    public function describe(): string
    {
        return 'раскрыть в круг ' . implode('-', $this->walk);
    }
}
