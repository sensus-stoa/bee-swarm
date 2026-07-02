<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Database;

class LawCompressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Database::get()->exec("DELETE FROM laws WHERE domain = 'test_cmp'");
    }

    // ═══ 1. КЛАСТЕРИЗАЦИЯ ═══

    /** Законы с одинаковым outer-атомом группируются */
    public function test_cluster_by_outer_atom(): void
    {
        $db = Database::get();
        $db->prepare("INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)")->execute(['c1', 'or(+)', 0.0, 'test_cmp']);
        $db->prepare("INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)")->execute(['c2', 'or(−)', 0.0, 'test_cmp']);
        $db->prepare("INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)")->execute(['c3', 'or(sq)', 0.0, 'test_cmp']);
        $db->prepare("INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)")->execute(['c4', 'or(max)', 0.0, 'test_cmp']);
        $db->prepare("INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)")->execute(['c5', 'sq(+)', 0.0, 'test_cmp']);
        $db->prepare("INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)")->execute(['c6', 'sq(−)', 0.0, 'test_cmp']);

        $clusters = $this->clusterByOuter('test_cmp');
        $this->assertArrayHasKey('or', $clusters, 'or cluster');
        $this->assertArrayHasKey('sq', $clusters, 'sq cluster');
        $this->assertEquals(4, $clusters['or'], '4 or-laws');
        $this->assertEquals(2, $clusters['sq'], '2 sq-laws');
    }

    /** Кластер из <3 законов не сжимается */
    public function test_small_cluster_not_compressed(): void
    {
        $db = Database::get();
        $db->prepare("INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)")->execute(['c1', 'abs(sub)', 0.0, 'test_cmp']);
        $db->prepare("INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)")->execute(['c2', 'abs(add)', 0.0, 'test_cmp']);

        $compressed = $this->compressClusters('test_cmp');
        $this->assertEquals(0, $compressed, '2 laws = not enough to compress');
    }

    // ═══ 2. СЖАТИЕ ═══

    /** Кластер из ≥3 законов сжимается в один */
    public function test_compress_cluster(): void
    {
        $db = Database::get();
        for ($i = 0; $i < 5; $i++) {
            $db->prepare("INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)")->execute(["c$i", "or(op$i)", 0.0, 'test_cmp']);
        }

        $countBefore = $db->query("SELECT COUNT(*) FROM laws WHERE domain = 'test_cmp'")->fetchColumn();
        $this->assertEquals(5, $countBefore);

        $compressed = $this->compressClusters('test_cmp');
        $this->assertGreaterThan(0, $compressed, 'Should compress');

        $countAfter = $db->query("SELECT COUNT(*) FROM laws WHERE domain = 'test_cmp'")->fetchColumn();
        $this->assertLessThan($countBefore, $countAfter, 'Laws decreased');
        
        // Проверяем что meta-law создан
        $meta = $db->query("SELECT COUNT(*) FROM laws WHERE domain = 'test_cmp' AND name LIKE 'meta_%'")->fetchColumn();
        $this->assertEquals(1, $meta, 'Meta-law created');
    }

    // ═══ HELPERS ═══

    private function clusterByOuter(string $domain): array
    {
        $db = Database::get();
        $laws = $db->prepare("SELECT formula FROM laws WHERE domain = ? AND formula LIKE '%(%)'");
        $laws->execute([$domain]);
        $rows = $laws->fetchAll(\PDO::FETCH_COLUMN);

        $clusters = [];
        foreach ($rows as $f) {
            if (preg_match('/^(\w+)\(/', $f, $m)) {
                $outer = $m[1];
                $clusters[$outer] = ($clusters[$outer] ?? 0) + 1;
            }
        }
        return $clusters;
    }

    private function compressClusters(string $domain): int
    {
        $clusters = $this->clusterByOuter($domain);
        $compressed = 0;
        $db = Database::get();

        foreach ($clusters as $outer => $count) {
            if ($count >= 3) {
                // Сжать: удалить индивидуальные законы, создать meta-law
                $db->prepare("DELETE FROM laws WHERE domain = ? AND formula LIKE ?")->execute([$domain, "$outer(%"]);
                $db->prepare("INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)")
                   ->execute(["meta_{$outer}", "{$outer}(*) — composes with $count ops", 0.0, $domain]);
                $compressed++;
            }
        }
        return $compressed;
    }
}
