<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Infra\Database;

/**
 * REUSE-CRITERION-BIRTH (10.08, фаза 1-2): двухфазное рождение B-атомов.
 * discovery → status='candidate' (в bornBinary, но в хвосте); reuse≥1 →
 * status='active' (PROMOTED — приоритет в bornBinary). Слово становится
 * частью языка, когда его используют.
 */
class ReuseCriterionBirthTest extends TestCase
{
    private function statusOf(string $name): ?string
    {
        $v = Database::get()->prepare('SELECT status FROM grammar_ops WHERE name = ?');
        $v->execute([$name]);
        $s = $v->fetchColumn();

        return $s === false ? null : $s;
    }

    public function testBirthCreatesCandidate(): void
    {
        Grammar::staticAdd('BC1', 'birth', '(x0addx1)', 'foraged_test');
        $this->assertSame('candidate', $this->statusOf('BC1'),
            'новый B-атом рождается как кандидат (двухфазное рождение)');
    }

    public function testLegacyAtomsAreActive(): void
    {
        // Старые атомы (до двухфазности) — активные (backward-compat)
        Database::get()->prepare(
            'INSERT OR IGNORE INTO grammar_ops (name, source, definition, birth_domain) VALUES (?, ?, ?, ?)'
        )->execute(['BC2', 'birth', '(x0subx1)', 'foraged_test']);
        $this->assertSame('active', $this->statusOf('BC2'),
            'атомы без status (легаси) — active по умолчанию');
    }

    public function testReusePromotesToActive(): void
    {
        Grammar::staticAdd('BC3', 'birth', '(x0mulx1)', 'foraged_test');
        $this->assertSame('candidate', $this->statusOf('BC3'));
        Grammar::registerReuse('BC3', 'foraged_b');
        $this->assertSame('active', $this->statusOf('BC3'),
            'reuse≥1 → PROMOTED → status=active');
    }
}
