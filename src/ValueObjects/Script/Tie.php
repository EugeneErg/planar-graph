<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Script;

/**
 * Протянуть между двумя шарами новую палку.
 *
 * В самом графе этой палки нет: её добавляет достройка односвязного графа до
 * двусвязного. Поэтому рисуется она пунктиром — видно, что связь искусственная.
 * Дальше по ней и режут.
 */
final readonly class Tie implements ActionInterface
{
    /**
     * Связывают не абы что: от точки сочленения отмеряют по половине каждой
     * из двух сошедшихся граней и соединяют то, куда дошли. Отсюда и палка.
     * Поэтому связка знает, откуда мерили и по каким путям, — иначе на
     * картинке она возьмётся ниоткуда, и вместо логики выйдет фокус.
     *
     * @param array{int, int} $edge шары, между которыми вяжут палку
     * @param array<int, int[]> $walks грани, которые должны получиться:
     *                                 по ним считается, сколько раз резать
     * @param int $at точка сочленения: от неё и мерят
     * @param array<int, int[]> $paths два пути от точки сочленения до концов
     *                                 палки — по половине грани каждый
     */
    public function __construct(
        public array $edge,
        public array $walks,
        public int $at = -1,
        public array $paths = [],
    ) {
    }

    public function describe(): string
    {
        return sprintf('связать %d и %d', $this->edge[0], $this->edge[1]);
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
        return $this->edge;
    }
}
