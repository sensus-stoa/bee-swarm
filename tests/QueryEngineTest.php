<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\QueryEngine;
use BeeSwarm\Infra\Database;

/**
 * Story S0-QUERY: Structured System Query (Theo-Conjecture T3)
 */
class QueryEngineTest extends TestCase
{
    private QueryEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new QueryEngine();
    }

    /** lawsByDomain возвращает законы для указанного домена */
    public function testLawsByDomain(): void
    {
        // S1.10: :memory: БД пустая — self-seed вместо накопленного мусора
        Database::get()->exec("INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES ('ARITH_LAW', 'x+y', 0, 'arithmetic')");

        $laws = $this->engine->lawsByDomain('arithmetic');
        $this->assertIsArray($laws);
        $this->assertNotEmpty($laws, 'Must have arithmetic laws in test DB');
        $this->assertArrayHasKey('name', $laws[0]);
        $this->assertArrayHasKey('formula', $laws[0]);
        $this->assertArrayHasKey('cv', $laws[0]);

        // Cleanup (конвенция: тест не оставляет мусор в БД)
        Database::get()->exec("DELETE FROM laws WHERE name = 'ARITH_LAW'");
    }

    /** topAtoms возвращает наиболее часто используемые атомы */
    public function testTopAtoms(): void
    {
        $atoms = $this->engine->topAtoms(5);
        $this->assertIsArray($atoms);
        $this->assertLessThanOrEqual(5, count($atoms));
    }

    /** systemHealth возвращает агрегированное состояние */
    public function testSystemHealth(): void
    {
        $health = $this->engine->systemHealth();
        $this->assertIsArray($health);
        $this->assertArrayHasKey('total_laws', $health);
        $this->assertArrayHasKey('active_domains', $health);
    }

    /** queryReadOnly блокирует write-операции */
    public function testReadOnlyBlocksWrite(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->engine->query("INSERT INTO laws (name) VALUES ('test')");
    }

    /** REPLACE INTO тоже блокируется */
    public function testReplaceBlocked(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->engine->query("REPLACE INTO laws (name, formula) VALUES ('x', 'y')");
    }

    /** SELECT без write-ключей проходит свободно */
    public function testSelectPasses(): void
    {
        $result = $this->engine->query("SELECT COUNT(*) as cnt FROM laws");
        $this->assertIsArray($result);
        $this->assertArrayHasKey('cnt', $result[0]);
    }
}
