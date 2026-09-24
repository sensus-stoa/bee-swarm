<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Infra\Database;

/**
 * RETRO-QUARANTINE (24.09, premortem И-1): ретро-валидация на каждом
 * рестарте (после P1-фикса — и на RESTORE) сверяет законы с текущим
 * срезом задач; дрейф корпуса → RETRO_OVERFIT → DELETE FROM laws
 * НЕОБРАТИМ, единственный след — строки лога. Фикс: удаление → перенос в
 * laws_quarantine (полная строка + quarantined_at + removed_ratio),
 * метрика removed_ratio за проход, алерт при > ALERT_RATIO.
 *
 * Overfit-фикстура — доказанный паттерн RetrospectiveDataTest (abs на
 * identity-данных с выбросом: train безупречен, holdout ломается).
 * Hive-уровневый drift-тест — контракт ВЫЖИВАНИЯ base-законов при
 * мутации FORAGER_SOURCES + отсутствие тихих удалений (overfit-механика
 * на статических base-данных невоспроизводима честно — clean data не
 * порождает overfit; это дизайн, не дыра теста).
 */
final class RetroQuarantineTest extends TestCase
{
    /** @var list<string> */
    private array $envKeys = ['FORAGER_SOURCES', 'CORPUS_DIRS', 'NO_BASE_TASKS'];

    /** @var list<string> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::get();
        $db->exec('DELETE FROM laws');
        $db->exec('DELETE FROM laws_quarantine');
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
        Database::get()->exec('DELETE FROM laws_quarantine');
        Database::setPath(':memory:');
        Database::reset();
        parent::tearDown();
    }

    /**
     * Overfit-закон ПЕРЕНОСИТСЯ в laws_quarantine (полная строка), а не
     * удаляется молча. Красный сейчас: таблицы laws_quarantine нет →
     * PDOException; и DELETE без следа.
     */
    public function testOverfitLawMovedToQuarantineNotDeleted(): void
    {
        $db = Database::get();
        $db->prepare('INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
            ->execute(['TEST_RETRO_GARBAGE', 'abs', 0, 'arithmetic']);

        $tasks = [
            [
                'name' => 'TEST_RETRO_GARBAGE',
                // train (0..4) identity: abs идеален (cv_train=0);
                // holdout содержит -1: abs(-1)=1, y=-1 → cv_holdout >> 0.10
                'data' => [[0, 0], [1, 1], [2, 2], [3, 3], [4, 4], [5, 5], [-1, -1]],
                'domain' => 'arithmetic',
            ],
        ];

        $result = AtomRegistry::retrospectiveValidate($tasks);

        $this->assertContains('TEST_RETRO_GARBAGE::abs', $result['overfit'], 'санити: закон определён как overfit');

        $inLaws = $db->query("SELECT COUNT(*) FROM laws WHERE name='TEST_RETRO_GARBAGE'")->fetchColumn();
        $this->assertSame(0, (int) $inLaws, 'overfit-закон покидает laws');

        $q = $db->query("SELECT name, formula, domain, quarantined_at FROM laws_quarantine WHERE name='TEST_RETRO_GARBAGE'")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $q, 'overfit-закон присутствует в laws_quarantine ровно одной строкой');
        $this->assertSame('abs', $q[0]['formula'], 'кварантинная строка несёт формулу (восстановимо)');
        $this->assertNotSame('', (string) $q[0]['quarantined_at'], 'кварантинная строка снабжена временем');
    }

