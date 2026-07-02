<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\AtomRegistry;
use BeeSwarm\Grammar;
use BeeSwarm\Database;

class LawVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Чистим законы от предыдущих тестов
        Database::get()->exec("DELETE FROM laws WHERE name LIKE 'test_%' OR domain = 'test'");
    }

    // ═══ 1. ВЕРИФИКАЦИЯ ПОДТВЕРЖДЁННОГО ЗАКОНА ═══

    /** Закон ADD на новых данных должен подтвердиться */
    public function test_verify_true_law(): void
    {
        // Закон: ADD = add(x0,x1)
        $law = ['name' => 'test_ADD', 'formula' => 'add(x0,x1)', 'cv' => 0.0, 'domain' => 'arithmetic'];

        // Новые данные (не из обучающей выборки)
        $newData = [[2.0, 4.0, 6.0], [7.0, 3.0, 10.0], [11.0, 9.0, 20.0]];

        $result = $this->verifyLaw($law, $newData);

        $this->assertTrue($result['verified'], 'True law should verify on new data');
        $this->assertLessThan(0.01, $result['cv'], 'CV should be near 0');
    }

    // ═══ 2. ОПРОВЕРЖЕНИЕ ЛОЖНОГО ЗАКОНА ═══

    /** Закон MUL на данных ADD должен опровергнуться */
    public function test_verify_false_law(): void
    {
        // Закон утверждает что ADD решается через mul (ложь)
        $law = ['name' => 'test_ADD_false', 'formula' => 'mul(x0,x1)', 'cv' => 0.0, 'domain' => 'arithmetic'];

        // Данные ADD
        $newData = [[1.0, 2.0, 3.0], [3.0, 4.0, 7.0], [5.0, 6.0, 11.0]];

        $result = $this->verifyLaw($law, $newData);

        $this->assertFalse($result['verified'], 'False law should fail verification');
        $this->assertGreaterThan(0.1, $result['cv'], 'CV should be high');
    }

    // ═══ 3. ПРУНИНГ ЛОЖНЫХ ЗАКОНОВ ═══

    /** Законы с CV > порог должны удаляться */
    public function test_prune_false_laws(): void
    {
        // Добавляем законы в БД
        $db = Database::get();
        $db->prepare("INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)")
           ->execute(['test_prune_ok', 'add(x0,x1)', 0.0, 'test']);
        $db->prepare("INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)")
           ->execute(['test_prune_bad', 'mul(x0,x1)', 0.0, 'test']);

        $countBefore = $db->query("SELECT COUNT(*) FROM laws WHERE domain = 'test'")->fetchColumn();
        $this->assertEquals(2, $countBefore);

        // Данные ADD
        $newData = [[1.0, 2.0, 3.0], [3.0, 4.0, 7.0], [5.0, 6.0, 11.0]];

        // Верифицируем и пруним
        $pruned = $this->verifyAndPrune('test', $newData, 0.1);

        $countAfter = $db->query("SELECT COUNT(*) FROM laws WHERE domain = 'test'")->fetchColumn();
        $this->assertEquals(1, $countAfter, 'One false law should be pruned');
        $this->assertEquals(1, $pruned, 'Should report 1 pruned law');

        // Уцелевший закон
        $survivor = $db->query("SELECT name FROM laws WHERE domain = 'test'")->fetchColumn();
        $this->assertEquals('test_prune_ok', $survivor);
    }

    // ═══ 4. ГРАНИЧНЫЙ СЛУЧАЙ: неизвестная формула ═══

    /** Неизвестная формула пропускается (не можем проверить) */
    public function test_unknown_formula_skipped(): void
    {
        $law = ['name' => 'test_unknown', 'formula' => 'unknown_op(x0,x1)', 'cv' => 0.0, 'domain' => 'test'];
        $newData = [[1.0, 2.0, 3.0]];

        $result = $this->verifyLaw($law, $newData);
        $this->assertFalse($result['verified'], 'Unknown formula should not verify');
        $this->assertGreaterThan(0.5, $result['cv']);
    }

    // ═══ 5. НЕДОСТАТОЧНО ДАННЫХ ═══

    /** Слишком мало точек — пропускаем */
    public function test_insufficient_data_skipped(): void
    {
        $law = ['name' => 'test_few', 'formula' => 'add(x0,x1)', 'cv' => 0.0, 'domain' => 'test'];
        $newData = [[1.0, 2.0, 3.0]]; // всего 1 точка

        $result = $this->verifyLaw($law, $newData);
        $this->assertFalse($result['verified'], 'Insufficient data should not verify');
        $this->assertEquals('insufficient_data', $result['reason'] ?? '');
    }

    // ═══ HELPERS ═══

    private function verifyLaw(array $law, array $newData): array
    {
        if (count($newData) < 2) {
            return ['verified' => false, 'cv' => 9.99, 'reason' => 'insufficient_data'];
        }

        $formula = $law['formula'];

        // Извлекаем атомы из формулы
        $atoms = $this->parseFormula($formula);
        if (!$atoms) {
            return ['verified' => false, 'cv' => 9.99, 'reason' => 'unparseable'];
        }

        // Применяем формулу к новым данным
        $X = array_map(fn($r) => array_slice($r, 0, -1), $newData);
        $y = array_column($newData, count($newData[0]) - 1);

        $vec = [];
        foreach ($X as $row) {
            $v = $this->applyFormula($atoms, $row);
            if ($v === null || is_nan($v) || is_infinite($v)) {
                return ['verified' => false, 'cv' => 9.99, 'reason' => 'apply_error'];
            }
            $vec[] = $v;
        }

        $cv = AtomRegistry::cv($vec, $y);
        $verified = $cv < 0.1;

        return ['verified' => $verified, 'cv' => $cv];
    }

    private function verifyAndPrune(string $domain, array $newData, float $threshold): int
    {
        $db = Database::get();
        $stmt = $db->prepare("SELECT name, formula, cv, domain FROM laws WHERE domain = ?");
        $stmt->execute([$domain]);
        $laws = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $pruned = 0;
        foreach ($laws as $law) {
            $result = $this->verifyLaw($law, $newData);
            if (!$result['verified'] && $result['cv'] > $threshold) {
                $delStmt = $db->prepare("DELETE FROM laws WHERE name = ? AND domain = ?");
                $delStmt->execute([$law['name'], $domain]);
                $pruned++;
            }
        }

        return $pruned;
    }

    /** Разобрать формулу на цепочку атомов */
    private function parseFormula(string $formula): ?array
    {
        $formula = trim($formula);

        // Простая формула: atom(x0,x1)
        if (preg_match('/^(\w+)\(x0,x1\)$/', $formula, $m)) {
            return ['type' => 'binary', 'outer' => $m[1]];
        }
        // Унарная: atom(x0)
        if (preg_match('/^(\w+)\(x0\)$/', $formula, $m)) {
            return ['type' => 'unary', 'outer' => $m[1]];
        }
        // Compose: outer(inner(x0,x1))
        if (preg_match('/^(\w+)\((\w+)\(x0,x1\)\)$/', $formula, $m)) {
            return ['type' => 'compose', 'outer' => $m[1], 'inner' => $m[2]];
        }
        // Compose unary: outer(inner(x0))
        if (preg_match('/^(\w+)\((\w+)\(x0\)\)$/', $formula, $m)) {
            return ['type' => 'compose_unary', 'outer' => $m[1], 'inner' => $m[2]];
        }

        return null;
    }

    private function applyFormula(array $atoms, array $row): ?float
    {
        if ($atoms['type'] === 'binary') {
            if (count($row) < 2) return null;
            return AtomRegistry::apply($atoms['outer'], (float)$row[0], (float)$row[1]);
        }
        if ($atoms['type'] === 'unary') {
            return AtomRegistry::apply($atoms['outer'], (float)$row[0]);
        }
        if ($atoms['type'] === 'compose') {
            if (count($row) < 2) return null;
            $v1 = AtomRegistry::apply($atoms['inner'], (float)$row[0], (float)$row[1]);
            if ($v1 === null) return null;
            return AtomRegistry::apply($atoms['outer'], $v1);
        }
        if ($atoms['type'] === 'compose_unary') {
            $v1 = AtomRegistry::apply($atoms['inner'], (float)$row[0]);
            if ($v1 === null) return null;
            return AtomRegistry::apply($atoms['outer'], $v1);
        }
        return null;
    }
}
