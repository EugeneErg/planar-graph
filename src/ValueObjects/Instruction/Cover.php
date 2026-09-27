<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Instruction;

/**
 * Несколько полей ложатся вместе. Либо обычная дуга накрыла отложенные —
 * и они ложатся с ней (`merged` = false), либо укладывать больше нечего,
 * и отложенные виртуально объединяются, чтобы лечь одной дугой
 * (`merged` = true).
 */
final readonly class Cover implements ActionInterface
{
    /**
     * @param array<string, int[]> $fields арена => поле
     */
    public function __construct(
        public array $fields,
        public bool $merged,
    ) {
    }

    public function describe(): string
    {
        $list = implode(', ', array_map(
            static fn (string $arena, array $walk): string => implode('-', $walk) . ' (арена ' . $arena . ')',
            array_keys($this->fields),
            $this->fields,
        ));

        return ($this->merged ? 'объединить отложенные и уложить: ' : 'дуга накрыла отложенные, уложить вместе: ') . $list;
    }
}
