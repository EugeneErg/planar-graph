<?php

declare(strict_types=1);

namespace Tests;

use EugeneErg\Graphs\ValueObjects\Script\Block;
use EugeneErg\Graphs\ValueObjects\Script\Cut;
use EugeneErg\Graphs\ValueObjects\Script\Detach;
use EugeneErg\Graphs\ValueObjects\Script\Step;
use EugeneErg\Graphs\ValueObjects\Script\Together;
use LogicException;

/**
 * Одновременно — только чужие друг другу куски.
 *
 * Правило «одно действие за раз» вводилось не от любви к порядку: пока
 * рассказ отпускал все ветви разом, на картинке обводился десяток точек
 * сочленения, а потом полграфа улетало в стороны, и не было видно, что от
 * чего отрезали. Параллельность не должна вернуть эту беду — и не вернёт,
 * потому что две ветви одного куска всегда делят остаток.
 */
final class TogetherTest extends AbstractTestCase
{
    public function testStrangePiecesGoAtOnce(): void
    {
        $action = new Together(
            new Detach([1, 2, 3], [3, 4], false),
            new Detach([10, 11], [11, 12], false),
        );

        self::assertCount(2, $action->actions);
        self::assertStringStartsWith('одновременно: ', $action->describe());
    }

    public function testOnePieceCannotBeCutTwiceAtOnce(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/шаром 4/');

        // Обе ветви держатся за один и тот же остаток: разом их не отпустить.
        new Together(
            new Detach([1, 2, 4], [4, 5, 6], false),
            new Detach([7, 8, 4], [4, 5, 6], false),
        );
    }

    /**
     * Точка сочленения есть и в отрезаемом, и в остатке — это одно действие,
     * и само себе оно не мешает.
     */
    public function testActionDoesNotClashWithItself(): void
    {
        $action = new Together(new Detach([1, 2, 3], [3, 4, 5], false));

        self::assertCount(1, $action->actions);
    }

    /** Одновременное — это по-прежнему один шаг и один кадр. */
    public function testStepKnowsWhatItDoes(): void
    {
        $together = new Step(new Together(new Cut([1, 2, 3]), new Cut([4, 5, 6])));
        $alone = new Step(new Block([7]));

        self::assertCount(2, $together->actions());
        self::assertCount(1, $alone->actions());
        self::assertSame($alone->action, $alone->actions()[0]);
    }

    /** Вопрос и ответ не смешиваются: одновременное — либо то, либо это. */
    public function testQuestionsAndAnswersDoNotMix(): void
    {
        self::assertTrue((new Together(new Block([1]), new Block([2])))->isQuestion());
        self::assertFalse((new Together(new Cut([1, 2, 3]), new Block([4])))->isQuestion());
        self::assertFalse((new Together())->isQuestion());
    }
}
