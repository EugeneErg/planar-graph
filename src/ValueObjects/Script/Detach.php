<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Script;

/**
 * Отделить от куска одну часть.
 *
 * Ровно одну: так граф распадается на несвязные куски, а кусок — на двусвязные
 * ветви. Отпустить все части разом нельзя, поэтому и действия такого нет —
 * на каждую часть своё.
 *
 * Общий шар достаётся и части, и остатку: он раздваивается, и связи между ними
 * не остаётся.
 */
final readonly class Detach implements ActionInterface
{
    /**
     * @param int[] $part шары, которые уезжают
     * @param int[] $rest шары, которые остаются
     * @param bool $table увезти часть на свой стол: так расходятся несвязные
     *                    куски, каждому нужно место, где его будут резать
     */
    public function __construct(public array $part, public array $rest, public bool $table = false)
    {
    }

    public function describe(): string
    {
        return sprintf(
            'отделить %s от %s%s',
            implode(',', $this->part),
            implode(',', $this->rest),
            $this->table ? ' и увезти на свой стол' : '',
        );
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
        return array_merge($this->part, $this->rest);
    }
}
