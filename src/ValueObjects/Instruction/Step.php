<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects\Instruction;

/**
 * Шаг инструкции: действие и дочерние дорожки.
 *
 * Дочерние дорожки идут одновременно, каждая — своя цепочка шагов. Когда
 * закончились все, выполняется действие самого шага. Так и записывается
 * параллельность: «найти ветви» ждёт, пока каждый кусок разберёт свои.
 */
final readonly class Step
{
    /**
     * @param Lane[] $children
     */
    public function __construct(
        public ActionInterface $action,
        public array $children = [],
    ) {
    }

    /**
     * @return string[]
     */
    public function describe(string $indent = ''): array
    {
        $result = [$indent . $this->action->describe()];
        $single = count($this->children) === 1 && $this->children[0]->title === '';

        foreach ($this->children as $number => $lane) {
            $last = $number === count($this->children) - 1;

            if ($single) {
                foreach ($lane->steps as $step) {
                    array_push($result, ...$step->describe($indent . '  '));
                }

                continue;
            }

            $result[] = $indent . ($last ? '└ ' : '├ ') . $lane->title;

            foreach ($lane->steps as $step) {
                array_push($result, ...$step->describe($indent . ($last ? '    ' : '│   ')));
            }
        }

        return $result;
    }
}
