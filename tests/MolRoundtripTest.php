<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Core\ExpressionNormalizer;
use BeeSwarm\Infra\Database;

/**
 * MOL-001: semantic roundtrip вложенных BW-операторов.
 *
 * R1 parse(render(AST)) ≡ AST (структурно, с канонизацией)
 * R2 T₂ из PROBE-1 вычисляет ту же функцию, что исходный закон
 * R3 variable hygiene: все ссылки через формальный список аргументов
 * R4 clean DB: обе зависимости → вычисление; без T₁ → отказ (отличим
 *    от parse-ошибки)
 * R6 fingerprint ненnested-форм не меняется
 *
 * Реальные артефакты PROBE-1:
 *   T₁ = BW5737967f... = ((x0+x1)/(x0−x1))
 *   T₂ = BW59baeae1... = ((x0−x1)+x0BW_T₁x1)  [nested]
 *   закон D₂ = T₁(c0,c1) + (c0−c2)
 */
class MolRoundtripTest extends TestCase
{
    private const T1_NAME = 'BW5737967f9b4ecb628fd48d7d82502f40';
    private const T1_DEF = '((x0+x1)/(x0−x1))';
    private const T2_NAME = 'BW59baeae13abe8cbdfae0c823ff207db2';
    // КОРРЕКТНЫЙ канон T₂ (post-MOL-001): слоты молекулы НЕ переименовываются
    // (до фикса renameTerminals по рангу появления портил (x0−x2) → (x0−x1),
    // т.к. парсер не видел слоты внутри склеенного атома)
    private const T2_DEF = '((x0−x2)+x0BW5737967f9b4ecb628fd48d7d82502f40x1)';
    // ИСКАЖЁННЫЙ канон (pre-fix артефакт PROBE-1) — для теста ремонта
    private const T2_DEF_CORRUPTED = '((x0−x1)+x0BW5737967f9b4ecb628fd48d7d82502f40x1)';
    private const LAW_X = '((x0BW5737967f9b4ecb628fd48d7d82502f40x1)+(x0−x2))';

    private array $birthNames;

    protected function setUp(): void
    {
        $this->birthNames = [self::T1_NAME, self::T2_NAME];
    }

    protected function tearDown(): void
    {
        // Атомы эксперимента не должны переживать тест в общей suite-БД:
        // bornBinary читает grammar_ops НАПРЯМУЮ (мимо restrictTo) —
        // остаточный T₁ делал «невыразимые» законы выразимыми у соседей
        // (SelfDiagnosis GRAMMAR-фейл при параллельном прогоне)
        try {
            $db = Database::get();
            $db->prepare('DELETE FROM grammar_ops WHERE name IN (?, ?)')
                ->execute([self::T1_NAME, self::T2_NAME]);
        } catch (\Throwable) {
            // R4-тесты подменяли БД — восстановление уже сделано в finally
        }
        parent::tearDown();
    }

    /** Независимые входы (3 колонки достаточно: x0,x1,x2). */
    private function inputs(int $n = 12): array
    {
        mt_srand(4242);
        $X = [];
        for ($i = 0; $i < $n; $i++) {
            $X[] = [mt_rand(10, 30) / 10, mt_rand(40, 60) / 10, mt_rand(30, 70) / 10];
        }

        return $X;
    }

    private function lawValues(array $X): array
    {
        $out = [];
        foreach ($X as $row) {
            $out[] = ($row[0] + $row[1]) / ($row[0] - $row[1]) + ($row[0] - $row[2]);
        }

        return $out;
    }

    // ── R1: AST roundtrip ───────────────────────────────────────────

    public function testR1RoundtripNestedBareForm(): void
    {
        $ast1 = ExpressionNormalizer::parse('x0' . self::T1_NAME . 'x1', $this->birthNames);
        $this->assertNotNull($ast1);
        $this->assertSame(self::T1_NAME, $ast1['op'] ?? null, 'склеенная B-форма разбирается в application-узел');
        $rendered = ExpressionNormalizer::render($ast1);
        $ast2 = ExpressionNormalizer::parse($rendered, $this->birthNames);
        $this->assertSame(json_encode($ast1), json_encode($ast2), 'parse(render(AST)) ≡ AST');
    }

