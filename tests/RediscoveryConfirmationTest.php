<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\RecordKeeper;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * T5-post (story theorem-level): re-discovery confirmation.
 *
 * Граница T5 (bench congruence_selection, EXP3): одноразовый acceptance
 * уязвим к выборочным корреляциям малых выборок (unlucky seed).
 * Лечение: закон становится DURABLE только после повторного открытия
 * на ДРУГИХ данных (другой task fingerprint). Повтор на тех же данных
# поднимает usage_count, но не confirmed_count.
 *
 * Прецедент seed=99: corr(x1,noise)=0.33 на одной выборке, −0.31..−0.04 на
 * свежих — формула с мусорной колонкой обязана остаться unconfirmed.
 */
final class RediscoveryConfirmationTest extends TestCase
{
    private RecordKeeper $keeper;

    protected function setUp(): void
    {
        Database::reset();
        Database::get(); // миграции
        $this->keeper = new RecordKeeper();
    }

    protected function tearDown(): void
    {
        Database::setPath(':memory:');
        Database::reset();
    }

    private function discovery(string $fingerprint): array
    {
        return [
            'atom' => '(K2×x0)',
            'cv' => 0.01,
            'class' => 'EMPIRICAL',
        ];
    }

    private function task(string $name, string $fingerprint): array
    {
        return ['name' => $name, 'domain' => 'test_conf', 'fingerprint' => $fingerprint];
    }

    private function confirmedCount(string $formula, string $domain): int
    {
        $stmt = Database::get()->prepare(
            'SELECT confirmed_count FROM laws WHERE formula=? AND domain=?'
        );
        $stmt->execute([$formula, $domain]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function usageCount(string $formula, string $domain): int
    {
        $stmt = Database::get()->prepare(
            'SELECT usage_count FROM laws WHERE formula=? AND domain=?'
        );
        $stmt->execute([$formula, $domain]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /** Первое открытие: usage=1, confirmed=0 (не durable). */
    public function testFirstDiscoveryUnconfirmed(): void
    {
        $r = $this->keeper->record(
            $this->discovery('fp_A'),
            $this->task('t1', 'fp_A'),
            'test_conf'
        );
        self::assertTrue($r['inserted']);
        self::assertSame(1, $this->usageCount('(K2×x0)', 'test_conf'));
        self::assertSame(0, $this->confirmedCount('(K2×x0)', 'test_conf'),
            'первое открытие не может быть durable (T5-post)');
    }

    /** Повтор на ТЕХ ЖЕ данных: usage растёт, confirmed НЕ растёт. */
    public function testSameDataRepeatDoesNotConfirm(): void
    {
        $this->keeper->record($this->discovery('fp_A'), $this->task('t1', 'fp_A'), 'test_conf');
        $this->keeper->record($this->discovery('fp_A'), $this->task('t2', 'fp_A'), 'test_conf');
        $this->keeper->record($this->discovery('fp_A'), $this->task('t3', 'fp_A'), 'test_conf');

        self::assertSame(3, $this->usageCount('(K2×x0)', 'test_conf'));
        self::assertSame(0, $this->confirmedCount('(K2×x0)', 'test_conf'),
            'повтор на тех же данных — не подтверждение (unlucky-seed защита)');
    }

    /** Повтор на ДРУГИХ данных: confirmed=1 → durable. */
    public function testNewDataRepeatConfirms(): void
    {
        $this->keeper->record($this->discovery('fp_A'), $this->task('t1', 'fp_A'), 'test_conf');
        $this->keeper->record($this->discovery('fp_B'), $this->task('t2', 'fp_B'), 'test_conf');

        self::assertSame(2, $this->usageCount('(K2×x0)', 'test_conf'));
        self::assertSame(1, $this->confirmedCount('(K2×x0)', 'test_conf'),
            'переоткрытие на новых данных = подтверждение (durable)');
    }

    /** Durable-гейт: confirmedLaws() возвращает только подтверждённые. */
    public function testConfirmedLawsFiltersUnconfirmed(): void
    {
        $this->keeper->record($this->discovery('fp_A'), $this->task('t1', 'fp_A'), 'test_conf');
        $this->keeper->record($this->discovery('fp_A'), $this->task('t2', 'fp_A'), 'test_conf');
        $this->keeper->record($this->discovery('fp_B'), $this->task('t3', 'fp_B'), 'test_conf');
        $this->keeper->record($this->discovery('fp_C'), $this->task('t4', 'fp_C'), 'test_conf');
        // 3 раза fp_A (usage=3, confirmed=0), по 1 разу fp_B/fp_C с разными fingerprint
        $this->keeper->record($this->discovery('fp_D'), $this->task('t5', 'fp_D'), 'test_conf');
        // fp_B: usage=2, confirmed=1

        // fp_A: usage=3 confirmed=0; fp_B: usage=2 confirmed=1; fp_C: usage=1 confirmed=0
        $this->keeper->record($this->discovery('fp_B'), $this->task('t5', 'fp_B2'), 'test_conf');
        // fp_B подтверждён второй раз (разные fingerprint)
        $this->keeper->record($this->discovery('fp_A'), $this->task('t6', 'fp_A'), 'test_conf');
        // fp_A снова те же данные — confirmed остаётся 0

        $durable = $this->keeper->confirmedLaws('test_conf');
        self::assertCount(1, $durable, 'только переоткрытые на разных данных законы durable');
        self::assertSame('(K2×x0)', $durable[0]['formula'] ?? '');
        self::assertGreaterThan(1, (int) ($durable[0]['confirmed_count'] ?? 0), 'confirmed>=1');
    }
}
