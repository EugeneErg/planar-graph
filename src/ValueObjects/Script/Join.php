<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Script;

/**
 * Сдвинуть две грани в одну.
 *
 * Они сходятся в точке сочленения: по этому шару их когда-то и разрезали,
 * теперь он снова один. Второй его экземпляр остаётся лежать поверх первого —
 * шаров стало меньше, а из кадра ничего не пропало.
 */
final readonly class Join implements ActionInterface
{
    /**
     * @param int[] $first обход первой грани
     * @param int[] $second обход второй грани
     */
    public function __construct(public array $first, public array $second)
    {
    }

    public function describe(): string
    {
        return sprintf('сдвинуть %s и %s в одну грань', implode('-', $this->first), implode('-', $this->second));
    }

    public function isQuestion(): bool
    {
        return false;
    }

    /**
     * @return int[]
     */
    public function vertexes(): array
    {
        return array_merge($this->first, $this->second);
    }
}
