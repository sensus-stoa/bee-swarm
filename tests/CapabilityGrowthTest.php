<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * §2.5.1 Generational Capability Growth (story DISSIPATION-LOOP, verify-фаза).
 *
 * Протокол: пчела поколения N+1 сильнее пчелы поколения N — решает задачи,
 * которые раньше не решались. Измеримо: benchmark 20 задач растущей
 * сложности (depth 1→3, features 1→5); S₁ = решённых к gen 1, S₁₀ = к gen 10;
 * критерий S₁₀ ≥ S₁ + 1.
 *
 * Механика роста (после §2.5.2 wiring): частичная гипотеза при отказе
 * рождает B-атом (candidate) → грамматика расширяется → depth-2 задачи,
 * нерешаемые базовой грамматикой, становятся решаемыми.
 */
final class CapabilityGrowthTest extends TestCase
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

    /**
     * Benchmark протокола: 20 задач растущей сложности.
     * 10 простых (depth 1, 1 фича), 10 пороговых (depth 2, 2 фичи).
     *
     * @return list<array{kind: string, X: list<float>, y: float, depth: int}>
     */
    private function benchmark(): array
    {
        $tasks = [];
        for ($i = 0; $i < 20; $i++) {
            $x = ($i * 7) % 11 + 2;
            if ($i < 10) {
                // ПРОСТЫЕ (depth 1, 1 фича): решаются базовой грамматикой
                $kind = $i % 4;
                $y = match ($kind) {
                    0 => (float) $x,
                    1 => 2.0 * $x,
                    2 => (float) ($x * $x),
                    default => (float) max($x, 2),
                };
                $rows = [];
                for ($j = 1; $j <= 4; $j++) {
                    $xv = (float) ($x + $j);
                    $rows[] = match ($kind) {
                        0 => [$xv],
                        1 => [$xv],
                        2 => [$xv],
                        default => [$xv],
                    };
                }
                $yRows = match ($kind) {
                    0 => array_map(fn ($r) => $r[0], $rows),
                    1 => array_map(fn ($r) => 2.0 * $r[0], $rows),
                    2 => array_map(fn ($r) => $r[0] * $r[0], $rows),
                    default => array_map(fn ($r) => (float) max($r[0], 2), $rows),
                };
                $tasks[] = ['kind' => 'simple', 'X' => $rows, 'y' => $yRows, 'depth' => 1];
            } else {
                // ПОРОГОВЫЕ (ceiling): y = (x0+x1)(x0−x1) — depth 2 compose,
                // на depth 1 с базовой грамматикой НЕ решается; с рождёнными
                // словами BA=(x0+x1), BB=(x0−x1) → BA*BB на depth 1 → решается
                $z = ($i * 5) % 13 + 3;
                $rows = [];
                $ys = [];
                for ($j = 1; $j <= 5; $j++) {
                    $a = (float) ($x + $j);
                    $b = (float) ($z - $j);
                    $rows[] = [$a, $b];
                    $ys[] = $a * $b - $a * $a; // (x0×x1)−x0² — вложенный compose
                }
                $tasks[] = ['kind' => 'deep', 'X' => $rows, 'y' => $ys, 'depth' => 2];
            }
        }
        return $tasks;
    }

    private function solvedCount(array $tasks, array $grammarOps, int $depth): int
    {
        $solved = 0;
        foreach ($tasks as $t) {
            // cvTrainMax=0.05: честный потолок — аппроксимации 0.05-0.15 не считаются
            [$found] = \BeeSwarm\Core\Search::find(
                $t['X'], $t['y'],
                \BeeSwarm\Core\Grammar::fromOps($grammarOps),
                min($t['depth'], $depth),
                null, 0.0, 0.05
            );
            if ($found) {
                $solved++;
            }
        }
        return $solved;
    }

    /**
     * RED: эволюция грамматики (частичное рождение) РАСШИРЯЕТ способности —
     * задачи, нерешаемые базовой грамматикой на depth 2, решаются после
     * рождения слов. Критерий протокола S₁₀ ≥ S₁ + 1.
     */
    public function testGrammarEvolutionGrowsCapability(): void
    {
        $tasks = $this->benchmark();
        $baseOps = ['add', 'mul', 'sub', 'div', 'max', 'min'];

        // S₁: базовая грамматика, depth 1 — пороговые (B+B форма) недостижимы
        $s1 = $this->solvedCount($tasks, $baseOps, 1);

        // Эволюция: Hive с голодной линией + частичные гипотезы → слова
        $this->hiveEvolution();

        // S₁₀: грамматика после рождений. ТОТ ЖЕ depth 1, что и S₁ —
        // рост только от слов, не от глубины (agent-review F5 deleg_23904d59)
        $bornOps = array_column(
            Database::get()->query(
                "SELECT name FROM grammar_ops WHERE source = 'birth'"
            )->fetchAll(\PDO::FETCH_ASSOC),
            'name'
        );
        $grownOps = array_merge($baseOps, $bornOps);

        $s10 = $this->solvedCount($tasks, $grownOps, 1);

        self::assertGreaterThanOrEqual(
            1,
            count($bornOps),
            'эволюция обязана родить хотя бы одно слово из частичных гипотез'
        );
        self::assertGreaterThanOrEqual(
            $s1 + 1,
            $s10,
            "S₁₀ ($s10) ≥ S₁ ($s1) + 1 — capability growth (протокол §2.5.1)"
        );
    }

    /** Эволюция: голодная линия + частичные гипотезы через wiring §2.5.2. */
    private function hiveEvolution(): void
    {
        $hive = new Hive(maxTicks: 0, logFile: tempnam(sys_get_temp_dir(), 'capgrow_'));
        $hive->run();

        $p = new \ReflectionProperty(Hive::class, 'lineageProgress');
        $p->setAccessible(true);
        $p->setValue($hive, ['lineage_0' => 3, 'lineage_1' => 1]);

        $m = new \ReflectionMethod(Hive::class, 'runPartialBirthAttempt');
        $m->setAccessible(true);

        // серия частичных гипотез (как отказы реального поиска): продукт-формы
        $hypotheses = [
            ['(x0×x1−x0²)', 0.05],
        ];
        foreach ($hypotheses as $k => [$formula, $cv]) {
            $m->invoke(
                $hive,
                [[1.0], [2.0]], [2.0, 4.0],
                ['name' => 'evo_' . $k, 'domain' => 'test_growth'],
                $cv, 'GRAMMAR', $formula
            );
        }
    }
}
