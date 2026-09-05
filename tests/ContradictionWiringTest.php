<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * §2.5.3 wiring (story DISSIPATION-LOOP, завершение Phase 6): contradiction
 * detection в живом пути Hive.
 *
 * Критерий протокола: две пчелы нашли РАЗНЫЕ формулы для одной задачи, обе
 * CV ≤ epsExact → записать противоречие (DISSIPATION: event=CONTRADICTION),
 * spawn resolution-задачу. Наблюдатель: discovery не блокируется.
 *
 * Интеграционный тест полного пути recordDiscovery (fingerprint-gap урок):
 * detect() получает кандидатов через живой контракт (atom→formula маппинг).
 */
final class ContradictionWiringTest extends TestCase
{
    private string $logFile;

    private Hive $hive;

    protected function setUp(): void
    {
        Database::reset();
        Database::get();
        $this->logFile = tempnam(sys_get_temp_dir(), 'contr_wire_');
        $this->hive = new Hive(maxTicks: 0, logFile: $this->logFile);
        $this->hive->run();
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        Database::setPath(':memory:');
        Database::reset();
    }

    private function invokeDiscovery(array $d, array $task, string $domain, bool &$foundAny, array $X, array $y): void
    {
        $m = new \ReflectionMethod(Hive::class, 'recordDiscovery');
        $m->setAccessible(true);
        $m->invokeArgs($this->hive, [$d, $task, $domain, &$foundAny, $X, $y]);
    }

    private function lastCandidates(array $formulas): void
    {
        $p = new \ReflectionProperty(Hive::class, 'lastCandidates');
        $p->setAccessible(true);
        $cands = [];
        foreach ($formulas as $i => $f) {
            $cands[] = ['atom' => $f, 'cv' => $i === 0 ? 0.001 : 0.002];
        }
        $p->setValue($this->hive, $cands);
    }

    private function runCheck(array $X, array $y, string $taskName): void
    {
        $m = new \ReflectionMethod(Hive::class, 'runContradictionCheck');
        $m->setAccessible(true);
        $m->invoke($this->hive, ['name' => $taskName], $X, $y);
    }

    /** RED: два exact-кандидата разных формул → DISSIPATION: event=CONTRADICTION. */
    public function testContradictionLoggedOnTwoExactFormulas(): void
    {
        // two candidates: (x0×K2) и (x0+K2) — структурно разные, оба exact
        $this->lastCandidates(['(x0×K2)', '(x0+K2)']);

        $X = [[1.0], [2.0], [3.0]];
        $y = [2.0, 4.0, 6.0];
        $this->runCheck($X, $y, 'cw1');

        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString(
            'event=CONTRADICTION',
            $log,
            'два exact-кандидата разных формул обязаны дать CONTRADICTION'
        );
    }

    /** RED: один кандидат (или два одинаковых) → противоречия нет. */
    public function testNoContradictionWithoutDivergence(): void
    {
        $this->lastCandidates(['(x0×K2)']); // один кандидат

        $X = [[1.0], [2.0], [3.0]];
        $y = [2.0, 4.0, 6.0];
        $this->runCheck($X, $y, 'cw2');

        $log = (string) file_get_contents($this->logFile);
        self::assertStringNotContainsString('event=CONTRADICTION', $log);
    }

    /** Observation-контракт: противоречие не мешает записи закона. */
    public function testDiscoveryNotBlockedByContradiction(): void
    {
        $this->lastCandidates(['(x0×K2)', '(x0+K2)']);
        $foundAny = false;
        $X = [[1.0], [2.0], [3.0]];
        $y = [2.0, 4.0, 6.0];
        $this->invokeDiscovery(
            ['atom' => '(x0×K2)', 'cv' => 0.001, 'class' => 'EMPIRICAL'],
            ['name' => 'cw3', 'domain' => 'test_contra3', 'fingerprint' => 'fp_1'],
            'test_contra3',
            $foundAny,
            $X,
            $y
        );

        self::assertTrue($foundAny, 'закон записан несмотря на противоречие (наблюдатель)');
        $n = Database::get()->query(
            "SELECT COUNT(*) FROM laws WHERE domain = 'test_contra3'"
        )->fetchColumn();
        self::assertSame(1, (int) $n);
    }
}
