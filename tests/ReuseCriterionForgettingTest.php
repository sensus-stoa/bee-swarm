<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Infra\Database;

/**
 * REUSE-CRITERION-BIRTH фаза 3 (10.08): ЗАБВЕНИЕ кандидатов.
 * Кандидат без reuse за N тиков удаляется; активные (reuse>0) — НЕ
 * забываются («нельзя разучиться ездить на велосипеде» — процедурная
 * память). Забвение — ТОЛЬКО кандидатов.
 */
class ReuseCriterionForgettingTest extends TestCase
{
    public function testOldCandidateIsForgotten(): void
    {
        Grammar::staticAdd('FC1', 'birth', '(x0addx1)', 'foraged_test');
        // Синтетически состарим кандидата (invented_at в прошлом)
        Database::get()->prepare(
            "UPDATE grammar_ops SET invented_at = datetime('now', '-10 days') WHERE name = 'FC1'"
        )->execute();

        $hive = new \BeeSwarm\Hive\Hive(
            new \BeeSwarm\Infra\PlateauDetector(50, 0), null,
            maxTicks: 1, logFile: tempnam(sys_get_temp_dir(), 'fgt_')
        );
        $hive->run();

        $exists = Database::get()->prepare('SELECT COUNT(*) FROM grammar_ops WHERE name = ?');
        $exists->execute(['FC1']);
        $this->assertSame(0, (int) $exists->fetchColumn(),
            'кандидат без reuse за порог тиков удаляется (забвение)');
    }

    public function testFreshCandidateIsNotForgotten(): void
    {
        // CONCERNS deleg_b2807bff: свежий кандидат (0 часов) не тронут
        Grammar::staticAdd('FC2', 'birth', '(x0addx1)', 'foraged_test');

        $hive = new \BeeSwarm\Hive\Hive(
            new \BeeSwarm\Infra\PlateauDetector(50, 0), null,
            maxTicks: 1, logFile: tempnam(sys_get_temp_dir(), 'fgt_')
        );
        $hive->run();

        $exists = Database::get()->prepare('SELECT COUNT(*) FROM grammar_ops WHERE name = ?');
        $exists->execute(['FC2']);
        $this->assertSame(1, (int) $exists->fetchColumn(),
            'свежий кандидат (0 часов) не удаляется забвением');
    }

    public function testActiveAtomIsNotForgotten(): void
    {
        Grammar::staticAdd('FA1', 'birth', '(x0mulx1)', 'foraged_test');
        Grammar::registerReuse('FA1', 'foraged_b'); // → active
        Database::get()->prepare(
            "UPDATE grammar_ops SET invented_at = datetime('now', '-10 days') WHERE name = 'FA1'"
        )->execute();

        $hive = new \BeeSwarm\Hive\Hive(
            new \BeeSwarm\Infra\PlateauDetector(50, 0), null,
            maxTicks: 1, logFile: tempnam(sys_get_temp_dir(), 'fgt_')
        );
        $hive->run();

        $exists = Database::get()->prepare('SELECT COUNT(*) FROM grammar_ops WHERE name = ?');
        $exists->execute(['FA1']);
        $this->assertSame(1, (int) $exists->fetchColumn(),
            'активные атомы (reuse>0) не забываются — процедурная память');
    }
}
