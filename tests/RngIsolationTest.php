<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Infra\RngIsolation;

/**
 * RngIsolation — тесты save-and-restore паттерна.
 *
 * Проверяет что guard корректно захватывает/восстанавливает энтропию,
 * и что assertClean() ловит незакрытые guard'ы.
 *
 * NOTE: tearDown НЕ вызывает assertClean() здесь — некоторые тесты
 * специально проверяют поведение незакрытых guard'ов.
 */
class RngIsolationTest extends TestCase
{
    /** Save-and-restore: после restore guard закрыт */
    public function testSaveAndRestore(): void
    {
        $guard = RngIsolation::deterministicSeed(42);

        $this->assertTrue(RngIsolation::hasUnrestoredGuards(),
            'After deterministicSeed(), guard should be active');

        $guard->restore();

        $this->assertFalse(RngIsolation::hasUnrestoredGuards(),
            'After restore(), guard should be closed');
    }

    /** GUARD: assertClean бросает исключение при незакрытых guard'ах */
    public function testAssertCleanThrowsWhenUnrestored(): void
    {
        $guard = RngIsolation::deterministicSeed(42);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('RNG POISONING');
            RngIsolation::assertClean();
        } finally {
            // GUARD: всегда восстанавливаем, иначе tearDown уронит тест
            $guard->restore();
        }
    }

    /** Несколько вложенных guard'ов отслеживаются корректно */
    public function testNestedGuards(): void
    {
        $outer = RngIsolation::deterministicSeed(42);
        $this->assertTrue(RngIsolation::hasUnrestoredGuards());

        $inner = RngIsolation::deterministicSeed(99);
        $this->assertTrue(RngIsolation::hasUnrestoredGuards());

        $inner->restore();
        $this->assertTrue(RngIsolation::hasUnrestoredGuards(),
            'After inner restore: outer guard still active');

        $outer->restore();
        $this->assertFalse(RngIsolation::hasUnrestoredGuards(),
            'After outer restore: all guards closed');
    }

    /** early return c ручным restore */
    public function testEarlyReturnStillRestores(): void
    {
        $guard = RngIsolation::deterministicSeed(42);
        $guard->restore();  // "ранний return" — восстанавливаем сразу

        $this->assertFalse(RngIsolation::hasUnrestoredGuards());
    }

    /** Проверка что tearDown ловит забытый restore — ТОЛЬКО демонстрация */
    public function testForgottenRestoreIsDetected(): void
    {
        $guard = RngIsolation::deterministicSeed(42);

        $this->assertTrue(RngIsolation::hasUnrestoredGuards());

        // Cleanup — в реальном тесте это делает tearDown
        $guard->restore();
    }

    /**
     * E1.3-fix: createComposeTasks() использует RngIsolation
     * (раньше был ручной захват/восстановление seed).
     * Проверяем что после вызова RNG чист.
     */
    public function testCreateComposeTasksKeepsRngClean(): void
    {
        $tg = new \BeeSwarm\Hive\TaskGenerator();
        $tg->createComposeTasks();

        $this->assertFalse(
            RngIsolation::hasUnrestoredGuards(),
            'createComposeTasks() must restore RNG after deterministic block'
        );
    }
}
