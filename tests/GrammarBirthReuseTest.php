<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;

/**
 * REUSE-TRACKING (разбор 06.08): таблица
 * Operator | Birth Domain | Reuse Count | Domains Reused.
 * B-атом должен фиксировать домен рождения и каждый reuse (домен + счётчик).
 */
class GrammarBirthReuseTest extends TestCase
{

    protected function tearDown(): void
    {
        // GRAMMAR-BIRTH: не засорять общую :memory: БД — иначе
        // последующие Search-тесты перебирают B-атомы (лавина)
        \BeeSwarm\Infra\Database::get()->exec("DELETE FROM grammar_ops WHERE source = 'birth'");
        parent::tearDown();
    }

    public function testBirthRecordsDomain(): void
    {
        $g = new Grammar();
        $g->add('Btest1', 'birth', '((x0+x1))', 'physics');

        $row = $this->fetchBirth('Btest1');
        $this->assertEquals('physics', $row['birth_domain'] ?? null,
            'birth domain must be recorded');
        $this->assertEquals(0, (int) ($row['reuse_count'] ?? 0),
            'reuse starts at 0');
    }

    public function testReuseIncrementsAndTracksDomains(): void
    {
        $g = new Grammar();
        $g->add('Btest2', 'birth', '((x0+x1))', 'physics');

        // Reuse в физике (повторно) и в биологии
        $g->registerReuse('Btest2', 'physics');
        $g->registerReuse('Btest2', 'biology');

        $row = $this->fetchBirth('Btest2');
        $this->assertEquals(2, (int) $row['reuse_count'], 'reuse count must be 2');
        $domains = json_decode($row['reuse_domains'] ?? '[]', true);
        $this->assertContains('physics', $domains);
        $this->assertContains('biology', $domains);
    }

    private function fetchBirth(string $name): array
    {
        $db = \BeeSwarm\Infra\Database::get();
        $stmt = $db->prepare(
            'SELECT birth_domain, reuse_count, reuse_domains FROM grammar_ops WHERE name = ?'
        );
        $stmt->execute([$name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, "born atom {$name} must exist");
        return $row;
    }
}
