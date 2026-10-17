<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\ExpressionNormalizer;

/**
 * DREAM-HARDENING WU-3a (prereg 29.09, stories/dream-hardening).
 *
 * Риск (agent-review F1b): ExpressionNormalizer::simplify имеет спец-ветку
 * только у 'sq' — unary-узлы (abs/sqrt/parity/log2/neg/inv) идут в общий
 * l/r-путь с r=null → TypeError. Мост сужен до 'sq', весь унарный
 * compose-класс недоступен dream-пути, атомы записываются мёртвыми.
 *
 * Критерий 3a: normalize('((x0+x1))abs') возвращает канон, не TypeError.
 * RED-прогноз до фикса: TypeError («Exception ... TypeError»).
 */
class NormalizerUnaryBridgeTest extends TestCase
{
    public function testNormalizeUnaryAbsDoesNotThrowTypeError(): void
    {
        $canonical = ExpressionNormalizer::normalize('((x0+x1))abs');
        $this->assertIsString($canonical);
        $this->assertNotSame('', $canonical, 'unary-мост обязан давать непустой канон');
    }
}
