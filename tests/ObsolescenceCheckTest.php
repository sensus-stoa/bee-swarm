<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\LawRegistry;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4 (story DISSIPATION-LOOP): obsolescence recheck (§2.5.5).
 *
 * Перепроверка каждые 50 поколений: CV закона на свежих данных > eps →
 * флаг OBSOLETE. Наблюдатель: флаг, не удаление (контракт стори).
 */
final class ObsolescenceCheckTest extends TestCase
{
    protected function setUp(): void
    {
        Database::reset();
        Database::get();
    }

    protected function tearDown(): void
    {
        Database::setPath(':memory:');
        Database::reset();
    }

    /** До порога recheck — пусто. */
    public function testBeforeRecheckThresholdEmpty(): void
    {
        $registry = new LawRegistry(preserveCheckGen: 15, eps: 0.15);
        $registry->register('(x0×K2)', 'test_obs', generation: 1);

        $result = $registry->obsolescenceCheck(
            currentGeneration: 30,
            recheckEvery: 50,
            freshCv: fn (string $f, string $d): float => 0.0
        );

        self::assertSame([], $result);
    }

    /** CV подтверждается (низкий) → не OBSOLETE. */
    public function testHealthyLawNotObsolete(): void
    {
        $registry = new LawRegistry(preserveCheckGen: 15, eps: 0.15);
        $registry->register('(x0×K2)', 'test_obs', generation: 1);

        $result = $registry->obsolescenceCheck(
            currentGeneration: 60,
            recheckEvery: 50,
            freshCv: fn (string $f, string $d): float => 0.02
        );

        self::assertSame([], $result);
    }

    /** CV сломался (> eps) на свежих данных → OBSOLETE-флаг с CV. */
    public function testBrokenCVObsolete(): void
    {
        $registry = new LawRegistry(preserveCheckGen: 15, eps: 0.15);
        $registry->register('(x0×K2)', 'test_obs', generation: 1);

        $result = $registry->obsolescenceCheck(
            currentGeneration: 60,
            recheckEvery: 50,
            freshCv: fn (string $f, string $d): float => 0.42
        );

        self::assertCount(1, $result);
        self::assertSame('OBSOLETE', $result[0]['event']);
        self::assertSame('(x0×K2)', $result[0]['formula']);
        self::assertEqualsWithDelta(0.42, $result[0]['cv'], 0.0001);
    }

    /** Молодой закон (< recheckEvery поколений) не перепроверяется. */
    public function testYoungLawSkipped(): void
    {
        $registry = new LawRegistry(preserveCheckGen: 15, eps: 0.15);
        // два закона: старый (gen 1) и молодой (gen 55) при current=60, recheck=50
        $registry->register('(x0×K2)', 'test_obs', generation: 1);
        $registry->register('(x0+K1)', 'test_obs', generation: 55);

        $result = $registry->obsolescenceCheck(
            currentGeneration: 60,
            recheckEvery: 50,
            freshCv: fn (string $f, string $d): float => 0.5 // оба сломаны
        );

        // только старый попал под recheck
        self::assertCount(1, $result);
        self::assertSame('(x0×K2)', $result[0]['formula']);
    }
}
