<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects;

/**
 * Размеры стола, посчитанные до того, как его куда-то поставили.
 *
 * Стол меряется по всем укладкам сразу — и по той, что строится, и по
 * расслабленной. Они разного размера: собранная бывает заметно шире. Если
 * мерить по одной, вторая не поместится: по расслабленной — места на кольце
 * окажутся внутри стройки, по собранной — расслабленный кусок вылезет
 * на соседний стол.
 *
 * Сначала меряют все столы, потом решают, где какой стоит, и только потом
 * ставят (`at`). Разделено потому, что «куда поставить» зависит от размеров
 * всех столов сразу: они раскладываются по кольцу вокруг центрального, и
 * радиус кольца подбирается по ним.
 */
final readonly class TableSize
{
    /**
     * @param Point2D $built где окажется центр собранной укладки
     * @param Point2D $tangled где кусок лежал в клубке: по этому углу стол
     *                         и уезжает на своё место, чтобы куски по дороге
     *                         не прошли друг сквозь друга
     * @param float $radius домашний круг
     * @param float $ring круг мест
     * @param array<int, float> $angles место => под каким углом оно лежит
     * @param float $extent весь стол целиком, со всем, что на нём вырастет
     */
    public function __construct(
        public Point2D $built,
        public Point2D $tangled,
        public float $turn,
        public float $radius,
        public float $ring,
        public array $angles,
        public float $extent,
    ) {
    }

    /**
     * Стол, поставленный в эту точку.
     *
     * @param Point2D[] $home домашние места вершин, уже посчитанные вокруг
     *                        этой же точки
     * @param Point2D[] $slots места вокруг стола
     */
    public function at(Point2D $point, array $home, array $slots): Table
    {
        return new Table(
            center: $point,
            home: $home,
            radius: $this->radius,
            // Внешний круг: на нём лежат места, туда же уезжает и то, что
            // не поместилось внутрь запертого обхода. Даже когда мест нет,
            // круг должен быть — выносить куда-то надо.
            ring: max($this->ring, $this->radius * 1.8),
            turn: $this->turn,
            slots: $slots,
            shift: new Point2D($point->x - $this->built->x, $point->y - $this->built->y),
        );
    }
}
