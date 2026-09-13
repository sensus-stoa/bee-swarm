<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Hive\Bee;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * §2.5.14 AUTOPHAGY: селективная деградация грамматики при голодании.
 *
 * Заменяет случайную HUNGER_MUTATE (panic) на стратегию: наименее ценные
 * атомы (utility = use_count × cv_improvement × cross_domain_count ниже
 * медианы) возвращаются в общий пул (НЕ удаляются), пчела получает
 * ΔE = +0.5 за атом. Стоп при E ≥ E_hunger + buffer = 7.0.
 * Вход: 3.0 ≤ E < 5.0 (SHRINK: E<3 — спячка).
 */
final class AutophagyTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = tempnam(sys_get_temp_dir(), 'autoph_') . '.db';
        Database::setPath($this->dbPath);
        Database::get();
    }

    protected function tearDown(): void
    {
        Database::reset();
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
        Database::setPath(':memory:');
        parent::tearDown();
    }

    /**
     * lawless-атом: нет законов → utility 0 (деградируется первым).
     */
    private function addGrammarOpRow(string $name, int $usage = 1, string $status = 'active'): void
    {
        Database::get()->prepare(
            'INSERT OR IGNORE INTO grammar_ops (name, source, definition, usage_count, status)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$name, 'birth', null, $usage, $status]);
    }

    private function addLaw(string $name, string $formula, float $cv, string $domain): void
    {
        Database::get()->prepare(
            'INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)'
        )->execute([$name, $formula, $cv, $domain]);
    }

    /**
     * Деградация худшего даёт энергию; ценный атом (строил закон cv≈0)
     * сохранён. B1: use=5, закон cv=0.01 → utility 5×0.99×1 = 4.95.
     * B2/B3: беззаконные → utility 0.
     */
    public function testDegradationGainsEnergyAndSkipsValuable(): void
    {
        $this->addGrammarOpRow('B1', 5);
        $this->addGrammarOpRow('B2', 1);
        $this->addGrammarOpRow('B3', 1);
        $this->addLaw('law_b1', '(x0B1x1)', 0.01, 'test_autophagy');

        $bee = new Bee(['+', '×', 'B1', 'B2', 'B3'], 3.5);
        $degraded = $bee->autophagy();

        // Худшие деградированы в детерминированном порядке (utility asc, имя asc)
        $this->assertSame(['B2', 'B3'], $degraded);
        // ΔE = +0.5 за атом
        $this->assertEqualsWithDelta(4.5, $bee->energy(), 0.0001);
        // Ценный атом сохранён, худшие удалены из грамматики пчелы
        $g = $bee->grammar();
        $this->assertContains('B1', $g, 'valuable atom must survive autophagy');
        $this->assertNotContains('B2', $g);
        $this->assertNotContains('B3', $g);
    }

    /** F2 (agent-review): BW-имя компрессора распознаётся как B-атом. */
    public function testBWNamedAtomUtilityRecognized(): void
    {
        // BW-атом С богатым законом (cv=0.0): при правильном токен-матчинге
        // util = 1×1.0×1 = 1.0 → protected; беззаконный B2 деградирует.
        $this->addGrammarOpRow('BW7a7aee', 1);
        $this->addGrammarOpRow('B2', 1);
        $this->addLaw('law_bw', '(x0BW7a7aee(x1))', 0.0, 'test_autophagy');

        $bee = new Bee(['+', 'BW7a7aee', 'B2'], 3.5);
        $degraded = $bee->autophagy();

        $this->assertSame(['B2'], $degraded, 'BW-named atom with a law must be protected (F2)');
        $this->assertContains('BW7a7aee', $bee->grammar());
    }

    /** F1 (agent-review): ВСЕ utility нулевые (int-0/float-0) — деградация работает. */
    public function testAllZeroUtilitiesStillDegrades(): void
    {
        // laws пуста (холодный старт), атомы active: util=0.0 у всех
        $this->addGrammarOpRow('B1', 1);
        $this->addGrammarOpRow('B2', 1);

        $bee = new Bee(['+', 'B1', 'B2'], 3.5);
        $degraded = $bee->autophagy();

        $this->assertNotSame([], $degraded, 'all-zero utilities must not freeze autophagy (F1)');
        $this->assertCount(2, $degraded);
    }

    /** H1/H4 (premortem): candidate-атом (непроверенный frontier) не деградируется. */
    public function testCandidateStatusAtomNotDegraded(): void
    {
        // BCand: source=birth, status=candidate (RCB двухфазность) —
        // exploration-фронтир; B2: active беззаконный — верифицированный мусор.
        $this->addGrammarOpRow('BCand', 1, 'candidate');
        $this->addGrammarOpRow('B2', 1, 'active');

        $bee = new Bee(['+', 'BCand', 'B2'], 3.5);
        $degraded = $bee->autophagy();

        $this->assertSame(['B2'], $degraded, 'candidate (unproven frontier) must be spared');
        $this->assertContains('BCand', $bee->grammar());
    }

    /** Популяционная медиана: переданный агрегат управляет гейтом. */
    public function testPopulationMedianGate(): void
    {
        // B1 с законом cv=0.01 → util 0.99; B2 беззаконный → 0.
        $this->addGrammarOpRow('B1', 1);
        $this->addGrammarOpRow('B2', 1);
        $this->addLaw('law_pop', '(x0B1x1)', 0.01, 'test_autophagy');

        // Высокая популяционная медиана (2.0) — даже B1 ниже медианы.
        // Но протокол: правило беззаконного атома жрёт сначала нулевые.
        // B2 деградирует, потом B1 (0.99 < 2.0) — мед выше своей-медианы-порога.
        $bee = new Bee(['+', 'B1', 'B2'], 3.5);
        $degraded = $bee->autophagy(2.0);
        $this->assertSame(['B2', 'B1'], $degraded, 'population median 2.0 puts B1 below gate');

        // Низкая медиана (0.1): B2 (0) деградирует, B1 (0.99 ≥ 0.1) защищён.
        $this->addGrammarOpRow('B3', 1);
        $bee2 = new Bee(['+', 'B1', 'B3'], 3.5);
        $degraded2 = $bee2->autophagy(0.1);
        $this->assertSame(['B3'], $degraded2);
        $this->assertContains('B1', $bee2->grammar());
    }

    /**
     * Стоп-условие: деградация прекращается при E ≥ E_hunger + buffer (7.0).
     */
    public function testStopsAtHungerBuffer(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->addGrammarOpRow('B' . $i, 1);
        }
        // 7 беззаконных атомов; из E=4.0 хватит 6 деградаций до 7.0
        $grammar = ['+', '×', 'B1', 'B2', 'B3', 'B4', 'B5', 'B6', 'B7'];
        $bee = new Bee($grammar, 4.0);

        $degraded = $bee->autophagy();

        $this->assertCount(6, $degraded, 'stop at E>=7.0: exactly 6 degradations');
        $this->assertEqualsWithDelta(7.0, $bee->energy(), 0.0001);
        $this->assertContains('B7', $bee->grammar(), '7th atom must remain (stop condition)');
    }

    /**
     * Базовые ops никогда не деградируют, даже при полном голоде.
     */
    public function testBaseOpsProtected(): void
    {
        // База + один беззаконный кастомный: деградируется только кастомный
        $bee = new Bee(array_merge(Grammar::baseOpNames(), ['B9']), 3.2);
        $degraded = $bee->autophagy();

        $this->assertSame(['B9'], $degraded);
        foreach (Grammar::baseOpNames() as $base) {
            $this->assertContains($base, $bee->grammar(), "base op {$base} must survive");
        }

        // Пчела с ОДНОЙ базовой грамматикой: деградировать нечего, E не растёт
        $baseOnly = new Bee(['+'], 4.0);
        $this->assertSame([], $baseOnly->autophagy());
        $this->assertEqualsWithDelta(4.0, $baseOnly->energy(), 0.0001);
    }

    /**
     * Случайная мутация УДАЛЕНА: голод не добавляет случайные ops в грамматику.
     */
    public function testNoRandomMutationUnderHunger(): void
    {
        // Старый метод больше не существует (§2.5.14 ЗАМЕНЯЕТ HUNGER_MUTATE)
        $this->assertFalse(
            method_exists(Bee::class, 'hungerMutate'),
            'random HUNGER_MUTATE must be replaced by autophagy'
        );

        // Грамматика при голоде может только УМЕНЬШАТЬСЯ (деградация), не расти
        $bee = new Bee(['+', '×', 'B4'], 4.0);
        $before = count($bee->grammar());
        $bee->autophagy();
        $this->assertLessThanOrEqual(
            $before - 1,
            count($bee->grammar()),
            'autophagy must not ADD random ops (panic behavior removed)'
        );
    }

    /**
     * Анти-осцилляция: деградированный атом не деградируется той же пчелой повторно.
     */
    public function testNoOscillation(): void
    {
        $this->addGrammarOpRow('B1', 1);
        $this->addGrammarOpRow('B2', 1);

        // E=3.5: окно 3.0–5.0 держится после деградаций (иначе тест ловил бы
        // стоп-гейт энергии, а не lifetime-механику)
        $bee = new Bee(['+', 'B1', 'B2'], 3.5);
        $first = $bee->autophagy();
        $this->assertCount(2, $first);
        $eAfterFirst = $bee->energy();

        // Атом вернулся в грамматику (re-discovery из пула) — повторной
        // деградации НЕТ (per bee per atom lifetime)
        $bee->addToGrammar('B1');
        $second = $bee->autophagy();

        $this->assertSame([], $second, 'degraded atom must not degrade twice per bee');
        $this->assertContains('B1', $bee->grammar(), 're-acquired degraded atom stays');
        $this->assertEqualsWithDelta($eAfterFirst, $bee->energy(), 0.0001);
    }

    /**
     * Деградированный атом остаётся в общем пуле и доступен другим пчёлам.
     */
    public function testAtomAvailableToOthers(): void
    {
        Grammar::staticAdd('B5', 'invented', '(x0×x1)');
        $this->addLaw('law_pool', '(x0B5x1)', 0.02, 'other_domain');

        // Второй беззаконный кандидат: инвариант теста = пул-доступность,
        // а не селекция; при одном ценном атоме деградация запрещена
        // (§2.5.14 D/C: removes UNUSED, PRESERVING valuable)
        $this->addGrammarOpRow('B6', 1);

        $bee = new Bee(['+', 'B5', 'B6'], 4.0);
        $degraded = $bee->autophagy();
        $this->assertSame(['B6'], $degraded, 'lawless B6 degrades first; valuable B5 protected');

        // Запись в grammar_ops НЕ удалена (перераспределение, не смерть)
        $row = Database::get()->query(
            "SELECT status FROM grammar_ops WHERE name = 'B5'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, 'atom must remain in grammar_ops pool');
        $this->assertSame('active', $row['status']);

        // Доступен другим пчёлам через cultural weights
        $this->assertArrayHasKey('B5', Grammar::weightsFromDb());
    }

    /**
     * Wiring: живой путь doTick вызывает autophagy (урок §2.5.2 — dead wiring).
     */
    public function testHiveWiringFiresAutophagy(): void
    {
        $this->addGrammarOpRow('B9', 1);
        $log = tempnam(sys_get_temp_dir(), 'autow_');
        $hive = new Hive(maxTicks: 0, logFile: $log);

        $hungry = new Bee(['+', '×', 'B9'], 4.0);
        $ref = new \ReflectionProperty(Hive::class, 'bees');
        $ref->setAccessible(true);
        $ref->setValue($hive, [$hungry]);

        $m = new \ReflectionMethod(Hive::class, 'doTick');
        $m->setAccessible(true);
        $m->invoke($hive);

        $logText = (string) file_get_contents($log);
        $this->assertStringContainsString(
            'AUTOPHAGY',
            $logText,
            'hive tick must wire autophagy for hungry bees'
        );
        $this->assertNotContains('B9', $hungry->grammar());
        // 4.0 − tickCost 0.01 + 0.5 (одна деградация B9)
        $this->assertEqualsWithDelta(4.49, $hungry->energy(), 0.0001);
        unlink($log);
    }
}
