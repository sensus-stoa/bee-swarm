<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Infra\Database;

class ComposePruningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Database::get()->exec("DELETE FROM laws WHERE domain = 'test_comp'");
    }

    // ═══ 1. COMPOSE-АТОМ ДОЛЖЕН ПРОВЕРЯТЬСЯ ═══

    /**
     * Compose-атом подтверждается на корректных данных
     */
    public function testComposeVerifiedOnCorrectData(): void
    {
        // abs(sub) — |x-y|
        $law = [
            'name' => 'test_comp_ok',
            'formula' => 'abs(sub(x0,x1))',
            'cv' => 0.0,
            'domain' => 'test_comp',
        ];
        $newData = [[1.0, 3.0, 2.0], [5.0, 1.0, 4.0], [2.0, 2.0, 0.0]]; // |x-y|

        $result = $this->verify($law, $newData);
        $this->assertTrue($result['verified'], 'Valid compose law should verify');
    }

    /**
     * Ложный compose-атом опровергается
     */
    public function testFalseComposeRejected(): void
    {
        // sq(add) applied to |x-y| data → wrong
        $law = [
            'name' => 'test_comp_bad',
            'formula' => 'sq(add(x0,x1))',
            'cv' => 0.0,
            'domain' => 'test_comp',
        ];
        $newData = [[1.0, 3.0, 2.0], [5.0, 1.0, 4.0], [2.0, 2.0, 0.0]]; // |x-y|

        $result = $this->verify($law, $newData);
        $this->assertFalse($result['verified'], 'False compose law should fail verification');
        $this->assertGreaterThan(0.1, $result['cv']);
    }

    // ═══ 2. ПРУНИНГ COMPOSE-ДОМЕНА ═══

    /**
     * Compose-законы с CV>порог удаляются
     */
    public function testPruneComposeDomain(): void
    {
        $db = Database::get();
        // Добавляем тестовые compose-законы
        $db->prepare('INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
            ->execute(['test_c1', 'abs(sub(x0,x1))', 0.0, 'test_comp']);
        $db->prepare('INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
            ->execute(['test_c2', 'sq(add(x0,x1))', 0.0, 'test_comp']);
        $db->prepare('INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
            ->execute(['test_c3', 'mul(min(x0,x1))', 0.0, 'test_comp']);

        $countBefore = $db->query("SELECT COUNT(*) FROM laws WHERE domain = 'test_comp'")
            ->fetchColumn();
        $this->assertEquals(3, $countBefore);

        // Данные для |x-y|
        $newData = [[1.0, 3.0, 2.0], [5.0, 1.0, 4.0], [2.0, 2.0, 0.0], [3.0, 0.0, 3.0]];

        $pruned = $this->pruneDomain('test_comp', $newData, 0.1);

        $this->assertGreaterThanOrEqual(1, $pruned, 'At least one false law pruned');
        $countAfter = $db->query("SELECT COUNT(*) FROM laws WHERE domain = 'test_comp'")
            ->fetchColumn();
        $this->assertLessThan($countBefore, $countAfter, 'Domain should shrink');
    }

    // ═══ 3. ДЕДУПЛИКАЦИЯ: НЕ ПРОВЕРЯЕМ УЖЕ УДАЛЁННОЕ ═══

    /**
     * Удалённые законы не появляются снова
     */
    public function testPrunedLawsNotRevived(): void
    {
        $db = Database::get();
        $db->prepare('INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
            ->execute(['test_dedup', 'sq(add(x0,x1))', 0.0, 'test_comp']);

        $newData = [[1.0, 3.0, 2.0], [5.0, 1.0, 4.0], [2.0, 2.0, 0.0], [3.0, 0.0, 3.0], [7.0, 2.0, 5.0]];
        $this->pruneDomain('test_comp', $newData, 0.1);

        // Проверяем что удалён
        $count = $db->query("SELECT COUNT(*) FROM laws WHERE domain = 'test_comp'")
            ->fetchColumn();
        $this->assertEquals(0, $count, 'All false laws pruned');
    }

    // ═══ HELPERS ═══

    private function verify(array $law, array $newData): array
    {
        if (count($newData) < 2) {
            return [
                'verified' => false,
                'cv' => 9.99,
            ];
        }
        $formula = $law['formula'];

        // Parse compose: outer(inner(x0,x1))
        if (! preg_match('/^(\w+)\((\w+)\(x0,x1\)\)$/', $formula, $m)) {
            return [
                'verified' => false,
                'cv' => 9.99,
            ];
        }
        $outer = $m[1];
        $inner = $m[2];

        $X = array_map(fn ($r) => array_slice($r, 0, -1), $newData);
        $y = array_column($newData, count($newData[0]) - 1);

        $vec = [];
        foreach ($X as $row) {
            $v1 = AtomRegistry::apply($inner, (float) $row[0], (float) $row[1]);
            if ($v1 === null) {
                return [
                    'verified' => false,
                    'cv' => 9.99,
                ];
            }
            $v2 = AtomRegistry::apply($outer, $v1);
            if ($v2 === null) {
                return [
                    'verified' => false,
                    'cv' => 9.99,
                ];
            }
            $vec[] = $v2;
        }

        $cv = AtomRegistry::cv($vec, $y);
        return [
            'verified' => $cv < 0.1,
            'cv' => $cv,
        ];
    }

    private function pruneDomain(string $domain, array $newData, float $threshold): int
    {
        $db = Database::get();
        $stmt = $db->prepare('SELECT name, formula, cv FROM laws WHERE domain = ?');
        $stmt->execute([$domain]);
        $laws = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $pruned = 0;
        foreach ($laws as $law) {
            $result = $this->verify($law, $newData);
            if (! $result['verified'] && $result['cv'] > $threshold) {
                $db->prepare('DELETE FROM laws WHERE name = ? AND domain = ?')
                    ->execute([$law['name'], $domain]);
                $pruned++;
            }
        }
        return $pruned;
    }
}
