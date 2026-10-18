<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\WordCodec;
use BeeSwarm\Core\WordCodecException;

require_once dirname(__DIR__) . '/scripts/genome001/corpus_lib.php';
require_once dirname(__DIR__) . '/scripts/genome002/evolve_lib.php';

/**
 * WORD-CUM-001 CHECKPOINT A (16.10.2026): накопительное наследование ссылок.
 *
 * Главный вопрос owner: сохраняется ли ссылка {word,args} после допустимой
 * мутации СОСЕДНЕГО, не затронутого поддерева — и накапливаются ли
 * последовательные мутации в одной линии (родитель → потомок → потомок).
 *
 * Схема:
 *   parent  = (((x0+x1)/(x0−x1))+(x2×x3)) — encode против словаря {W1} даёт
 *             геном {op:+, l:{word:W1,args:[x0,x1]}, r:{op:×,l:x2,r:x3}};
 *   мутации — минимальная локальная правка узлов генома (рамка owner:
 *             «разрешается минимальная локальная мутация непосредственно
 *             узлов Word Genome»), сайт детерминирован;
 *   C1-C2   ref выживает при мутации соседа, обе мутации наследуются в цепочке;
 *   C3      финальный геном → чистый процесс (decode_execute.php) → исполнение;
 *   C4      эмпирика схемы decode → mutate AST → re-encode (теряет ли ссылки);
 *   C5      мутация ВНУТРИ аргументов W1;
 *   C6      отказы: неверная арность / отсутствующее слово;
 *   C7      совместимость с мутатором GENOME-002 (mutateGenome): 100 сидированных
 *             потомков декодируются без исключений.
 */
final class WordCum001Test extends TestCase
{
    private const W1_NAME = 'BW5737967f9b4ecb628fd48d7d82502f40';

    private const W1_DEF = '((x0+x1)/(x0−x1))';

    private function dictionary(): array
    {
        return WordCodec::buildDictionary([
            self::W1_NAME => self::W1_DEF,
        ], [self::W1_NAME]);
    }

    /**
     * Геном parent: W1-ref + бинарные операции.
     */
    private function parentGenome(): array
    {
        $ast = WordCodec::parseDefinition('(((x0+x1)/(x0−x1))+(x2×x3))');

        return WordCodec::encode($ast, $this->dictionary());
    }

    /**
     * Минимальная локальная мутация узла генома по пути (atom-swap).
     */
    private function atomSwapAt(array $genome, array $path, string $newAtom): array
    {
        $ref = &$genome;
        foreach ($path as $step) {
            $ref = &$ref[$step];
        }
        self::assertArrayHasKey('atom', $ref, 'мутация по атому: ' . json_encode($ref));
        $ref['atom'] = $newAtom;

        return $genome;
    }

    private function refNode(array $genome): array
    {
        return $genome['l'];
    }

    private function assertVectorsEqual(array $a, array $b, string $label): void
    {
        self::assertCount(count($b), $a, $label . ': длина');
        foreach ($a as $i => $va) {
            $vb = $b[$i];
            if ($va === null || $vb === null) {
                self::assertNull($vb, $label . ": row {$i} null-паттерн");

                continue;
            }
            self::assertEqualsWithDelta($va, $vb, 1e-9 * max(1.0, abs((float) $va)), $label . ": row {$i}");
        }
    }

    // ── C1: ref выживает при мутации соседнего поддерева ───────────────

    public function testC1RefSurvivesMutationOutsideWord(): void
    {
        $parent = $this->parentGenome();
        self::assertSame(self::W1_NAME, $parent['l']['word'] ?? null, 'parent содержит {word:W1}');
        // мутация ЗА ПРЕДЕЛАМИ W1: путь [r,l] — атом x2 → x5
        $child = $this->atomSwapAt($parent, ['r', 'l'], 'x5');
        // та же ссылка: имя и аргументы байт-в-байт
        self::assertSame(json_encode($this->refNode($parent)), json_encode($this->refNode($child)), 'C1: {word,args} не затронут');
        // семантика W1-части: decoded subtree идентичен родительскому
        $dict = $this->dictionary();
        $parentDecoded = WordCodec::decode($parent, $dict);
        $childDecoded = WordCodec::decode($child, $dict);
        self::assertSame(
            json_encode($parentDecoded['l']),
            json_encode($childDecoded['l']),
            'C1: развёрнутая W1-часть потомка ≡ родительской'
        );
        // полная семантика потомка = W1(x0,x1) + x5×x3
        $expected = WordCodec::parseDefinition('(((x0+x1)/(x0−x1))+(x5×x3))');
        $rows = \GenomeBenchmarkLib::evalRows();
        $this->assertVectorsEqual(
            \GenomeBenchmarkLib::evalAst($expected, $rows),
            \GenomeBenchmarkLib::evalAst($childDecoded, $rows),
            'C1: семантика потомка'
        );
    }

