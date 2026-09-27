<?php

/**
 * Тридцать вершин, связный, но не двусвязный граф. В середине решётка
 * три на четыре, разбитая диагоналями на треугольники. На ней висят
 * шестиугольник с хордой, K4, колесо и хвост: цепочка с треугольником
 * на конце.
 *
 * Сложный случай сразу для всех этапов: здесь ветви, поля разного
 * размера и несколько склеек.
 */
return [
    0 => [1 => true, 4 => true, 5 => true, 25 => true],
    1 => [0 => true, 2 => true, 5 => true],
    2 => [1 => true, 3 => true, 5 => true, 6 => true],
    3 => [2 => true, 6 => true, 7 => true, 12 => true, 16 => true],
    4 => [0 => true, 5 => true, 8 => true],
    5 => [0 => true, 1 => true, 2 => true, 4 => true, 6 => true, 8 => true, 9 => true],
    6 => [2 => true, 3 => true, 5 => true, 7 => true, 9 => true, 10 => true, 11 => true],
    7 => [3 => true, 6 => true, 11 => true],
    8 => [4 => true, 5 => true, 9 => true, 17 => true, 18 => true, 19 => true],
    9 => [5 => true, 6 => true, 8 => true, 10 => true],
    10 => [6 => true, 9 => true, 11 => true],
    11 => [6 => true, 7 => true, 10 => true, 20 => true, 21 => true, 24 => true],
    12 => [3 => true, 13 => true, 15 => true],
    13 => [12 => true, 14 => true],
    14 => [13 => true, 15 => true],
    15 => [12 => true, 14 => true, 16 => true],
    16 => [3 => true, 15 => true],
    17 => [8 => true, 18 => true, 19 => true],
    18 => [8 => true, 17 => true, 19 => true],
    19 => [8 => true, 17 => true, 18 => true],
    20 => [11 => true, 21 => true, 22 => true, 23 => true, 24 => true],
    21 => [11 => true, 20 => true, 22 => true],
    22 => [20 => true, 21 => true, 23 => true],
    23 => [20 => true, 22 => true, 24 => true],
    24 => [11 => true, 20 => true, 23 => true],
    25 => [0 => true, 26 => true],
    26 => [25 => true, 27 => true],
    27 => [26 => true, 28 => true, 29 => true],
    28 => [27 => true, 29 => true],
    29 => [27 => true, 28 => true],
];
