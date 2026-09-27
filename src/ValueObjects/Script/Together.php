<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Script;

use LogicException;

/**
 * Несколько действий разом — над разными кусками.
 *
 * Несвязные куски друг о друге ничего не знают: пока один разбирают
 * на двусвязные, второй может разбираться тем же приёмом и в то же время.
 * Показывать это по очереди — врать про алгоритм и заодно растягивать
 * рассказ во столько раз, сколько кусков.
 *
 * Одновременно можно только то, что не мешает друг другу: действия обязаны
 * трогать разные шары, иначе непонятно, кто из них что сделал. Это не совет,
 * а проверка в конструкторе, и она же не даёт вернуть старую беду —
 * «полграфа разлетелось в стороны»: две ветви одного куска всегда делят
 * остаток, поэтому одновременными быть не могут. Одновременны только чужие
 * друг другу куски.
 */
final readonly class Together implements ActionInterface
{
    /** @var ActionInterface[] */
    public array $actions;

    public function __construct(ActionInterface ...$actions)
    {
        $seen = [];

        foreach ($actions as $action) {
            // Внутри одного действия шар может встретиться дважды: у «отделить»
            // точка сочленения есть и в отрезаемом, и в остатке. Это одно
            // действие, и само себе оно не мешает.
            foreach (array_unique($action->vertexes()) as $vertex) {
                if (isset($seen[$vertex])) {
                    throw new LogicException(sprintf(
                        'Одновременно делают разное с шаром %d: %s',
                        $vertex,
                        implode('; ', array_map(
                            static fn (ActionInterface $item): string => $item->describe(),
                            $actions,
                        )),
                    ));
                }

                $seen[$vertex] = true;
            }
        }

        $this->actions = $actions;
    }

    public function describe(): string
    {
        return 'одновременно: ' . implode('; ', array_map(
            static fn (ActionInterface $action): string => $action->describe(),
            $this->actions,
        ));
    }

    public function isQuestion(): bool
    {
        foreach ($this->actions as $action) {
            if (! $action->isQuestion()) {
                return false;
            }
        }

        return $this->actions !== [];
    }

    /**
     * @return int[]
     */
    public function vertexes(): array
    {
        $result = [];

        foreach ($this->actions as $action) {
            foreach ($action->vertexes() as $vertex) {
                $result[] = $vertex;
            }
        }

        return $result;
    }
}
