<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Instruction;

/**
 * Ответ проверки: висящие на запертом обходе куски разложены по две его
 * стороны. Снаружи — то, что достаёт до уже пройденной границы, и то, что
 * с ним несовместимо через одно; внутри — остальное. Отрезается потом
 * внутренняя сторона вместе с обходом.
 *
 * На первом обходе ветви сторону «снаружи» задать нечем, и если всё
 * висящее легло внутрь, алгоритм считает его другой стороной.
 */
final readonly class Sides implements ActionInterface
{
    /**
     * @param array<int, int[]> $inside куски внутри
     * @param array<int, int[]> $outside куски снаружи
     */
    public function __construct(
        public array $inside,
        public array $outside,
    ) {
    }

    /**
     * @return int[]
     */
    public function insideVertexes(): array
    {
        return array_merge([], ...$this->inside);
    }

    /**
     * @return int[]
     */
    public function outsideVertexes(): array
    {
        return array_merge([], ...$this->outside);
    }

    public function describe(): string
    {
        $pieces = static fn (array $list): string => $list === []
            ? '—'
            : implode(', ', array_map(Format::vertexes(...), $list));

        return 'стороны: внутри ' . $pieces($this->inside) . '; снаружи ' . $pieces($this->outside);
    }
}