    public function testR1RoundtripRealT2Definition(): void
    {
        $ast1 = ExpressionNormalizer::parse(self::T2_DEF, $this->birthNames);
        $rendered = ExpressionNormalizer::render($ast1);
        $ast2 = ExpressionNormalizer::parse($rendered, $this->birthNames);
        $this->assertSame(json_encode($ast1), json_encode($ast2));
        // вложенный T₁-токен остался оператором (не склеился)
        $this->assertStringContainsString(self::T1_NAME, $rendered);
    }

    public function testR1RoundtripMultiLevelNesting(): void
    {
        // многоуровневая вложенность: применение T₂ (def которого содержит T₁)
        // слева и справа от внешнего оператора
        $expr = '(x0' . self::T2_NAME . 'x1)+(x1' . self::T1_NAME . 'x2)';
        $ast1 = ExpressionNormalizer::parse($expr, $this->birthNames);
        $rendered = ExpressionNormalizer::render($ast1);
        $ast2 = ExpressionNormalizer::parse($rendered, $this->birthNames);
        $this->assertSame(json_encode($ast1), json_encode($ast2));
        $this->assertStringContainsString(self::T2_NAME, $rendered, 'T₂-токен на месте');
        $this->assertStringContainsString(self::T1_NAME, $rendered, 'T₁-токен на месте');
    }

    public function testR1RoundtripBwLeftAndRightPositions(): void
    {
        // BW слева от внешнего оператора
        $left = ExpressionNormalizer::parse('x0' . self::T1_NAME . 'x1', $this->birthNames);
        $this->assertSame(self::T1_NAME, $left['op'] ?? null);
        // BW справа: (x0−x1)+BW-форма
        $right = ExpressionNormalizer::parse('(x0−x1)+(x0' . self::T1_NAME . 'x1)', $this->birthNames);
        $this->assertSame('+', $right['op'] ?? null);
        $this->assertSame(self::T1_NAME, $right['r']['op'] ?? null);
    }

    // ── R2: semantic equivalence (реальный T₂ из PROBE-1) ───────────

    public function testR2SemanticEquivalenceRealT2(): void
    {
        $db = Database::get();
        $db->prepare("INSERT OR REPLACE INTO grammar_ops (name, source, definition, status) VALUES (?, 'birth', ?, 'candidate')")
            ->execute([self::T1_NAME, self::T1_DEF]);
        $db->prepare("INSERT OR REPLACE INTO grammar_ops (name, source, definition, status) VALUES (?, 'birth', ?, 'candidate')")
            ->execute([self::T2_NAME, self::T2_DEF]);

        $X = $this->inputs();
        $lawVals = $this->lawValues($X);
        // исходный закон: T₁-форма + (x0−x2) — через вложенную оценку движка
        $lawEngine = ExpressionEvaluator::evaluateFormula(self::LAW_X, $X);
        // сохранённый T₂: ((x0−x1)+x0BW_T₁x1) — та же функция через T₂
        $t2Vals = ExpressionEvaluator::evaluateFormula(self::T2_DEF, $X);

        $this->assertIsArray($lawEngine);
        $this->assertIsArray($t2Vals);
        $this->assertCount(count($lawVals), $lawEngine);
        $this->assertCount(count($lawVals), $t2Vals);
        for ($i = 0; $i < count($lawVals); $i++) {
            $this->assertIsNumeric($lawEngine[$i]);
            $this->assertIsNumeric($t2Vals[$i]);
            $this->assertEqualsWithDelta($lawVals[$i], (float) $lawEngine[$i], 1e-9 * max(1.0, abs($lawVals[$i])), "law vs engine-law, row $i");
            $this->assertEqualsWithDelta($lawVals[$i], (float) $t2Vals[$i], 1e-9 * max(1.0, abs($lawVals[$i])), "law vs T₂, row $i");
        }
    }

    // ── R3: variable hygiene ────────────────────────────────────────

