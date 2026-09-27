<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Script;

use EugeneErg\Graphs\ValueObjects\StageKind;

/**
 * Атом рассказа: выделил — и сделал то, ради чего выделял.
 *
 * Выделение и действие живут в одном шаге и разъединить их нельзя. Именно
 * поэтому шаг и заведён: пока кадры собирались руками, раз за разом получался
 * светофор — сначала обводилось всё, потом всё разом происходило. Теперь такой
 * рассказ просто невыразим: кольцо принадлежит действию, а не кадру.
 *
 * Выделять можно только то, с чем работают: шары, которых действие не касается,
 * в `marks` не попадут — их там нечем назвать.
 */
final readonly class Step
{
    /**
     * @param int[] $marks шары, которые обводят перед действием; пустой список
     *                     значит, что обводить нечего — действие и так видно
     * @param ?StageKind $kind что это за действие
     * @param ?StageKind $marked что показывает выделение, если это не то же
     *                           самое: кольцо на точке сочленения — это шаг
     *                           «нашли точку сочленения», а разрез следом —
     *                           уже «режем ветвь»
     */
    public function __construct(
        public ActionInterface $action,
        public array $marks = [],
        public ?StageKind $kind = null,
        public ?StageKind $marked = null,
    ) {
    }

    /**
     * Что на этом шаге делают — по отдельности.
     *
     * Обычно действие одно. Но несвязные куски разбираются одновременно,
     * и тогда шаг несёт несколько действий сразу (`Together`). Кадр у них
     * всё равно один: одновременное — это одно событие, а не несколько.
     * Кому нужно «что вообще делают», тот и спрашивает здесь.
     *
     * @return ActionInterface[]
     */
    public function actions(): array
    {
        return $this->action instanceof Together ? $this->action->actions : [$this->action];
    }

    /**
     * Шаг одной строкой: «обвести 3,7 → вырезать грань 3-7-8».
     */
    public function describe(): string
    {
        return ($this->marks === [] ? '' : 'обвести ' . implode(',', $this->marks) . ' → ')
            . $this->action->describe();
    }
}
