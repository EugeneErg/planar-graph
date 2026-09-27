<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects;

/**
 * Один кадр рассказа: что и где нарисовано в этот момент.
 *
 * Граф виден целиком в каждом кадре: ничего не исчезает, всё только
 * переезжает. То, что сейчас не в работе, приглушается, а не прячется.
 *
 * Вершина может присутствовать в кадре несколько раз: когда граф разрезан
 * по точкам сочленения, общая вершина попадает в каждую из ветвей. Поэтому
 * кадр оперирует не вершинами, а их экземплярами — вершина плюс номер копии.
 * Пока разреза нет, копии стоят там же, где оригинал, и глазу неразличимы.
 *
 * Текста в кадре нет: что происходит, должно читаться по самой картинке.
 * От шага остаётся только его вид (`kind`) — чтобы было по чему искать кадр,
 * а не чтобы подписывать его зрителю.
 */
final readonly class Scene
{
    /**
     * @param array<string, Point2D> $vertexes экземпляр => его место
     * @param array<string, array{Point2D, Point2D}> $edges ребро => концы
     * @param array<string, int> $groups экземпляр => номер цветовой группы
     * @param array<string, true> $highlight экземпляры, о которых идёт речь
     * @param array<string, true> $faded экземпляры и рёбра, отложенные в сторону
     * @param float $weight сколько времени держится кадр
     * @param ?StageKind $kind какой шаг алгоритма показывает кадр; на картинку
     *                         не попадает — по ней шаг и так виден
     * @param array<string, array{string, int}> $flows ребро => с какого конца
     *                и каким цветом по нему в этот кадр переползает краска;
     *                видно, какая точка какую закрасила
     * @param array<string, true> $ties рёбра, которых в графе нет: их добавила
     *                склейка, чтобы односвязный граф стал двусвязным. Рисуются
     *                пунктиром — видно, что это не связь исходного графа
     * @param array<string, true> $blocked запертые шары: краска через них
     *                не пройдёт. Этим приёмом алгоритм и находит всё — куски,
     *                ветви, поля, — поэтому запертое видно сразу и издалека
     */
    public function __construct(
        public array $vertexes,
        public array $edges,
        public array $groups = [],
        public array $highlight = [],
        public array $faded = [],
        public float $weight = 1.0,
        public ?StageKind $kind = null,
        public array $flows = [],
        public array $ties = [],
        public array $blocked = [],
    ) {
    }

    /**
     * Ключ экземпляра вершины.
     */
    public static function vertexKey(int $vertex, int $copy = 0): string
    {
        return $vertex . ':' . $copy;
    }

    /**
     * Номер вершины по ключу её экземпляра.
     */
    public static function vertexOf(string $key): int
    {
        return (int) explode(':', $key)[0];
    }

    /**
     * Ключ экземпляра ребра. Разрез идёт и по рёбрам: ребро на границе
     * достаётся обоим кускам, поэтому у него тоже бывают копии.
     */
    public static function edgeKey(int $vertexA, int $vertexB, int $copy = 0): string
    {
        return self::edgeName($vertexA, $vertexB) . ':' . $copy;
    }

    /**
     * Имя ребра без номера копии: по нему считают копии и ищут ребро в куске.
     */
    public static function edgeName(int $vertexA, int $vertexB): string
    {
        return min($vertexA, $vertexB) . '-' . max($vertexA, $vertexB);
    }
}
