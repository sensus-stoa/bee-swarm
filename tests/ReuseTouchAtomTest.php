<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Infra\Database;

/**
 * REUSE-TOUCH-ATOM (10.08, фаза 2, аудит deleg_0518ec3b): reuse
 * регистрируется в ТОЧКЕ ПРИМЕНЕНИЯ (Search::find: формула-победитель с
 * B-именем!), а не подстрочным матчингом при вставке. Имя атома известно
 * в момент применения — не надо угадывать по подстроке (нотация add vs +).
 */
class ReuseTouchAtomTest extends TestCase
{
    public function testFindWinnerWithBAtomRegistersReuse(): void
    {
        // ИЗОЛЯЦИЯ (10.08): :memory: общая для процесса — чужие birth-атомы
        // (B1=(x0addx1) из других тестов) дедупят B9 по definition!
        \BeeSwarm\Infra\Database::get()->exec("DELETE FROM grammar_ops WHERE source = 'birth'");
        Grammar::staticAdd('B9', 'birth', '(x0addx1)', 'foraged_a');
        // PROMOTED заранее: кандидат должен стать active через touchAtom
        $rows = [];
        $h = fopen(__DIR__ . '/fixtures/forager/b_quad.csv', 'r');
        fgetcsv($h);
        while (($r = fgetcsv($h)) !== false) {
            $rows[] = array_map('floatval', $r);
        }
        fclose($h);
        $X = array_map(fn ($r) => [$r[0], $r[1], $r[2]], $rows);
        $y = array_map(fn ($r) => $r[3], $rows);
        $g = Grammar::fromOps(['add', 'sub', 'mul', 'div', 'max', 'min']);
        putenv('SEARCH_BEAM_K=10');

        [$found, , $formula] = Search::find($X, $y, $g, 3, null, 0.2, 0.15);

        $this->assertTrue($found);
        $this->assertStringContainsString('B9', $formula,
            'B-форма должна победить (exact-shortest): ' . $formula);

        // Применение в find → reuse зарегистрирован (SET, идемпотентно)
        $stmt = Database::get()->prepare(
            'SELECT status, reuse_count FROM grammar_ops WHERE name = ?'
        );
        $stmt->execute(['B9']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, 'B9 должен существовать');
        $this->assertSame('active', $row['status'],
            'touchAtom в find: применение → PROMOTED (active)');
        $this->assertGreaterThanOrEqual(1, (int) $row['reuse_count'],
            'touchAtom в find: reuse_count ≥ 1');
    }

    public function testNewDomainIncrementsReuseCount(): void
    {
        // CONCERNS deleg_87be566b: НОВЫЙ домен → инкремент (SET-счётчик
        // уникальных доменов), не только no-op
        Grammar::staticAdd('B7', 'birth', '(x0mulx1)', 'foraged_a');
        Grammar::registerReuse('B7', 'search');
        Grammar::registerReuse('B7', 'foraged_b');

        $stmt = Database::get()->prepare(
            'SELECT reuse_count FROM grammar_ops WHERE name = ?'
        );
        $stmt->execute(['B7']);
        $this->assertSame(2, (int) $stmt->fetchColumn(),
            '2 разных домена = reuse_count 2');
    }

    public function testRepeatedHitSameDomainIsNoOp(): void
    {
        // CONCERNS deleg_71cd0698: повторный хит того же домена НЕ
        // инкрементит reuse_count (SET-семантика, не частотомер!)
        Grammar::staticAdd('B8', 'birth', '(x0subx1)', 'foraged_a');
        Grammar::registerReuse('B8', 'search');
        Grammar::registerReuse('B8', 'search');
        Grammar::registerReuse('B8', 'search');

        $stmt = Database::get()->prepare(
            'SELECT reuse_count, reuse_domains FROM grammar_ops WHERE name = ?'
        );
        $stmt->execute(['B8']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $row['reuse_count'],
            '3 хита одного домена = 1 reuse (SET)');
        $domains = json_decode((string) $row['reuse_domains'], true);
        $this->assertSame(['search'], $domains,
            'домены — SET, без дублей');
    }
}
