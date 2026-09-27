<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Script;

/**
 * Ничего не трогать, просто дать посмотреть.
 *
 * Такое действие нужно исходному графу и найденным граням: клубок не меняется,
 * но кадр нужен — иначе следующее действие начнётся с полуслова.
 */
final readonly class Show implements ActionInterface
{
    /**
     * @param int[] $vertexes что именно показывают, если показывают не всё:
     *                        внешнюю грань, например, — с неё начнётся сборка
     */
    public function __construct(public string $what = '', public array $vertexes = [])
    {
    }

    public function describe(): string
    {
        return 'показать' . ($this->what === '' ? '' : ' ' . $this->what);
    }

    public function isQuestion(): bool
    {
        return true;
    }

    /**
     * @return int[]
     */
    public function vertexes(): array
    {
        return $this->vertexes;
    }
}
