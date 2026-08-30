<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Infra\Database;

/**
 * FLOOR-EMERGENCE M1-wiring (EXP-038): compress() вызывается из Hive::doTick.
 *
 * Периодическая фаза (tick % 100 === 0, как DEAD-CLEANUP): изоморфные
 * законы → BW-атомы. Observability: лог LANGUAGE-COMPRESS read/groups/born.
 *
 * Изоляция: домен test_wlc, BW% чистка в setUp/tearDown.
 */
class HiveLanguageCompressTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::get();
        $db->exec("DELETE FROM laws WHERE domain LIKE 'test_wlc%'");
        $db->exec("DELETE FROM grammar_ops WHERE name LIKE 'BW%'");
    }

    protected function tearDown(): void
    {
        Database::get()->exec("DELETE FROM grammar_ops WHERE name LIKE 'BW%'");
        Database::get()->exec("DELETE FROM laws WHERE domain LIKE 'test_wlc%'");
        parent::tearDown();
    }

    private function addLaw(string $name, string $formula, string $domain): void
    {
        Database::get()->prepare('INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
            ->execute([$name, $formula, 0.001, $domain]);
    }

    public function testCompressRunsOnPeriodicTick(): void
    {
        // Изоморфная пара из двух доменов: периодическая фаза
        // (tick % 100 === 0 || tick === 1) срабатывает на тике 1 —
        // maxTicks:1 = быстрый тест полного пути doTick→compress.
        $this->addLaw('w1', '(x0−x1)', 'test_wlc_a');
        $this->addLaw('w2', '(x3−x2)', 'test_wlc_b');

        $logFile = tempnam(sys_get_temp_dir(), 'wlc_');
        $hive = new \BeeSwarm\Hive\Hive(maxTicks: 1, logFile: $logFile);
        $hive->run();

        $cnt = Database::get()->prepare("SELECT COUNT(*) FROM grammar_ops WHERE name LIKE 'BW%'");
        $cnt->execute();
        $this->assertSame(1, (int) $cnt->fetchColumn(), 'BW atom born by periodic compress');

        $log = (string) file_get_contents($logFile);
        $this->assertStringContainsString('LANGUAGE-COMPRESS', $log, 'observability log line');
        unlink($logFile);
    }

    public function testNoIsoMorphismNoAtom(): void
    {
        // Негативный контроль wiring: законы НЕ изоморфны (разные деревья)
        // и в одном домене — компрессор не рождает ничего, даже на тике 1.
        $this->addLaw('w1', '(x0−x1)', 'test_wlc_a');
        $this->addLaw('w2', '(x0+(x1×x2))', 'test_wlc_a');

        $logFile = tempnam(sys_get_temp_dir(), 'wlc2_');
        $hive = new \BeeSwarm\Hive\Hive(maxTicks: 1, logFile: $logFile);
        $hive->run();

        $cnt = Database::get()->prepare("SELECT COUNT(*) FROM grammar_ops WHERE name LIKE 'BW%'");
        $cnt->execute();
        $this->assertSame(0, (int) $cnt->fetchColumn(), 'non-isomorphic laws birth nothing');
        $log = (string) file_get_contents($logFile);
        $this->assertStringNotContainsString('LANGUAGE-COMPRESS', $log);
        unlink($logFile);
    }
}