    public function testR3NoGluedAtomsInParsedTree(): void
    {
        $ast = ExpressionNormalizer::parse(self::T2_DEF, $this->birthNames);
        $atoms = [];
        $walk = function (array|string|null $node) use (&$walk, &$atoms): void {
            if ($node === null || is_string($node)) { return; }
            if (isset($node['atom'])) { $atoms[] = $node['atom']; return; }
            foreach (['l', 'r'] as $k) {
                if (isset($node[$k]) && is_array($node[$k])) { $walk($node[$k]); }
            }
        };
        $walk($ast);
        foreach ($atoms as $a) {
            $this->assertMatchesRegularExpression('/^x\d+$/', (string) $a, "терминал '$a' — не формальная переменная (склейка?)");
        }
    }

    public function testR3RenameAndPermuteBinding(): void
    {
        $db = Database::get();
        $db->prepare("INSERT OR REPLACE INTO grammar_ops (name, source, definition, status) VALUES (?, 'birth', ?, 'candidate')")
            ->execute([self::T1_NAME, self::T1_DEF]);
        $db->prepare("INSERT OR REPLACE INTO grammar_ops (name, source, definition, status) VALUES (?, 'birth', ?, 'candidate')")
            ->execute([self::T2_NAME, self::T2_DEF]);

        // перестановка колонок: закон в терминах (a,b,c) = (col1, col0, col2)
        mt_srand(777);
        $Xa = []; $Xb = [];
        for ($i = 0; $i < 10; $i++) {
            $c0 = mt_rand(10, 30) / 10; $c1 = mt_rand(40, 60) / 10; $c2 = mt_rand(30, 70) / 10;
            $Xa[] = [$c0, $c1, $c2];           // привязка A: x0=c0, x1=c1, x2=c2
            $Xb[] = [$c1, $c0, $c2];           // привязка B: колонки переставлены
        }
        // определение под привязку B: T₁(c1,c0) + (c1−c2) — swap через
        // плейсхолдеры (прямой str_replace x0↔x1 перекладывал бы дважды)
        $defB = str_replace(['x0', 'x1'], ["\x00A\x00", "\x00B\x00"], self::T2_DEF);
        $defB = str_replace(["\x00A\x00", "\x00B\x00"], ['x1', 'x0'], $defB);
        $vA = ExpressionEvaluator::evaluateFormula(self::T2_DEF, $Xa);
        $vB = ExpressionEvaluator::evaluateFormula($defB, $Xb);
        $this->assertIsArray($vA);
        $this->assertIsArray($vB);
        for ($i = 0; $i < 10; $i++) {
            $this->assertEqualsWithDelta($vA[$i], $vB[$i], 1e-9 * max(1.0, abs((float) $vA[$i])), "rename/permute, row $i");
        }
    }

    // ── R4: clean DB ────────────────────────────────────────────────

    public function testR4CleanDbBothDependencies(): void
    {
        $dbFile = tempnam(sys_get_temp_dir(), 'mol001_') . '.sqlite';
        @unlink($dbFile);
        putenv("SWARM_DB_PATH=$dbFile");
        Database::reset();
        try {
            $db = Database::get();
            $db->prepare("INSERT OR REPLACE INTO grammar_ops (name, source, definition, status) VALUES (?, 'birth', ?, 'candidate')")
                ->execute([self::T1_NAME, self::T1_DEF]);
            $db->prepare("INSERT OR REPLACE INTO grammar_ops (name, source, definition, status) VALUES (?, 'birth', ?, 'candidate')")
                ->execute([self::T2_NAME, self::T2_DEF]);
            $X = $this->inputs(6);
            $v = ExpressionEvaluator::evaluateFormula(self::T2_DEF, $X);
            $this->assertIsArray($v, 'T₂ вычисляется в чистой БД с обеими зависимостями');
            $expected = $this->lawValues($X);
            for ($i = 0; $i < 6; $i++) {
                $this->assertEqualsWithDelta($expected[$i], (float) $v[$i], 1e-9 * max(1.0, abs($expected[$i])), "clean DB, row $i");
            }
        } finally {
            // восстановление окружения: иначе static-кэш birthOpNames и
            // singleton переживают тест → порядок-зависимые фейлы соседей
            putenv('SWARM_DB_PATH=:memory:');
            Database::reset();
            @unlink($dbFile);
        }
    }

