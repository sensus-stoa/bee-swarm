<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Infra\GlobalStateGuard;

/**
 * D19.1: GlobalStateGuard — ловит утечки глобального состояния PHP.
 */
class GlobalStateGuardTest extends TestCase
{
    public function testCleanPasses(): void
    {
        GlobalStateGuard::snapshot();
        GlobalStateGuard::assertClean();
        $this->assertTrue(true);
    }

    public function testDetectsIniSetLeak(): void
    {
        GlobalStateGuard::snapshot();
        // PHP 8.2: ini_set(memory_limit) ниже текущего usage НЕ работает (false).
        // Воркер paratest после Hive-тестов держит гигабайты → ставим ВЫШЕ usage.
        $prev = ini_get('memory_limit');
        $usageMb = (int) ceil(memory_get_usage(true) / 1024 / 1024);
        $targetMb = $usageMb + 64;
        if ($prev === $targetMb . 'M') {
            $targetMb += 64;  // коллизия с текущим значением — сдвигаем
        }
        $target = $targetMb . 'M';
        $this->assertNotFalse(ini_set('memory_limit', $target),
            "Precondition: ini_set({$target}) must succeed (usage {$usageMb}MB)");

        try {
            GlobalStateGuard::assertClean();
            $this->fail('Should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ini_set', $e->getMessage());
        } finally {
            ini_set('memory_limit', $prev);
        }
    }

    public function testDetectsErrorReportingLeak(): void
    {
        GlobalStateGuard::snapshot();
        $prev = error_reporting();
        error_reporting(0);

        try {
            GlobalStateGuard::assertClean();
            $this->fail('Should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('error_reporting', $e->getMessage());
        } finally {
            error_reporting($prev);
        }
    }
}
