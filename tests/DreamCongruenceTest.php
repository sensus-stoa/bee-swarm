<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Core\ExpressionNormalizer;
use BeeSwarm\Core\Grammar;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * DREAM-CONGRUENCE (follow-up V0.15, 25.09): dream-путь обязан говорить на
 * языке роя и проходить конгруэнтное поглощение/награду как search.
 *
 * Дыра А: recordDiscovery от dream зовётся без X/y и без task['data'] →
 * детектор конгруэнтности инконклюзивен → конгруэнтная dream-форма встаёт
 * второй строкой (двойной учёт открытия).
 * Дыра Б: $newClass остаётся true при X=null → payDiscovery на КАЖДОЕ
 * dream-открытие, включая предсказательные дубли.
 * Нотационная дыра (probe 29.09): discoverCompose пишет функциональную
 * форму 'outer(inner)' (sq(+)) — она НЕ вычисляется ExpressionEvaluator
 * (parse даёт атом-строку) → детектор и LawClassifier мертвы для
 * compose-форм даже при переданных данных.
 */
final class DreamCongruenceTest extends TestCase
{
    private string $logFile;

    private Hive $hive;

    protected function setUp(): void
    {
        Database::reset();
        Database::get();
        putenv('NO_BASE_TASKS=1');
        putenv('FORAGER_SOURCES=:');
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'dream_');
        $this->hive = new Hive(maxTicks: 0, logFile: $this->logFile);
        $this->hive->run();
        $this->setPool([self::taskPlusSq('domD')]);
        $this->injectRoutedBee();
    }

    protected function tearDown(): void
    {
        putenv('NO_BASE_TASKS');
        putenv('FORAGER_SOURCES');
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        Database::setPath(':memory:');
        Database::reset();
    }

    /**
     * Детерминированный домен: y = (x0+x1)^2 (умножение, не pow — eval-паритет).
     *
     * @return list<list<float>>
     */
    private static function rowsPlusSq(float $shift): array
    {
        $rows = [];
        for ($i = 0; $i < 12; ++$i) {
            $x0 = fmod($i * 0.41 + $shift, 40.0);
            $x1 = fmod($i * 1.37 + $shift, 40.0);
            $s = $x0 + $x1;
            $rows[] = [$x0, $x1, $s * $s];
        }

        return $rows;
    }

    /**
     * Детерминированный домен: y = (x0-x1)^2 — инконгруэнтен plus-sq.
     *
     * @return list<list<float>>
     */
    private static function rowsMinusSq(float $shift): array
    {
        $rows = [];
        for ($i = 0; $i < 12; ++$i) {
            $x0 = fmod($i * 0.41 + $shift, 40.0);
            $x1 = fmod($i * 1.37 + $shift, 40.0);
            $d = $x0 - $x1;
            $rows[] = [$x0, $x1, $d * $d];
        }

        return $rows;
    }

    private static function taskPlusSq(string $domain): array
    {
        return ['name' => 'dream_sq', 'domain' => $domain, 'data' => self::rowsPlusSq(0.0)];
    }

    private static function taskMinusSq(string $domain): array
    {
        return ['name' => 'dream_minus', 'domain' => $domain, 'data' => self::rowsMinusSq(0.0)];
    }

    private function setPool(array $tasks): void
    {
        $prop = new \ReflectionProperty(Hive::class, 'foragedTasksGlobal');
        $prop->setAccessible(true);
        $prop->setValue($this->hive, $tasks);
    }

    private function injectRoutedBee(): void
    {
        $bees = new \ReflectionProperty(Hive::class, 'bees');
        $bees->setAccessible(true);
        $all = $bees->getValue($this->hive);
        $first = array_values(array_filter($all, fn ($b) => $b->isAlive()))[0] ?? null;
        self::assertNotNull($first, 'bootstrap дал живую пчелу');
        $rb = new \ReflectionProperty(Hive::class, 'routedBee');
        $rb->setAccessible(true);
        $rb->setValue($this->hive, $first);
    }

    /**
     * Живой dream-тик (ReflectionMethod, прецедент runContradictionCheck).
     */
    private function dreamTick(): void
    {
        $m = new \ReflectionMethod(Hive::class, 'idleDreamTick');
        $m->setAccessible(true);
        $m->invokeArgs($this->hive, []);
    }

    private function insertCarrier(string $domain, string $formula, ?string $classId, ?string $lawClass): void
    {
        Database::get()->prepare(
            'INSERT INTO laws (name, formula, cv, domain, class_id, law_class, class_domain_json, usage_count)
             VALUES (?, ?, 0.01, ?, ?, ?, ?, 1)'
        )->execute([
            'carrier_' . $domain,
            $formula,
            $domain,
            $classId,
            $lawClass,
            json_encode([
                'members' => [$classId ?? $formula],
                'split_at' => null,
                'domain' => ['0' => [0.0, 40.0], '1' => [0.0, 40.0]],
            ]),
        ]);
    }

    private function lawCount(string $domain): int
    {
        $stmt = Database::get()->prepare('SELECT COUNT(*) FROM laws WHERE domain = ?');
        $stmt->execute([$domain]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Дыра А (RED): конгруэнтная dream-форма не встаёт второй строкой.
     * Носитель ((x0+x1)×(x0+x1)) уже в domD; dream находит sq-форму того же
     * класса → поглощение (usage_count+1), а не вторая строка.
     * Сегодня: детектор инконклюзивен (данные не переданы + нотация) →
     * вторая строка → 2 ≠ 1.
     */
    public function testDreamCongruentAbsorbed(): void
    {
        $canonA = ExpressionNormalizer::normalize('((x0+x1)×(x0+x1))');
        $this->insertCarrier('domD', '((x0+x1)×(x0+x1))', $canonA, null);

        $this->dreamTick();

        $count = (int) Database::get()->query(
            "SELECT COUNT(*) FROM laws WHERE domain = 'domD'"
        )->fetchColumn();
        self::assertSame(1, $count, 'конгруэнтная dream-форма не вторая строка');
        $usage = (int) Database::get()->query(
            "SELECT usage_count FROM laws WHERE domain = 'domD'"
        )->fetchColumn();
        self::assertSame(2, $usage, 'поглощение с атрибуцией usage_count');
    }

    /**
     * Дыра Б (RED): конгруэнтный dream-дубль не оплачивается повторно.
     * Носитель уже в domD; конгруэнтная dream-форма → inserted=false →
     * ранняя return ДО payDiscovery → энергия неизменна.
     * НО до моста rewardDiscovery вообще не платит dream-формам
     * (hasFeatures=false на функциональной нотации — probe 10) → тест
     * GREEN по обходному пути и теряет силу ДО моста. После моста
     * (hasFeatures=true) гвард inserted=false становится единственной
     * защитой — тест фиксирует её.
     * Двойной пин: (1) дубль не платит; (2) ЛЕГИТИМНОЕ dream-открытие
     * платит (testDreamIncongruentInsertsAndPays) — пара исключает
     * обходный путь «reward мёртв целиком».
     */
    public function testDreamDuplicateNotPaidTwice(): void
    {
        $canonA = ExpressionNormalizer::normalize('((x0+x1)×(x0+x1))');
        $this->insertCarrier('domD', '((x0+x1)×(x0+x1))', $canonA, null);
        $bee = $this->liveBee();
        $before = $bee->energy();

        $this->dreamTick();

        self::assertSame(0.0, $bee->energy() - $before, 'dream-дубль не оплачивается');
    }

    /**
     * Дыра Б + нотационная дыра (RED): dream-открытие легитимно оплачивается.
     * Инконгруэнтная dream-форма — свой закон И своя награда.
     * Нынешний код: (1) hasFeatures=false на функциональной нотации
     * (rewardDiscovery return без награды — probe 10); (2) $newClass не
     * считается при X=null. Мост + data закрывают обе.
     */
    public function testDreamIncongruentInsertsAndPays(): void
    {
        $this->setPool([self::taskMinusSq('domI')]);
        $this->insertCarrier('domI', '(K2×x0)', '(K2×x0)', 'cls_carrier_domI');
        $bee = $this->liveBee();
        $before = $bee->energy();

        $this->dreamTick();

        $count = (int) Database::get()->query(
            "SELECT COUNT(*) FROM laws WHERE domain = 'domI'"
        )->fetchColumn();
        self::assertSame(2, $count, 'инконгруэнтная форма — свой закон');
        self::assertGreaterThan(0.0, $bee->energy() - $before, 'легитимное dream-открытие оплачивается');
    }

    /**
     * Нотационный пин: compose-открытия вычислимы evaluator'ом (язык роя).
     * Живой dream-путь мостит атом до записи — evaluator видит инфикс.
     */
    public function testComposeAtomsAreInSwarmLanguage(): void
    {
        $rows = self::rowsPlusSq(0.0);
        $X = array_map(static fn (array $r): array => [$r[0], $r[1]], $rows);
        $y = array_column($rows, 2);

        $found = \BeeSwarm\Core\AtomRegistry::discoverCompose($X, $y, Grammar::baseOpNames(), 0.15);

        self::assertNotEmpty($found, 'фикстура обязана давать compose-хит (probe 29.09)');
        foreach ($found as $f) {
            $infix = \BeeSwarm\Core\ComposeBridge::toInfix((string) $f['atom'])
                ?? (string) $f['atom'];
            $vec = ExpressionEvaluator::evaluateFormula($infix, $X);
            self::assertNotNull($vec, "compose-атом вычислим после моста: {$f['atom']} -> $infix");
        }
    }

    private function liveBee(): \BeeSwarm\Hive\Bee
    {
        $rb = new \ReflectionProperty(Hive::class, 'routedBee');
        $rb->setAccessible(true);
        $bee = $rb->getValue($this->hive);
        self::assertNotNull($bee, 'routedBee инжектирован');

        return $bee;
    }
}
