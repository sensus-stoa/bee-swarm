<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\LawRegistry;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * Phase 7 фиксы премортема deleg_9af558c1 (DISSIPATION-LOOP):
 * З2c: OBSOLETE-флаг не персистился → вечный штраф каждый аудит.
 * З3: in_array O(N×M) → array_flip+isset.
 * З5: DISSIPATION лог без cooldown → burst флуд.
 *
 * Решение: audit_state в law_generations ('pending'|'loss'|'obsolete'),
 * односторонний переход — закон не штрафуется повторно.
 */
final class AuditStateTest extends TestCase
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

    /** RED: после первого аудита закон помечен loss и НЕ возвращается повторно. */
    public function testLossReportedOnce(): void
    {
        $reg = new LawRegistry(preserveCheckGen: 15, eps: 0.15);
        $reg->register('(K2×x0)', 'test_as', generation: 1);

        $first = $reg->audit(15, 0.15, []); // reservoir пуст → vanished
        self::assertCount(1, $first, 'первый аудит: LOSS');

        $second = $reg->audit(20, 0.15, []);
        self::assertSame([], $second, 'повторный аудит: тот же LOSS не переэмитится');
    }

    /** RED: OBSOLETE-флаг персистентен — не штрафуется каждый аудит. */
    public function testObsoleteReportedOnce(): void
    {
        $reg = new LawRegistry(preserveCheckGen: 15, eps: 0.15);
        $reg->register('(K2×x0)', 'test_as', generation: 1);

        $freshCv = fn (string $f, string $d): float => 0.5; // сломан
        $first = $reg->obsolescenceCheck(60, 50, $freshCv);
        self::assertCount(1, $first, 'первый recheck: OBSOLETE');

        $second = $reg->obsolescenceCheck(70, 50, $freshCv);
        self::assertSame([], $second, 'повторный recheck: OBSOLETE не переэмитится');
    }

    /** audit_state записан в law_generations. */
    public function testAuditStatePersisted(): void
    {
        $reg = new LawRegistry(preserveCheckGen: 15, eps: 0.15);
        $reg->register('(K2×x0)', 'test_as', generation: 1);
        $reg->audit(15, 0.15, []);

        $stmt = Database::get()->prepare(
            'SELECT audit_state FROM law_generations WHERE formula = ? AND domain = ?'
        );
        $stmt->execute(['(K2×x0)', 'test_as']);
        self::assertSame('loss', $stmt->fetchColumn());
    }

    /** Живой закон (в aliveFormulas, cv ok) не переходит в loss — остаётся pending. */
    public function testAliveLawStaysPending(): void
    {
        $reg = new LawRegistry(preserveCheckGen: 15, eps: 0.15);
        $reg->register('(K2×x0)', 'test_as', generation: 1);
        $reg->audit(15, 0.15, ['(K2×x0)'], fn (): bool => true);

        $stmt = Database::get()->prepare(
            'SELECT audit_state FROM law_generations WHERE formula = ?'
        );
        $stmt->execute(['(K2×x0)']);
        self::assertSame('pending', $stmt->fetchColumn());
    }
}
