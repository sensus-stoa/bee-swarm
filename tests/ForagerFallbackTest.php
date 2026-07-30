<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

/**
 * Forager universal fallback: works without FORAGER_SOURCES env var.
 *
 * @group disabled — requires full Hive run with home directory scan (2+ min).
 * Run manually: vendor/bin/phpunit tests/ForagerFallbackTest.php --no-configuration
 */
class ForagerFallbackTest extends TestCase
{
    /** Проверяет что Hive не падает без FORAGER_SOURCES */
    public function testHiveConstructsWithoutForagerSources(): void
    {
        $oldEnv = getenv('FORAGER_SOURCES');
        putenv('FORAGER_SOURCES');

        try {
            $hive = new \BeeSwarm\Hive\Hive(maxTicks: 1);
            $this->assertInstanceOf(\BeeSwarm\Hive\Hive::class, $hive);
        } finally {
            if ($oldEnv !== false) putenv("FORAGER_SOURCES={$oldEnv}");
        }
    }
}