    // ── C2: цепочка из двух мутаций наследуется ────────────────────────

    public function testC2ChainTwoMutationsInherited(): void
    {
        $dict = $this->dictionary();
        $parent = $this->parentGenome();
        // мутация 1: вне W1 (атом x2→x5)
        $off1 = $this->atomSwapAt($parent, ['r', 'l'], 'x5');
        // мутация 2: уже В ПОТОМКЕ, снова вне W1 (атом x3→x1)
        $off2 = $this->atomSwapAt($off1, ['r', 'r'], 'x1');
        // обе мутации присутствуют в финальном геноме
        self::assertSame('x5', $off2['r']['l']['atom'] ?? null, 'C2: мутация 1 (x2→x5) наследована');
        self::assertSame('x1', $off2['r']['r']['atom'] ?? null, 'C2: мутация 2 (x3→x1) наследована');
        self::assertSame(json_encode($this->refNode($parent)), json_encode($this->refNode($off2)), 'C2: W1-ref прошёл обе мутации');
        // семантика накопленной линии
        $decoded = WordCodec::decode($off2, $dict);
        $expected = WordCodec::parseDefinition('(((x0+x1)/(x0−x1))+(x5×x1))');
        $rows = \GenomeBenchmarkLib::evalRows();
        $this->assertVectorsEqual(
            \GenomeBenchmarkLib::evalAst($expected, $rows),
            \GenomeBenchmarkLib::evalAst($decoded, $rows),
            'C2: семантика потомка 2'
        );
    }

    // ── C3: передача финального генома в чистый процесс ────────────────

