<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Core\ExpressionNormalizer;
use BeeSwarm\Core\WordCodec;
use BeeSwarm\Core\WordCodecException;
use BeeSwarm\Infra\Database;

/**
 * GENOME-001 CHECKPOINT A (15.10.2026): кодек наследуемых исполняемых слов.
 *
 * Реальные артефакты PROBE-1 (канон post-MOL-001, tests/MolRoundtripTest.php):
 *   T₁ = BW5737967f… = ((x0+x1)/(x0−x1)), арность 2
 *   T₂ = BW59baeae1… = ((x0−x2)+x0BW_T₁x1), молекула арности 3, definition содержит T₁
 *   закон D₂ = T₁(c0,c1) + (c0−c2)
 *
 * GC-1 roundtrip / GC-2 численная эквивалентность / GC-3 variable hygiene /
 * GC-4 вложенные слова + разделяемые подвыражения / GC-5 чистая БД (in-process
 * + subprocess) / GC-6 отказы (unknown, cycle, arity) / GC-7 полное раскрытие
 * без birth-атомов (H1: слово — архив, исполняемый смысл живёт в словаре).
 */
final class GenomeCodecTest extends TestCase
{
    private const T1_NAME = 'BW5737967f9b4ecb628fd48d7d82502f40';
    private const T1_DEF = '((x0+x1)/(x0−x1))';
    private const T2_NAME = 'BW59baeae13abe8cbdfae0c823ff207db2';
    private const T2_DEF = '((x0−x2)+x0BW5737967f9b4ecb628fd48d7d82502f40x1)';
    private const LAW_X = '((x0BW5737967f9b4ecb628fd48d7d82502f40x1)+(x0−x2))';

    protected function tearDown(): void
    {
        // атомы эксперимента не переживают тест в общей suite-БД
        // (прецедент MolRoundtripTest: bornBinary читает grammar_ops напрямую)
        try {
            Database::get()->prepare('DELETE FROM grammar_ops WHERE name IN (?, ?)')
                ->execute([self::T1_NAME, self::T2_NAME]);
        } catch (\Throwable) {
            // R4-тесты подменяли БД — восстановление уже сделано в finally
        }
        parent::tearDown();
    }

    /** Независимые входы (паттерн MolRoundtripTest::inputs). */
    private function inputs(int $n = 12): array
    {
        mt_srand(4242);
        $X = [];
        for ($i = 0; $i < $n; $i++) {
            $X[] = [mt_rand(10, 30) / 10, mt_rand(40, 60) / 10, mt_rand(30, 70) / 10];
        }

        return $X;
    }

    /** Оракул закона чистой арифметикой (независим от движка). */
    private function lawValues(array $X): array
    {
        $out = [];
        foreach ($X as $row) {
            $out[] = ($row[0] + $row[1]) / ($row[0] - $row[1]) + ($row[0] - $row[2]);
        }

        return $out;
    }

    private function dictionary(): array
    {
        return WordCodec::buildDictionary(
            [self::T1_NAME => self::T1_DEF, self::T2_NAME => self::T2_DEF],
            [self::T1_NAME, self::T2_NAME]
        );
    }

    private function registerAtoms(): void
    {
        $db = Database::get();
        $stmt = $db->prepare("INSERT OR REPLACE INTO grammar_ops (name, source, definition, status) VALUES (?, 'birth', ?, 'candidate')");
        $stmt->execute([self::T1_NAME, self::T1_DEF]);
        $stmt->execute([self::T2_NAME, self::T2_DEF]);
        ExpressionEvaluator::resetCaches();
    }

    /** @return list<float|null> */
    private function evalAstRows(array $ast, array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = ExpressionEvaluator::evalNode($ast, $row, [], []);
        }

