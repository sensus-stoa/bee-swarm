<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;

/**
 * L2: Детерминизм retrospective validation при рестартах.
 *
 * История: GEN_ задачи были источником mt_rand()-недетерминизма — закрыто
 * RNG-COMPOSE-TASKS (deterministicSeed(42), TaskGenerator:132) +
 * skipGenerated (getTasks(skipGenerated: true) скипает GEN_ целиком).
 * Остаточные подозреваемые (lowpri-bugs.md L2): (а) порядок задач из
 * forager-скана, (б) состав скана.
 *
 * Путь: RESTORE (bee_persistence сеется в setUp) — после P1-RESTORE-ROUTER
 * рестарт проваливается в полный init (ретро выполняется и на RESTORE,
 * раньше пропускалась) — это прод-семантика каждого рестарта демона.
 * Пул: base-задачи (статические данные) + forager-скан фикстур (неизменное
 * FS-состояние) + law 'ADD' для нетривиального ретро-вердикта.
 *
 * :memory: shared: Database::$instance static + SWARM_DB_PATH=:memory: force
 * (criterion-audit L2-а) — оба bootstrap'а читают одну БД, проба валидна.
 *
 * GREEN-сейчас = верификация (L2 закрыт фактом, рефакторинг не нужен).
 * RED = реальный недетерминизм → чинить сортировкой.
 */
final class RetrospectiveDeterminismTest extends TestCase
{
    /** @var list<string> */
    private array $logs = [];

    /** @var list<string> */
    private array $envKeys = ['FORAGER_SOURCES', 'CORPUS_DIRS', 'NO_BASE_TASKS'];

    protected function setUp(): void
    {
        parent::setUp();
        // Forager-скан фикстур: состав входа ретро (неизменное FS-состояние).
        putenv('FORAGER_SOURCES=' . __DIR__ . '/fixtures/forager');
        putenv('CORPUS_DIRS=:'); // corpus off — HOME-скан не часть ретро
        putenv('NO_BASE_TASKS'); // base-задачи ВКЛ (статические данные)

        $db = Database::get();
        $db->exec('DELETE FROM laws');
        $db->prepare('INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
            ->execute(['ADD', 'add', 0, 'arithmetic']);

        // RESTORE-путь: непустая bee_persistence → оба bootstrap'а идут
        // через восстановление популяции + полный init (P1-семантика).
        $db->exec('DELETE FROM bee_persistence');
        $stmt = $db->prepare('INSERT INTO bee_persistence (grammar, energy, is_alive) VALUES (?, ?, 1)');
        foreach (range(1, 2) as $i) {
            $stmt->execute([json_encode(['+', '*']), 10.0]);
        }
        $this->logs = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->envKeys as $key) {
            putenv($key);
        }
        foreach ($this->logs as $log) {
            if (is_file($log)) {
                unlink($log);
            }
        }
        Database::get()->exec('DELETE FROM laws');
        Database::setPath(':memory:');
        Database::reset();
        parent::tearDown();
    }

    public function testRetrospectiveDeterministicAcrossRestarts(): void
    {
        $snap1 = $this->bootstrapAndSnapshot();
        $snap2 = $this->bootstrapAndSnapshot();

        $this->assertStringContainsString('RESTORE:', $snap1['log'], 'Санити: оба прогона идут по RESTORE-пути (прод-семантика рестартов)');
        $this->assertStringContainsString('Retrospective:', $snap1['log'], 'Санити: ретро реально выполнялась на RESTORE-пути');
        $this->assertNotEmpty($snap1['tasks'], 'Санити: состав задач ретро непуст');

        $this->assertSame(
            $snap1['tasks'],
            $snap2['tasks'],
            'Состав/порядок задач ретро идентичен между рестартами (skipGenerated исключает GEN_)'
        );
        $this->assertSame(
            $snap1['retro'],
            $snap2['retro'],
            'Вердикт ретро-валидации идентичен между рестартами'
        );
    }

    /**
     * @return array{tasks: array, retro: string, log: string}
     */
    private function bootstrapAndSnapshot(): array
    {
        $logFile = (string) tempnam(sys_get_temp_dir(), 'l2retro_');
        $this->logs[] = $logFile;

        $hive = new Hive(maxTicks: 0, logFile: $logFile);
        $hive->run();

        $method = new \ReflectionMethod(Hive::class, 'getTasks');
        $method->setAccessible(true);
        $tasks = $method->invoke($hive, true);

        $content = (string) file_get_contents($logFile);
        preg_match('/Retrospective: (\d+) passed, (\d+) overfit removed/', $content, $m);

        return [
            'tasks' => $tasks,
            'retro' => ($m[1] ?? '?') . 'passed/' . ($m[2] ?? '?') . 'overfit',
            'log' => $content,
        ];
    }
}