    public function testC3CleanProcessExecutesAccumulatedGenome(): void
    {
        $dict = $this->dictionary();
        $parent = $this->parentGenome();
        $off2 = $this->atomSwapAt($this->atomSwapAt($parent, ['r', 'l'], 'x5'), ['r', 'r'], 'x1');
        $bundle = WordCodec::serializeBundle($dict, [
            'child' => $off2,
        ]);
        $rows = \GenomeBenchmarkLib::evalRows();
        $envelope = [
            'db' => tempnam(sys_get_temp_dir(), 'wordcum_') . '.sqlite',
            'genome' => 'child',
            'rows' => $rows,
            'bundle' => json_decode($bundle, true),
        ];
        $envFile = tempnam(sys_get_temp_dir(), 'wordcum_env_') . '.json';
        file_put_contents($envFile, json_encode($envelope, JSON_UNESCAPED_UNICODE));
        try {
            $out = shell_exec(
                'timeout 60 ' . escapeshellarg(PHP_BINARY) . ' '
                . escapeshellarg(dirname(__DIR__) . '/scripts/genome001/decode_execute.php')
                . ' ' . escapeshellarg($envFile) . ' 2>&1'
            );
            $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $out))));
            $result = json_decode((string) end($lines), true);
            self::assertIsArray($result, 'subprocess JSON');
            self::assertTrue($result['ok'] ?? false, 'subprocess ok: ' . (string) ($result['error'] ?? ''));
            // оракул в родительском процессе: decode здесь
            $expected = \GenomeBenchmarkLib::evalAst(WordCodec::decode($off2, $dict), $rows);
            $this->assertVectorsEqual($expected, $result['values'], 'C3: чистый процесс исполнил накопленный геном');
        } finally {
            @unlink($envFile);
            @unlink((string) $envelope['db']);
        }
    }

    // ── C4: эмпирика decode → mutate AST → re-encode ───────────────────

    public function testC4AstRoundtripRefRetention(): void
    {
        $dict = $this->dictionary();
        $parent = $this->parentGenome();
        // (a) мутация ВНЕ word-области на AST-пути: ref при re-encode ВОССТАНАВЛИВАЕТСЯ
        $ast = WordCodec::decode($parent, $dict);
        $astMut = $this->atomSwapAt($ast, ['r', 'l'], 'x5');
        $regenome = WordCodec::encode($astMut, $dict);
        self::assertSame(self::W1_NAME, $regenome['l']['word'] ?? null, 'C4a: re-encode восстановил ref (subtree по-прежнему матчит W1)');
        self::assertSame(json_encode($this->refNode($parent)), json_encode($regenome['l']), 'C4a: аргументы ref те же');
        // (b) мутация ВНУТРИ word-области на AST-пути: ref ТЕРЯЕТСЯ (subtree больше не матчит W1)
        $astMut2 = $this->atomSwapAt($ast, ['l', 'l', 'l'], 'x4');
        $regenome2 = WordCodec::encode($astMut2, $dict);
        self::assertArrayNotHasKey('word', $regenome2['l'], 'C4b: re-encode потерял ref (подтверждено: decode→mutate AST→re-encode не сохраняет ссылки при правке внутри слова)');
        self::assertSame('x4', $regenome2['l']['l']['l']['atom'] ?? null, 'C4b: но содержимое мутации сохранено (raw-форма)');
        // семантика raw-формы корректна
        $decoded = WordCodec::decode($regenome2, $dict);
        $expected = WordCodec::parseDefinition('(((x4+x1)/(x0−x1))+(x2×x3))');
        $rows = \GenomeBenchmarkLib::evalRows();
        $this->assertVectorsEqual(
            \GenomeBenchmarkLib::evalAst($expected, $rows),
            \GenomeBenchmarkLib::evalAst($decoded, $rows),
            'C4b: raw-форма исполняется корректно'
        );
    }

    // ── C5: мутация аргументов W1 ──────────────────────────────────────

    public function testC5ArgMutationInsideWord(): void
    {
        $dict = $this->dictionary();
        $parent = $this->parentGenome();
        // мутация аргумента ref: args[0] x0 → x4 (имя слова не меняется)
        $child = $this->atomSwapAt($parent, ['l', 'args', 0], 'x4');
        self::assertSame(self::W1_NAME, $child['l']['word'] ?? null, 'C5: ref пережил мутацию аргумента');
        self::assertSame('x4', $child['l']['args'][0]['atom'] ?? null, 'C5: новый аргумент на месте');
        $decoded = WordCodec::decode($child, $dict);
        $expected = WordCodec::parseDefinition('(((x4+x1)/(x4−x1))+(x2×x3))');
        $rows = \GenomeBenchmarkLib::evalRows();
        $this->assertVectorsEqual(
            \GenomeBenchmarkLib::evalAst($expected, $rows),
            \GenomeBenchmarkLib::evalAst($decoded, $rows),
            'C5: слово применилось к НОВЫМ аргументам'
        );
    }

    // ── C6: отказы ─────────────────────────────────────────────────────

    public function testC6ArityAndMissingWordRefusals(): void
    {
        $dict = $this->dictionary();
        // неверная арность
        try {
            WordCodec::decode([
                'op' => '+',
                'l' => [
                    'word' => self::W1_NAME,
                    'args' => [[
                        'atom' => 'x0',
                    ]],
                ],
                'r' => [
                    'atom' => 'x1',
                ],
            ], $dict);
            self::fail('арность 1 != 2 должна отказывать');
        } catch (WordCodecException $e) {
            self::assertStringContainsString('arity mismatch', $e->getMessage());
        }
        // отсутствующее слово
        try {
            WordCodec::decode([
                'op' => '+',
                'l' => [
                    'word' => 'BWdeadbeefdeadbeefdeadbeefdeadbeef',
                    'args' => [[
                        'atom' => 'x0',
                    ], [
                        'atom' => 'x1',
                    ]],
                ],
                'r' => [
                    'atom' => 'x1',
                ],
            ], $dict);
            self::fail('неизвестное слово должно отказывать');
        } catch (WordCodecException $e) {
            self::assertStringContainsString('unknown word', $e->getMessage());
        }
        // то же через мутационный путь: ref с потерянным аргументом (args count<arity)
        $parent = $this->parentGenome();
        $broken = $parent;
        array_pop($broken['l']['args']);
        try {
            WordCodec::decode($broken, $dict);
            self::fail('сломанный мутацией ref должен отказывать явно');
        } catch (WordCodecException $e) {
            self::assertStringContainsString('arity mismatch', $e->getMessage());
        }
    }

    // ── C7: совместимость с мутатором GENOME-002 ───────────────────────

    public function testC7Genome002MutatorOffspringAlwaysDecodable(): void
    {
        $dict = $this->dictionary();
        $parent = $this->parentGenome();
        $rows = \GenomeBenchmarkLib::evalRows();
        $parentVals = \GenomeBenchmarkLib::evalAst(WordCodec::decode($parent, $dict), $rows);
        $decoded = 0;
        for ($i = 1; $i <= 100; $i++) {
            $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(20261021 + 7919 * $i));
            $res = \GenomeEvolveLib::mutateGenome($parent, $rng, $dict);
            if ($res['noop']) {
                continue;
            }
            $child = WordCodec::decode($res['genome'], $dict);
            $vals = \GenomeBenchmarkLib::evalAst($child, $rows);
            self::assertCount(count($rows), $vals, "case {$i}: вектор полной длины");
            ++$decoded;
        }
        self::assertGreaterThan(50, $decoded, 'мутатор GENOME-002 производит декодируемых потомков');
        // контроль: родитель исполняется (санity)
        self::assertCount(count($rows), $parentVals);
    }
}
