<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Instruction;

/**
 * Дорожка: цепочка шагов, которая идёт одновременно с соседними.
 *
 * Дорожка работает на своей арене: запирания и заливки в ней относятся
 * к тому, что лежит на этой арене.
 */
final readonly class Lane
{
    /**
     * @param Step[] $steps
     * @param string $arena на какой арене идёт работа; пустая — исходный граф
     */
    public function __construct(
        public string $title,
        public array $steps,
        public string $arena = '',
    ) {
    }
}