        return $out;
    }

    private function assertVectorsEqual(array $expected, array $actual, string $label): void
    {
        self::assertCount(count($expected), $actual, $label . ': длина вектора');
        foreach ($expected as $i => $e) {
            $a = $actual[$i];
            if ($e === null || $a === null) {
                self::assertNull($a, $label . ": row {$i} — null-паттерн (недопустимая операция)");

                continue;
            }
            self::assertEqualsWithDelta($e, $a, 1e-9 * max(1.0, abs((float) $e)), $label . ": row {$i}");
        }
    }

    // ── GC-1: roundtrip ────────────────────────────────────────────────

    public function testEncodeDecodeRoundtripT1ExactStructure(): void
    {
        $entries = $this->dictionary();
        $ast = WordCodec::parseDefinition(self::T1_DEF);
        $genome = WordCodec::encode($ast, $entries);
        // T₁ сжимается до одной ссылки
        self::assertSame(self::T1_NAME, $genome['word'] ?? null);
        $decoded = WordCodec::decode($genome, $entries);
        self::assertSame(json_encode($ast), json_encode($decoded), 'T₁: точное структурное восстановление');
    }

    public function testEncodeDecodeRoundtripT2DefinitionExactStructure(): void
    {
        $entries = $this->dictionary();
        $ast = WordCodec::parseDefinition(self::T2_DEF, [self::T1_NAME, self::T2_NAME]);
        $genome = WordCodec::encode($ast, $entries);
        self::assertSame(self::T2_NAME, $genome['word'] ?? null, 'T₂ сжимается до одной ссылки');
        self::assertSame(3, $genome['args'] ? count($genome['args']) : 0, 'T₂ — молекула арности 3');
        $decoded = WordCodec::decode($genome, $entries);
        self::assertSame(json_encode($ast), json_encode($decoded), 'T₂: точное структурное восстановление');
        // вложенный T₁-application сохранён КАК ЕСТЬ (faithful op-node pass-through)
        $rendered = ExpressionNormalizer::render($decoded);
        self::assertStringContainsString(self::T1_NAME, $rendered, 'вложенный T₁ на месте');
    }

    public function testEncodeDecodeRoundtripMultiLevelApplications(): void
    {
        $entries = $this->dictionary();
        $birth = [self::T1_NAME, self::T2_NAME];
        // применение T₂ и T₁ по обе стороны внешнего оператора
        $expr = '(x0' . self::T2_NAME . 'x1)+(x1' . self::T1_NAME . 'x2)';
        $ast = WordCodec::parseDefinition($expr, $birth);
        $genome = WordCodec::encode($ast, $entries);
        $decoded = WordCodec::decode($genome, $entries);
        self::assertSame(json_encode($ast), json_encode($decoded), 'многоуровневые application: точный roundtrip');
        self::assertTrue(
            WordCodec::containsWordApplications($decoded, $birth),
            'application-узлы прошли насквозь (faithful pass-through)'
        );
        // ВАЖНО: исполнение этого дерева НЕ проверяем — арность-3 application в движке
        // инертна (Link2 FAIL, row=[$l,$r]); кодек сохраняет дерево, а не чинит движок.
    }

    public function testEncodeWithEmptyDictionaryIsLosslessPassthrough(): void
    {
        $ast = WordCodec::parseDefinition(self::T2_DEF, [self::T1_NAME, self::T2_NAME]);
        $genome = WordCodec::encode($ast, []);
        $decoded = WordCodec::decode($genome, []);
        self::assertSame(json_encode($ast), json_encode($decoded), 'пустой словарь: геном = lossless-пасsthrough');
        self::assertArrayNotHasKey('word', $genome, 'без словаря ссылок не возникает');
    }

    public function testLawCompressesToSingleWordReferenceAndDecodesCanonically(): void
    {
        $entries = $this->dictionary();
        $ast = WordCodec::parseDefinition(self::LAW_X, [self::T1_NAME, self::T2_NAME]);
        $genome = WordCodec::encode($ast, $entries);
        self::assertSame(self::T2_NAME, $genome['word'] ?? null, 'закон D₂ = одно слово T₂');
        self::assertCount(3, $genome['args']);
        self::assertLessThan(
            WordCodec::nodesCount($ast),
            WordCodec::nodesCount($genome),
            'геном меньше развёрнутого AST'
        );
        $decoded = WordCodec::decode($genome, $entries);
        // исходный порядок детей: [T₁app, (x0−x2)]; definition T₂: [(x0−x2), T₁app] —
        // коммутативное зеркало, каноническая эквивалентность (рамка HANDOFF §3)
        self::assertTrue(WordCodec::astEqual($decoded, $ast), 'decode канонически эквивалентен исходнику');
        self::assertFalse(
            json_encode($decoded) === json_encode($ast),
            'и честно: побайтово порядок детей зеркален (документируем, не прячем)'
        );
    }

    // ── GC-2: численная эквивалентность ────────────────────────────────

    public function testNumericEquivalenceOriginalVsDecoded(): void
    {
        $this->registerAtoms();
        $entries = $this->dictionary();
        $ast = WordCodec::parseDefinition(self::LAW_X, [self::T1_NAME, self::T2_NAME]);
        $decoded = WordCodec::decode(WordCodec::encode($ast, $entries), $entries);
        $X = $this->inputs(12);
        $this->assertVectorsEqual($this->lawValues($X), $this->evalAstRows($decoded, $X), 'decoded vs oracle');
        $this->assertVectorsEqual(
            $this->evalAstRows($ast, $X),
            $this->evalAstRows($decoded, $X),
            'decoded vs original'
        );
    }

    public function testZeroDenominatorNullPatternPreserved(): void
    {
        $this->registerAtoms();
        $entries = $this->dictionary();
        $ast = WordCodec::parseDefinition(self::LAW_X, [self::T1_NAME, self::T2_NAME]);
        $decoded = WordCodec::decode(WordCodec::encode($ast, $entries), $entries);
        // x0==x1 → знаменатель T₁ = 0 → null; (0,0,0) → null; (5,3,5) → (x0−x2)=0 в слагаемом — валидно
        $rows = [[5.0, 5.0, 2.0], [1.0, 1.0, 1.0], [0.0, 0.0, 0.0], [5.0, 3.0, 5.0], [2.0, 4.0, 1.0]];
        $orig = $this->evalAstRows($ast, $rows);
        $dec = $this->evalAstRows($decoded, $rows);
        $this->assertVectorsEqual($orig, $dec, 'zero-denominator: null-паттерн совпадает');
        foreach ([0, 1, 2] as $i) {
            self::assertNull($orig[$i], "row {$i}: x0−x1=0 → недопустимая операция = null");
        }
        self::assertNotNull($orig[3], 'row 3: (x0−x2)=0 валиден (не знаменатель)');
        self::assertEqualsWithDelta(
            $this->lawValues([$rows[3]])[0],
            $orig[3],
            1e-9,
            'row 3 значение по оракулу'
        );
    }

    // ── GC-3: variable hygiene ─────────────────────────────────────────

    public function testVariableHygieneDecodedAtomsFormal(): void
    {
        $entries = $this->dictionary();
        $birth = [self::T1_NAME, self::T2_NAME];
        foreach ([self::T2_DEF, self::LAW_X] as $form) {
            $ast = WordCodec::parseDefinition($form, $birth);
            $decoded = WordCodec::decode(WordCodec::encode($ast, $entries), $entries);
            $atoms = [];
            $walk = static function (array $node) use (&$walk, &$atoms): void {
                if (isset($node['atom'])) {
                    $atoms[] = (string) $node['atom'];

                    return;
                }
                foreach (['l', 'r'] as $k) {
                    if (isset($node[$k]) && is_array($node[$k])) {
                        $walk($node[$k]);
                    }
                }
            };
            $walk($decoded);
            self::assertNotSame([], $atoms, "{$form}: атомы есть");
            foreach ($atoms as $a) {
                self::assertMatchesRegularExpression('/^x\d+$/', $a, "{$form}: атом '{$a}' — не формальная переменная");
                self::assertStringNotContainsString('BW', $a, "{$form}: склейка BW-токена в атом?");
            }
        }
    }

    public function testRenamePermuteBindingSurvivesArchive(): void
    {
        $this->registerAtoms();
        $entries = $this->dictionary();
        $birth = [self::T1_NAME, self::T2_NAME];
        mt_srand(777);
        $Xa = [];
        $Xb = [];
        for ($i = 0; $i < 10; $i++) {
            $c0 = mt_rand(10, 30) / 10;
            $c1 = mt_rand(40, 60) / 10;
            $c2 = mt_rand(30, 70) / 10;
            $Xa[] = [$c0, $c1, $c2];
            $Xb[] = [$c1, $c0, $c2]; // колонки переставлены
        }
        // переименование через плейсхолдеры (прямой str_replace x0↔x1 перекладывал бы дважды)
        $defB = str_replace(['x0', 'x1'], ["\x00A\x00", "\x00B\x00"], self::T2_DEF);
        $defB = str_replace(["\x00A\x00", "\x00B\x00"], ['x1', 'x0'], $defB);
        $astB = WordCodec::parseDefinition($defB, $birth);
        $decodedB = WordCodec::decode(WordCodec::encode($astB, $entries), $entries);
        self::assertSame(json_encode($astB), json_encode($decodedB), 'переименованная форма: точный roundtrip');
        $valsOriginal = $this->evalAstRows($astB, $Xb);
        $valsDecoded = $this->evalAstRows($decodedB, $Xb);
        $this->assertVectorsEqual($valsOriginal, $valsDecoded, 'binding-B: original vs decoded');
        // привязка A: та же функция на своих колонках
        $astA = WordCodec::parseDefinition(self::T2_DEF, $birth);
        $this->assertVectorsEqual(
            $this->evalAstRows($astA, $Xa),
            $valsOriginal,
            'форма A на Xa ≡ форма B на Xb (семантика переименования сохранена архивацией)'
        );
    }

    // ── GC-4: вложенные слова + разделяемые подвыражения ───────────────

    public function testNestedWordDependencyAndSharedSubexpressionSingleArticle(): void
    {
        // вложенность T₂→T₁: dependency статьи T₂ содержит T₁
        $entries = $this->dictionary();
        $byName = [];
        foreach ($entries as $e) {
            $byName[$e['name']] = $e;
        }
        self::assertSame(
            [self::T1_NAME],
            WordCodec::dependenciesOfEntry($byName[self::T2_NAME], $entries),
            'T₂ зависит ровно от T₁ (born-op application в definition)'
        );
        self::assertSame([], WordCodec::dependenciesOfEntry($byName[self::T1_NAME], $entries), 'T₁ независим');

        // разделяемые подвыражения: (x0×x1) и (x0−x1) живут в ОДНОЙ статье каждая
        $shared = WordCodec::buildDictionary([
            'S1' => 'x0×x1',
            'S2' => '(x0−x1)',
            'W1' => '((x0×x1)+(x0−x1))',
            'W2' => '((x0×x1)×(x0−x1))',
        ], []);
        self::assertCount(4, $shared, 'ровно 4 статьи: общие поддеревья не дублируются');
        $names = [];
        foreach ($shared as $e) {
            $names[$e['name']] = $e;
        }
        self::assertSame(['S1', 'S2'], WordCodec::dependenciesOfEntry($names['W1'], $shared));
        self::assertSame(['S1', 'S2'], WordCodec::dependenciesOfEntry($names['W2'], $shared));
        foreach ($shared as $e) {
            $back = WordCodec::decodeDefinition($e, $shared);
            self::assertSame(
                json_encode($e['def']),
                json_encode($back),
                "{$e['name']}: definition восстанавливается точно из генома"
            );
        }
        // W2 сжался до ОДНОЙ ссылки: S1(S1(x0,x1), S2(x0,x1)) ≡ ((x0×x1)×(x0−x1))
        self::assertSame('S1', $names['W2']['genome']['word'] ?? null, 'W2 = одно слово-применение');
    }

    // ── GC-5: чистая БД ────────────────────────────────────────────────

    public function testCleanDbInProcessBundleTransferExecutes(): void
    {
        $entries = $this->dictionary();
        $law = WordCodec::encode(
            WordCodec::parseDefinition(self::LAW_X, [self::T1_NAME, self::T2_NAME]),
            $entries
        );
        $bundle = WordCodec::serializeBundle($entries, ['law' => $law]);

        $dbFile = tempnam(sys_get_temp_dir(), 'genome001_') . '.sqlite';
        @unlink($dbFile);
        putenv("SWARM_DB_PATH={$dbFile}");
        Database::reset();
        ExpressionEvaluator::resetCaches();
        try {
            [$loadedEntries, $genomes] = WordCodec::loadBundle($bundle);
            WordCodec::registerDictionary($loadedEntries);
            $birthNames = ExpressionEvaluator::birthOpNames();
            self::assertContains(self::T1_NAME, $birthNames, 'T₁ зарегистрирован в чистой БД');
            self::assertContains(self::T2_NAME, $birthNames, 'T₂ зарегистрирован в чистой БД');
            // registerDictionary: definition = render(decodeDefinition) — скобочная
            // форма (MOL-001: parse(render(AST)) ≡ AST, склеенная и скобочная
            // записи одного дерева эквивалентны). Инвариант — структурный, не байтовый.
            $row = Database::get()->prepare('SELECT definition FROM grammar_ops WHERE name = ?');
            $row->execute([self::T2_NAME]);
            $restored = (string) $row->fetchColumn();
            $birthNames = ExpressionEvaluator::birthOpNames();
            self::assertSame(
                json_encode(WordCodec::parseDefinition(self::T2_DEF, $birthNames)),
                json_encode(WordCodec::parseDefinition($restored, $birthNames)),
                'definition T₂ в чистой БД структурно эквивалентна исходной'
            );
            $ast = WordCodec::decode($genomes['law'], $loadedEntries);
            $X = $this->inputs(8);
            $this->assertVectorsEqual($this->lawValues($X), $this->evalAstRows($ast, $X), 'clean DB: decode+execute');
        } finally {
            putenv('SWARM_DB_PATH=:memory:');
            Database::reset();
            ExpressionEvaluator::resetCaches();
            @unlink($dbFile);
        }
    }

    public function testCleanDbSubprocessCrossLineExecution(): void
    {
        // H1: словарь+геном как ОПАКЕТНЫЙ JSON переходят в НОВЫЙ процесс
        // (новая линия): decode+execute там, без какого-либо process-local состояния.
        $entries = $this->dictionary();
        $law = WordCodec::encode(
            WordCodec::parseDefinition(self::LAW_X, [self::T1_NAME, self::T2_NAME]),
            $entries
        );
        $X = $this->inputs(8);
        $envelope = [
            'db' => tempnam(sys_get_temp_dir(), 'genome001_sub_') . '.sqlite',
            'genome' => 'law',
            'rows' => $X,
            'bundle' => json_decode(WordCodec::serializeBundle($entries, ['law' => $law]), true),
        ];
        $envFile = tempnam(sys_get_temp_dir(), 'genome001_env_') . '.json';
        file_put_contents($envFile, json_encode($envelope, JSON_UNESCAPED_UNICODE));
        try {
            $out = shell_exec(
                'timeout 60 ' . escapeshellarg(PHP_BINARY) . ' '
                . escapeshellarg(dirname(__DIR__) . '/scripts/genome001/decode_execute.php')
                . ' ' . escapeshellarg($envFile) . ' 2>&1'
            );
            self::assertIsString($out, 'subprocess вернул вывод');
            $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $out))));
            self::assertNotSame([], $lines, 'subprocess: есть строки вывода');
            $result = json_decode((string) end($lines), true);
            self::assertIsArray($result, 'subprocess: JSON-ответ, получили: ' . (string) end($lines));
            self::assertTrue($result['ok'] ?? false, 'subprocess ok: ' . (string) ($result['error'] ?? ''));
            $this->assertVectorsEqual($this->lawValues($X), $result['values'], 'subprocess: значения закона');
        } finally {
            @unlink($envFile);
            @unlink((string) $envelope['db']);
        }
    }

    public function testSubprocessRefusesUnknownWord(): void
    {
        $entries = $this->dictionary();
        $envelope = [
            'db' => tempnam(sys_get_temp_dir(), 'genome001_sub2_') . '.sqlite',
            'genome' => 'corrupt',
            'rows' => [[1.0, 2.0, 3.0]],
            'bundle' => json_decode(
                WordCodec::serializeBundle($entries, ['corrupt' => ['word' => 'BWdeadbeefdeadbeefdeadbeefdeadbeef', 'args' => []]]),
                true
            ),
        ];
        $envFile = tempnam(sys_get_temp_dir(), 'genome001_env2_') . '.json';
        file_put_contents($envFile, json_encode($envelope, JSON_UNESCAPED_UNICODE));
        try {
            $out = shell_exec(
                'timeout 60 ' . escapeshellarg(PHP_BINARY) . ' '
                . escapeshellarg(dirname(__DIR__) . '/scripts/genome001/decode_execute.php')
                . ' ' . escapeshellarg($envFile) . ' 2>&1'
            );
            $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $out))));
            $result = json_decode((string) end($lines), true);
            self::assertIsArray($result, 'subprocess: JSON-ответ');
            self::assertFalse($result['ok'] ?? true, 'отказ при неизвестном слове');
            self::assertStringContainsString('unknown word', (string) ($result['error'] ?? ''), 'диагноз: unknown word');
        } finally {
            @unlink($envFile);
            @unlink((string) $envelope['db']);
        }
    }

    // ── GC-6: отказы ───────────────────────────────────────────────────

    public function testUnknownWordRefusalInProcess(): void
    {
        $entries = $this->dictionary();
        $genome = ['word' => 'BWunknown0000000000000000000000ff', 'args' => [['atom' => 'x0'], ['atom' => 'x1']]];
        try {
            WordCodec::decode($genome, $entries);
            self::fail('decode: неизвестное слово должно отказывать');
        } catch (WordCodecException $e) {
            self::assertStringContainsString('unknown word', $e->getMessage());
        }
        try {
            WordCodec::decodeExpanded($genome, $entries);
            self::fail('decodeExpanded: неизвестное слово должно отказывать');
        } catch (WordCodecException $e) {
            self::assertStringContainsString('unknown word', $e->getMessage());
        }
    }

    public function testCyclicDictionaryRefusal(): void
    {
        // (a) {word}-ссылочный цикл A→B→A при decodeDefinition
        $cyclic = [
            ['name' => 'A', 'arity' => 2, 'genome' => ['word' => 'B', 'args' => [['atom' => 'x0'], ['atom' => 'x1']]]],
            ['name' => 'B', 'arity' => 2, 'genome' => ['op' => '+', 'l' => ['word' => 'A', 'args' => [['atom' => 'x0'], ['atom' => 'x1']]], 'r' => ['atom' => 'K1']]],
        ];
        try {
            WordCodec::decodeDefinition($cyclic[0], $cyclic);
            self::fail('ссылочный цикл должен отказывать');
        } catch (WordCodecException $e) {
            self::assertStringContainsString('cyclic word dependency', $e->getMessage());
        }
        // (b) self-reference
        $self = [['name' => 'A', 'arity' => 2, 'genome' => ['word' => 'A', 'args' => [['atom' => 'x0'], ['atom' => 'x1']]]]];
        try {
            WordCodec::decodeDefinition($self[0], $self);
            self::fail('self-reference должен отказывать');
        } catch (WordCodecException $e) {
            self::assertStringContainsString('cyclic word dependency', $e->getMessage());
        }
        // (c) определенческий цикл через born-op application: A применяет B, B применяет A
        $an = 'BWaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1';
        $bn = 'BWbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb2';
        try {
            WordCodec::buildDictionary([$an => 'x0' . $bn . 'x1', $bn => 'x0' . $an . 'x1'], [$an, $bn]);
            self::fail('определенческий цикл должен отказывать (validateDictionary)');
        } catch (WordCodecException $e) {
            self::assertStringContainsString('cyclic dictionary', $e->getMessage());
        }
    }

    public function testArityMismatchRefusal(): void
    {
        $entries = $this->dictionary();
        // (a) decode: ссылка с неверным числом аргументов
        try {
            WordCodec::decode(['word' => self::T2_NAME, 'args' => [['atom' => 'x0'], ['atom' => 'x1']]], $entries);
            self::fail('арность 2 != 3 должна отказывать');
        } catch (WordCodecException $e) {
            self::assertStringContainsString('arity mismatch', $e->getMessage());
        }
        // (b) decodeExpanded: T₂-application бинарной формы — ровно та точка, где
        // движок молча подставлял бы x2→0 (Link2 FAIL); кодек отказывается явно
        $app = WordCodec::parseDefinition('x0' . self::T2_NAME . 'x1', [self::T1_NAME, self::T2_NAME]);
        try {
            WordCodec::decodeExpanded($app, $entries);
            self::fail('T₂-application (арность 3, бинарная форма) должен отказывать, а не подставлять x2→0');
        } catch (WordCodecException $e) {
            self::assertStringContainsString('arity mismatch', $e->getMessage());
        }
    }

    // ── GC-7: полное раскрытие без birth-атомов (H1) ───────────────────

    public function testFullExpansionExecutesWithoutBirthAtoms(): void
    {
        // grammar_ops пуст (изоляция TestCase): НИ ОДНОГО зарегистрированного атома
        $birth = [self::T1_NAME, self::T2_NAME];
        self::assertNotContains(self::T1_NAME, ExpressionEvaluator::birthOpNames(), 'атомов нет в БД');
        $entries = $this->dictionary();
        $law = WordCodec::encode(WordCodec::parseDefinition(self::LAW_X, $birth), $entries);
        $pure = WordCodec::decodeExpanded($law, $entries);
        self::assertFalse(
            WordCodec::containsWordApplications($pure, $birth),
            'полное раскрытие: born-op application не осталось'
        );
        self::assertSame(
            '((x0−x2)+((x0+x1)/(x0−x1)))',
            ExpressionNormalizer::render($pure),
            'раскрытое дерево — чистая базовая грамматика'
        );
        $X = $this->inputs(12);
        $this->assertVectorsEqual($this->lawValues($X), $this->evalAstRows($pure, $X), 'H1: исполнение без словаря в БД');
    }
}
