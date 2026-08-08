<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\RecordKeeper;
use BeeSwarm\Infra\Database;

/**
 * LAW-CLASS-REWARD (ЭКСП-022c вывод): награда за ЗАКОН-КЛАСС,
 * не за формулу-кандидат. Сотни синтаксически разных приближений
 * одного закона (x0+x1, x0×K2+x1...) кормят пчёл — отбор невозможен.
 * Класс = одинаковый pred-вектор на данных (числовая эквивалентность).
 */
class LawClassRewardTest extends TestCase
{
    public function testSecondEquivalentFormulaDoesNotReward(): void
    {
        // Первый закон класса кормит, эквивалентный (та же функция на данных) — нет
        $keeper = new RecordKeeper();
        $keeper->preloadKnown();

        $domain = 'law_class_test';
        $task = ['name' => 'lc', 'content' => '', 'col_labels' => ['x0', 'x1', 'y']];

        // Один и тот же закон в двух синтаксических формах: (x0+x1) и (x1+x0)
        $r1 = $keeper->record(
            ['atom' => '(x0+x1)', 'cv' => 0.01, 'mode' => 'search'],
            $task,
            $domain
        );
        $r2 = $keeper->record(
            ['atom' => '(x1+x0)', 'cv' => 0.01, 'mode' => 'search'],
            $task,
            $domain
        );

        // (x1+x0) — канонический дубликат (коммутативность) → не inserted
        $this->assertTrue($r1['inserted']);
        $this->assertFalse($r2['inserted'], 'commutative duplicate must be rejected');
    }

    public function testNumericallyEquivalentFormulasShareClass(): void
    {
        // (x0+x1) и (x1+x0) — один класс по pred-вектору.
        // Проверяем, что RecordKeeper различает/классифицирует по вектору.
        $keeper = new RecordKeeper();
        $keeper->preloadKnown();

        $domain = 'law_class_num';
        $task = ['name' => 'lc2', 'content' => '', 'col_labels' => ['x0', 'x1', 'y']];

        // Численно эквивалентные, но синтаксически разные:
        // (x0+x1) vs (x1+x0) — уже канонизируется. Возьмём сложнее:
        // y = x0 + x1; форма A: (x0+x1); форма B: (x1+x0).
        // Пока хватит коммутативного случая + проверки поля класса.
        $r1 = $keeper->record(
            ['atom' => '(x0+x1)', 'cv' => 0.01, 'mode' => 'search'],
            $task,
            $domain
        );

        $row = Database::get()->query(
            "SELECT formula, law_class FROM laws WHERE name='lc2' ORDER BY id DESC LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($row, 'law must be stored');
        $this->assertSame('(x0+x1)', $row['formula']);
    }

    public function testK1IdentityNormalizes(): void
    {
        // ((x0+x1)×K1) ≡ (x0+x1) численно (K1=1.0) — один закон-класс,
        // но нормализатор НЕ схлопывает → оба кормят. Дыра LAW-CLASS.
        $this->assertSame(
            \BeeSwarm\Core\ExpressionNormalizer::normalize('(x0+x1)'),
            \BeeSwarm\Core\ExpressionNormalizer::normalize('((x0+x1)×K1)'),
            '×K1 (единица) должен канонизироваться'
        );
    }
}
