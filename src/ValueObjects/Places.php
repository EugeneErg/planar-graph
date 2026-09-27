<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects;

/**
 * Где что лежит в этом состоянии рассказа.
 *
 * Один слепок стола: координаты всех экземпляров, концы всех палок и то,
 * какому куску какой экземпляр достался. Из него собирается кадр, и почти
 * каждому методу отрисовки он нужен целиком.
 */
final readonly class Places
{
    /**
     * @param array<string, Point2D> $positions экземпляр => где он лежит
     * @param array<string, array{string, string}> $edges палка => её концы
     * @param array<int, array<int, string>> $pieces кусок => вершина => её
     *                                              экземпляр в этом куске
     * @param array<int, int> $hosts кусок => на каком куске он сейчас лежит;
     *                               ещё не отрезанный ждёт поверх своего
     */
    public function __construct(
        public array $positions = [],
        public array $edges = [],
        public array $pieces = [],
        public array $hosts = [],
    ) {
    }
}