    public function testR4MissingT1IsDependencyFailureNotParseError(): void
    {
        $dbFile = tempnam(sys_get_temp_dir(), 'mol001_') . '.sqlite';
        @unlink($dbFile);
        putenv("SWARM_DB_PATH=$dbFile");
        Database::reset();
        try {
            $db = Database::get();
            // ТОЛЬКО T₂, без T₁
            $db->prepare("INSERT OR REPLACE INTO grammar_ops (name, source, definition, status) VALUES (?, 'birth', ?, 'candidate')")
                ->execute([self::T2_NAME, self::T2_DEF]);
            // парс: birthNames из БД = [T₂] → вложенный T₁-токен неразрешим,
            // но parse СТРУКТУРНО успешен (склеенный атом) — отличимо от
            // parse-ошибки (null-дерева)
            $birthNames = \BeeSwarm\Core\ExpressionEvaluator::birthOpNames();
            $ast = ExpressionNormalizer::parse(self::T2_DEF, $birthNames);
            $this->assertNotNull($ast, 'parse структуру даёт (нет parse-ошибки)');
            $v = \BeeSwarm\Core\ExpressionEvaluator::evaluateFormula(self::T2_DEF, $this->inputs(4));
            $this->assertNull($v, 'без T₁ вложенная ветка возвращает null = отсутствующая зависимость');
        } finally {
            putenv('SWARM_DB_PATH=:memory:');
            Database::reset();
            @unlink($dbFile);
        }
    }

    // ── R6: identity compatibility ──────────────────────────────────

    public function testR6FingerprintStabilityNonNested(): void
    {
        // pre-fix бейлайн (захвачен до фикса, см. отчёт MOL-001):
        // render И fingerprint ненnested-форм не изменились
        $baseline = [
            '((x0+x1)/(x0−x1))' => ['((x0+x1)/(x0−x1))', '((x0+x1)/(x0−x1))'],
            '(x0−x1)+(x0×x2)' => ['((x0−x1)+(x0×x2))', '((x0×x2)+(x0−x1))'],
            '((x0−x1))²' => ['((x0−x1))²', '((x0−x1))²'],
        ];
        foreach ($baseline as $form => [$expectedRender, $expectedFp]) {
            $ast = ExpressionNormalizer::parse($form, []);
            $this->assertNotNull($ast);
            $rendered = ExpressionNormalizer::render($ast);
            $this->assertSame($expectedRender, $rendered, "render ненnested '$form' не изменился");
            $this->assertSame(
                ExpressionNormalizer::fingerprint($expectedFp),
                ExpressionNormalizer::fingerprint($rendered),
                "fingerprint '$form' стабилен",
            );
        }
    }

    public function testR6RenameCorruptionRepaired(): void
    {
        // ИСКАЖЁННЫЙ канон PROBE-1 (pre-fix): слоты молекулы были переименованы
        // (x2→x1) — семантика потеряна. Post-fix канонизатор (parse видит
        // B-применение → molecule path без переименования) даёт корректный
        // канон из ТЕХ ЖЕ сохранённых законов.
        $db = Database::get();
        $db->prepare("INSERT OR REPLACE INTO grammar_ops (name, source, definition, status) VALUES (?, 'birth', ?, 'candidate')")
            ->execute([self::T1_NAME, self::T1_DEF]);
        // T₂ тоже: его отсутствие совпадало бы со stale-sentinel'ом кэша
        // birthOpNames (count 1→1 после R4Missing → stale список без T₁)
        $db->prepare("INSERT OR REPLACE INTO grammar_ops (name, source, definition, status) VALUES (?, 'birth', ?, 'candidate')")
            ->execute([self::T2_NAME, self::T2_DEF]);
        $storedLaw = '((x0−x2)+x0' . self::T1_NAME . 'c1)'; // форма с исходным дефектом конверсии
        $canonical = \BeeSwarm\Hive\LawIsomorphismCompressor::canonize($storedLaw);
        $this->assertNotNull($canonical);
        $this->assertStringContainsString('(x0−x2)', $canonical, 'слот x2 больше не переименовывается');
        $this->assertStringContainsString(self::T1_NAME, $canonical, 'вложенный T₁ сохранён');
    }
}
