<?php
declare(strict_types=1);

use BeeSwarm\Core\ExpressionNormalizer;
use BeeSwarm\Hive\LawIsomorphismCompressor;
use PHPUnit\Framework\TestCase;

/**
 * EXP-040 M1-EXT (HANDOFF_M1_EXT_SUBTREE.md): слова из суб-деревьев
 * несертифицированных кандидатов одной задачи (intra-task).
 * Pre-reg criteria: dot = x0*x3+x1*x4+x2*x5 depth-3 reachable после
 * рождения BProd-слов; anti-garbage: < MIN_OCCURRENCES вхождений —
 * слово НЕ рождается.
 */
final class SubTreeWordTest extends TestCase
{
    protected function setUp(): void
    {
        $db = \BeeSwarm\Infra\Database::get();
        $db->exec('DELETE FROM grammar_ops');
        $db->exec('DELETE FROM laws');
    }

    private function compressor(): LawIsomorphismCompressor
    {
        return new LawIsomorphismCompressor();
    }

    public function testExtractSubTreesOfProductChain(): void
    {
        // dot-класс: полный кандидат depth-5, но гейт не прошёл
        $cand = '((x0×x3)+(x1×x4))+(x2×x5)';
        $subs = LawIsomorphismCompressor::extractSubTrees($cand, 2);
        self::assertNotEmpty($subs);
        // canonize переименовывает переменные по рангу: x0×x3, x1×x4, x2×x5
        // изоморфны → один canonical (x0×x1)
        $canons = array_map(
            fn ($t) => LawIsomorphismCompressor::canonize($t),
            $subs
        );
        $this->assertContains('(x0×x1)', $canons);
    }

    public function testExtractSkipsTerminalsAndOverDepth(): void
    {
        $subs = LawIsomorphismCompressor::extractSubTrees('(x0×x3)', 2);
        // дерево глубины 1: root = само произведение (единственное структурное
        // под-дерево), терминалы не возвращаются
        $this->assertSame(['(x0×x3)'], $subs);
        // глубже maxDepth не лезем: у атома нет детей
        $subs2 = LawIsomorphismCompressor::extractSubTrees('x0', 2);
        $this->assertSame([], $subs2);
    }

    public function testBirthWordFromCandidateOccurrences(): void
    {
        // 20 сидов dot: кандидаты содержат (x_i×x_j) произведения —
        // изоморфные sub-деревья встречаются ≥3 раз → слово рождается
        $candidates = [
            '((x0×x3)+(x1×x4))+(x2×x5)',
            '((x0×x3)+(x2×x5))+(x1×x4)',
            '(x1×x4)+((x0×x3)+(x2×x5))',
            '((x0×x4)+(x1×x3))+(x2×x5)',  // x1×x3 изоморфно x0×x3
        ];
        $born = LawIsomorphismCompressor::compressCandidateSubTrees(
            $candidates,
            'dot',
            minOccurrences: 3
        );
        $this->assertGreaterThanOrEqual(1, $born, 'BProd-слово должно родиться');
        $db = \BeeSwarm\Infra\Database::get();
        $rows = $db->query(
            "SELECT name, definition, status FROM grammar_ops WHERE source='birth'"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($rows);
        // definition = канонизированное sub-дерево (переменные переименованы)
        $this->assertMatchesRegularExpression('/^[\w(×÷−+\)\(]+$/', $rows[0]['definition']);
    }

    public function testNoBirthBelowMinOccurrences(): void
    {
        // 2 вхождения < 3 — анти-мусорный порог (HANDOFF §4)
        $candidates = [
            '((x0×x3)+(x1×x4))+(x2×x5)',
            '(x0×x3)+x9',
        ];
        $born = LawIsomorphismCompressor::compressCandidateSubTrees(
            $candidates,
            'dot',
            minOccurrences: 3
        );
        $this->assertSame(0, $born);
        $db = \BeeSwarm\Infra\Database::get();
        $n = (int) $db->query(
            "SELECT count(*) c FROM grammar_ops WHERE source='birth'"
        )->fetchColumn();
        $this->assertSame(0, $n);
    }

    public function testWordUsableInGrammarSearch(): void
    {
        // после рождения слова глубина потребная для dot падает:
        // (BW(x0×x3)+BW(x1×x4))+BW(x2×x5) — depth-2 по словам +1
        $candidates = [
            '((x0×x3)+(x1×x4))+(x2×x5)',
            '((x0×x3)+(x1×x4))+(x2×x5)',
            '((x1×x3)+(x0×x4))+(x2×x5)',
        ];
        LawIsomorphismCompressor::compressCandidateSubTrees($candidates, 'dot', minOccurrences: 3);
        $db = \BeeSwarm\Infra\Database::get();
        $ops = $db->query(
            "SELECT name, definition FROM grammar_ops WHERE source='birth' AND status IN ('candidate','active')"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($ops);
        // definition парсится и применимо: evaluate через ExpressionEvaluator
        $def = $ops[0]['definition'];
        $tree = ExpressionNormalizer::parse($def);
        $this->assertNotNull($tree, "definition должен парситься: {$def}");
    }
}
