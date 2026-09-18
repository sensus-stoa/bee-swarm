<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\VerificationExecutor;
use PHPUnit\Framework\TestCase;

/**
 * V0.16 WU-1 (verifier-eps-parity): резолвер порога поиска исполнителя.
 *
 * Контракт спеки: env VVERIFY_CV_TRAIN_MAX задан → env (операторский
 * override); не задан → epsilon из V-задачи (та же калибровка домена, что
 * у открывателя — WU-5 прогон V0.14: зона partial cv∈[0.05, eps] была
 * архитектурно неподтверждаема); нет epsilon (ghost) → константа
 * CV_TRAIN_MAX.
 *
 * PHP-falsy гвард: env='0' — ЗАДАННЫЙ порог 0.0, не fallback (старый `?:`
 * молча подменял ноль константой). Семантика 0.0: строгий < в Search::find
 * означает «ничего не подтверждать» (exact даёт cv=0.0, не < 0.0) —
 * осознанный операторский kill-switch, фиксируем тестом.
 */
final class VerificationEpsParityTest extends TestCase
{
    protected function setUp(): void
    {
        // Гарантия чистого env-состояния между тестами (putenv без значения = unset).
        putenv('VVERIFY_CV_TRAIN_MAX');
    }

    protected function tearDown(): void
    {
        putenv('VVERIFY_CV_TRAIN_MAX');
    }

    /**
     * Резолвер приватный — контракт через ReflectionMethod (прецедент S1.x).
     */
    private function resolve(array $vtask): float
    {
        $m = new \ReflectionMethod(VerificationExecutor::class, 'resolveCvMax');
        $m->setAccessible(true);

        return $m->invoke(new VerificationExecutor(), $vtask);
    }

    /**
     * RED: epsilon калибровки домена из V-задачи становится порогом, когда
     * операторский env не задан. Partial-закон cv=0.07 при eps=0.0865
     * подтверждаем (сейчас: не подтверждаем — константа 0.05).
     */
    public function testResolverPrefersVtaskEpsilonWhenEnvUnset(): void
    {
        $vtask = [
            'epsilon' => 0.0865,
            'fingerprint' => 'fp_partial',
        ];

        self::assertSame(0.0865, $this->resolve($vtask));
    }

    /**
     * RED: env приоритетнее калибровки — операторский override.
     */
    public function testResolverEnvOverridesCalibration(): void
    {
        putenv('VVERIFY_CV_TRAIN_MAX=0.06');
        $vtask = [
            'epsilon' => 0.0865,
        ];

        self::assertSame(0.06, $this->resolve($vtask));
    }

    /**
     * RED: ghost-задача (fingerprint='' / колонка NULL) → fallback константы.
     */
    public function testResolverGhostFallsBackToConstant(): void
    {
        self::assertSame(VerificationExecutor::CV_TRAIN_MAX, $this->resolve([
            'fingerprint' => '',
        ]));
        self::assertSame(VerificationExecutor::CV_TRAIN_MAX, $this->resolve([]));
    }

    /**
     * RED: PHP-falsy ловушка — env='0' трактуется как заданный порог 0.0
     * (страрый `?:` подставлял константу). Порядок приоритета сохраняется:
     * '0' бьёт и epsilon, и константу.
     */
    public function testResolverEnvZeroIsHonoredNotFallback(): void
    {
        putenv('VVERIFY_CV_TRAIN_MAX=0');

        self::assertSame(0.0, $this->resolve([
            'epsilon' => 0.0865,
        ]));
    }
}