    /**
     * Метрика removed_ratio за проход + алерт: 1 overfit из 2 законов →
     * 0.5 > ALERT_RATIO → alert=true; чистый проход → 0.0, alert=false.
     */
    public function testRemovedRatioComputedAndAlerted(): void
    {
        $db = Database::get();
        $db->prepare('INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
            ->execute(['TEST_RETRO_GARBAGE', 'abs', 0, 'arithmetic']);
        $db->prepare('INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
            ->execute(['TEST_RETRO_OK', 'add', 0, 'arithmetic']);

        $garbageTask = [
            'name' => 'TEST_RETRO_GARBAGE',
            'data' => [[0, 0], [1, 1], [2, 2], [3, 3], [4, 4], [5, 5], [-1, -1]],
            'domain' => 'arithmetic',
        ];
        $okTask = [
            'name' => 'TEST_RETRO_OK',
            'data' => [[1, 2, 3], [3, 4, 7], [5, 6, 11], [7, 8, 15], [9, 10, 19], [11, 12, 23]],
            'domain' => 'arithmetic',
        ];

        $result = AtomRegistry::retrospectiveValidate([$garbageTask, $okTask]);

        $this->assertSame(0.5, $result['removed_ratio'], 'ratio = overfit / все законы прохода');
        $this->assertTrue($result['alert'], '0.5 > ALERT_RATIO → алерт за проход');
    }

    public function testCleanPassNoAlert(): void
    {
        $db = Database::get();
        $db->prepare('INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
            ->execute(['TEST_RETRO_OK', 'add', 0, 'arithmetic']);

        $okTask = [
            'name' => 'TEST_RETRO_OK',
            'data' => [[1, 2, 3], [3, 4, 7], [5, 6, 11], [7, 8, 15], [9, 10, 19], [11, 12, 23]],
            'domain' => 'arithmetic',
        ];

        $result = AtomRegistry::retrospectiveValidate([$okTask]);

        $this->assertSame(0.0, $result['removed_ratio']);
        $this->assertFalse($result['alert'], 'чистый проход не алертит');
    }

    /**
     * Идемпотентность рестартов: закон уже в карантине → второй проход его
     * не пере-оценивает (в laws его нет) → дублей строк карантина нет.
     */
    public function testQuarantineIdempotentAcrossRestarts(): void
    {
        $db = Database::get();
        $db->prepare('INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
            ->execute(['TEST_RETRO_GARBAGE', 'abs', 0, 'arithmetic']);

        $garbageTask = [
            'name' => 'TEST_RETRO_GARBAGE',
            'data' => [[0, 0], [1, 1], [2, 2], [3, 3], [4, 4], [5, 5], [-1, -1]],
            'domain' => 'arithmetic',
        ];

        AtomRegistry::retrospectiveValidate([$garbageTask]);
        AtomRegistry::retrospectiveValidate([$garbageTask]); // рестарт #2

        $count = (int) $db->query("SELECT COUNT(*) FROM laws_quarantine WHERE name='TEST_RETRO_GARBAGE'")->fetchColumn();
        $this->assertSame(1, $count, 'повторная ретро не плодит дубли в карантине');
    }

    /**
     * Drift-тест (Hive, контракт выживания): base-закон переживает мутацию
     * FORAGER_SOURCES между рестартами; никаких тихих удалений (карантин
     * пуст для выживших, ratio=0 → нет алерта).
     */
    public function testBaseLawSurvivesForagerCorpusMutation(): void
    {
        $db = Database::get();
        $db->prepare('INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
            ->execute(['ADD', 'add', 0, 'arithmetic']);
        // закон без совпадающего таска: ретро его скипает (не удаляет) —
        // тоже форма «пережить рестарт при дрейфе»
        $db->prepare('INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
            ->execute(['TEST_ORPHAN', 'abs', 0, 'arithmetic']);

        putenv('FORAGER_SOURCES=' . __DIR__ . '/fixtures/forager');
        putenv('CORPUS_DIRS=:');
        putenv('NO_BASE_TASKS'); // base ВКЛ (закон ADD обязан матчиться)

        $log1 = (string) tempnam(sys_get_temp_dir(), 'rq_a_');
        $this->logs[] = $log1;
        (new \BeeSwarm\Hive\Hive(maxTicks: 0, logFile: $log1))->run();

        // Мутация корпуса: временная директория с изменённым CSV
        $driftDir = sys_get_temp_dir() . '/rq_drift_' . uniqid();
        mkdir($driftDir);
        copy(__DIR__ . '/fixtures/forager/add.csv', $driftDir . '/add.csv');
        file_put_contents(
            $driftDir . '/drift.csv',
            "x,y\n1,999\n2,1\n3,4\n4,9\n5,16\n6,25\n7,999\n8,49\n9,64\n10,999\n11,100\n12,121\n13,144\n14,169\n15,196\n"
        );
        putenv('FORAGER_SOURCES=' . $driftDir);

        $log2 = (string) tempnam(sys_get_temp_dir(), 'rq_b_');
        $this->logs[] = $log2;
        (new \BeeSwarm\Hive\Hive(maxTicks: 0, logFile: $log2))->run();

        $this->exec("rm -rf " . escapeshellarg($driftDir));

        $addInLaws = (int) $db->query("SELECT COUNT(*) FROM laws WHERE name='ADD' AND formula='add'")->fetchColumn();
        $orphanInLaws = (int) $db->query("SELECT COUNT(*) FROM laws WHERE name='TEST_ORPHAN'")->fetchColumn();
        $quarantined = (int) $db->query('SELECT COUNT(*) FROM laws_quarantine')->fetchColumn();

        $this->assertSame(1, $addInLaws, 'base-закон ADD пережил рестарт на мутировавшем корпусе');
        $this->assertSame(1, $orphanInLaws, 'орфан-закон пережил рестарт (skip ≠ удаление)');
        $this->assertSame(0, $quarantined, 'дрейф корпуса не удалил ни одного закона → карантин пуст');
        $this->assertStringNotContainsString('RETRO_QUARANTINE_ALERT', (string) file_get_contents($log2), 'ratio=0 → алерта нет');
    }

    private function exec(string $cmd): void
    {
        shell_exec($cmd);
    }
}
