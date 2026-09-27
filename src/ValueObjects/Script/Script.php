<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Script;

use EugeneErg\Graphs\ValueObjects\StageKind;

/**
 * Инструкция: что делают с клубком, по шагу за раз.
 *
 * Между журналом алгоритма и картинкой стоит именно она. Журнал говорит, что
 * алгоритм нашёл; инструкция — что надо показать; отрисовка — где это нарисовать.
 * Координат в инструкции нет, кадров тоже: она про действия.
 *
 * Читается глазами (`describe`), и проверять порядок рассказа надо по ней,
 * а не по кадрам: в кадрах уже поздно — там видно только результат.
 */
final readonly class Script
{
    /**
     * @param Step[] $steps
     */
    public function __construct(public array $steps = [])
    {
    }

    /**
     * @return string[]
     */
    public function describe(): array
    {
        return array_map(static fn (Step $step): string => $step->describe(), $this->steps);
    }

    /**
     * Инструкция без этих этапов — вместе с подготовкой к ним.
     *
     * Нужна, чтобы смотреть этап отдельно: показать разбиение на куски
     * и на двусвязные, а поиск полей не показывать вовсе. Раньше для этого
     * резались готовые кадры, и получалась неправда: алгоритм занимается
     * кусками по очереди, поэтому «до последнего разреза ветви» — это ещё
     * и все поля шести кусков до него.
     *
     * Выкидывать надо вместе с подготовкой. Запирание и заливка сами по себе
     * ничего не значат — они вопрос, который задают перед действием. Если
     * оставить вопрос и убрать ответ, на картинке останется запертый обход,
     * про который так и не сказали, зачем его запирали. Поэтому подготовка
     * копится и выдаётся только вместе с тем действием, ради которого её
     * делали, — а если то действие выкинули, выкидывается и она.
     */
    public function without(StageKind ...$kinds): self
    {
        $drop = [];

        foreach ($kinds as $kind) {
            $drop[$kind->value] = true;
        }

        $result = [];
        $waiting = [];

        foreach ($this->steps as $step) {
            if ($step->kind !== null && isset($drop[$step->kind->value])) {
                $waiting = [];

                continue;
            }

            if ($step->action->isQuestion()) {
                $waiting[] = $step;

                continue;
            }

            $result = [...$result, ...$waiting, $step];
            $waiting = [];
        }

        return new self($result);
    }

    /**
     * Шаги, на которых делают что-то такое.
     *
     * Одновременные действия тоже считаются: шаг, на котором отделяют семь
     * ветвей сразу, — это шаг, на котором отделяют.
     *
     * @param class-string $action
     *
     * @return Step[]
     */
    public function of(string $action): array
    {
        return array_values(array_filter($this->steps, static function (Step $step) use ($action): bool {
            foreach ($step->actions() as $item) {
                if ($item instanceof $action) {
                    return true;
                }
            }

            return false;
        }));
    }
}
