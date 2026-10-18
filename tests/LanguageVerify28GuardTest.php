<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\LanguageVerify28;
use PHPUnit\Framework\TestCase;

/**
 * TEST-HYGIENE (fast2min, 18.10): LanguageVerify28Test (8 тестов, 3:20 —
 * полный cert-пайплайн verify_2_8) вынесен из tests/ в канонический раннер
 * scripts/verify_2_8.php (env-шапка, report, exit 0/1 по вердикту) — это
 * верификационный инструмент, не unit-тест (прецедент WineDatasetTest).
 * Здесь остаётся БЫСТРЫЙ guard: пайплайн отказывается работать на
 * персистентной БД (защита от засорения прода верификационными законами).
 */
final class LanguageVerify28GuardTest extends TestCase
{
    protected function tearDown(): void
    {
        // ENV-ТЕЧЬ (12.10, suite 214 skip): /tmp/v28_guard_forbidden.db —
        // без восстановления весь хвост очереди paratest-воркера скипается.
        putenv('SWARM_DB_PATH=:memory:');
        LanguageVerify28::reset();
        parent::tearDown();
    }

    public function testRefusesToRunOnPersistentDatabase(): void
    {
        putenv('SWARM_DB_PATH=/tmp/v28_guard_forbidden.db');
        LanguageVerify28::reset();

        $this->expectException(\RuntimeException::class);
        LanguageVerify28::report();
    }
}
