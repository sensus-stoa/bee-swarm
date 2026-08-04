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
        $prev = ini_get('memory_limit');
        ini_set('memory_limit', '128M');

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
