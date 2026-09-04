<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * T5-post-2 (story theorem-level): wiring confirmedLaws в читателей.
 *
 * Культура (Grammar::capped → weightedPick) питается ТОЛЬКО durable-законами
 * (confirmed_count >= 1). Unlucky-seed закон (EXP3 congruence: выборочная
 * корреляция мусора с шумом) не должен влиять на эволюцию роя.
 *
 * Прецедент сид-ловушки: формула с мусорной колонкой принята на seed=99
 * (corr=0.33), на свежих данных corr=-0.31..-0.04. Если capped её читает —
 * рой наследует ошибку через weightedPick.
 */
final class CappedConfirmedWiringTest extends TestCase
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

    private function insertLaw(string $formula, int $usage, int $confirmed, string $domain = 'test_wiring'): void
    {
        Database::get()->prepare(
            'INSERT INTO laws (name,formula,cv,domain,usage_count,confirmed_count)
             VALUES (?,?,0.0,?,?,?)'
        )->execute([$formula, $formula, $domain, $usage, $confirmed]);
    }

    /** RED: capped ВКЛЮЧАЕТ confirmed-закон (durable влияет на культуру). */
    public function testCappedIncludesConfirmedLaw(): void
    {
        $this->insertLaw('(x0×K2)', usage: 1, confirmed: 1);
        $g = new Grammar();
        $capped = $g->capped(50);
        self::assertContains('(x0×K2)', $capped, 'durable закон входит в культуру');
    }

    /** RED: capped НЕ включает unconfirmed-закон (unlucky-seed не влияет). */
    public function testCappedExcludesUnconfirmedLaw(): void
    {
        $this->insertLaw('(x0+K1)', usage: 9, confirmed: 0);
        $g = new Grammar();
        $capped = $g->capped(50);
        self::assertNotContains('(x0+K1)', $capped,
            'unconfirmed закон не влияет на weightedPick (T5-post-2)');
    }

    /** Смешанный случай: только confirmed попадают в топ. */
    public function testCappedTopFromConfirmedOnly(): void
    {
        $this->insertLaw('(x0+K1)', usage: 100, confirmed: 0);  // высокий usage, unlucky
        $this->insertLaw('(x0−K1)', usage: 1, confirmed: 1);    // низкий usage, durable
        $g = new Grammar();
        $top = array_slice(array_diff($g->capped(2), array_keys(Grammar::BASE_OPS)), 0, 2);
        self::assertContains('(x0−K1)', $top, 'durable в топе');
        self::assertNotContains('(x0+K1)', $top, 'unlucky-seed не в топе');
    }

    /** Legacy: у старых законов confirmed_count может быть NULL → трактуются как 0. */
    public function testNullConfirmedTreatedAsUnconfirmed(): void
    {
        Database::get()->exec(
            "INSERT INTO laws (name,formula,cv,domain,usage_count,confirmed_count)
             VALUES ('legacy', '(x0/K1)', 0.0, 'test_wiring', 7, NULL)"
        );
        $g = new Grammar();
        $capped = $g->capped(50);
        self::assertNotContains('(x0/K1)', $capped, 'NULL confirmed = unconfirmed');
    }

    /** Презентация: presentable() возвращает только durable. */
    public function testPresentableOnlyConfirmed(): void
    {
        $this->insertLaw('(x0×K2)', usage: 1, confirmed: 1, domain: 'test_pres');
        $this->insertLaw('(x0+K1)', usage: 5, confirmed: 0, domain: 'test_pres');
        $keeper = new \BeeSwarm\Hive\RecordKeeper();
        $rows = $keeper->presentable('test_pres');
        $formulas = array_column($rows, 'formula');
        self::assertContains('(x0×K2)', $formulas);
        self::assertNotContains('(x0+K1)', $formulas);
    }

    /** Дедуп НЕ меняется: preloadKnown видит ВСЕ законы (вкл. unconfirmed). */
    public function testPreloadKnownSeesAll(): void
    {
        $this->insertLaw('(x0×K2)', usage: 1, confirmed: 1, domain: 'test_dedup');
        $this->insertLaw('(x0+K1)', usage: 5, confirmed: 0, domain: 'test_dedup');
        $keeper = new \BeeSwarm\Hive\RecordKeeper();
        $n = $keeper->preloadKnown();
        self::assertGreaterThanOrEqual(2, $n, 'дедуп видит полный набор');
    }
}
