<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Core\WordCodec;
use BeeSwarm\Core\WordCodecException;

/**
 * EVO-FIX-001: тесты двух исправлений.
 *
 * FIX-2 (WordCodec nested same-word): вложенное самоменение W(W(a,b,c),d,e)
 * легально; циклы определений (прямой W→W и косвенный W1→W2→W1) отклоняются;
 * арность по-прежнему охраняется; семантика переживает clean-process roundtrip.
 *
 * FIX-1 (world-transition fitness): переносимые особи переоцениваются на
 * данных нового мира, получают world_id/fitness_hash; на старой реализации
 * (без transitionReevaluate) тест воспроизводил сохранение устаревших оценок.
 */
final class EvoFix001Test extends TestCase
{
    private const W1_NAME = 'BW11111111111111111111111111111111';

    private const W1_DEF = '((x0/x1)−x2)'; // арность 3

    private const W2_NAME = 'BW22222222222222222222222222222222';

    private function dictionary(): array
    {
        return WordCodec::buildDictionary(
            [
                self::W1_NAME => self::W1_DEF,
            ],
            [self::W1_NAME]
        );
    }

    private function rows(): array
    {
        mt_srand(4242);
        $rows = [];
        for ($i = 0; $i < 8; $i++) {
            $rows[] = [mt_rand(10, 50) / 10, mt_rand(10, 50) / 10, mt_rand(10, 50) / 10, mt_rand(10, 50) / 10];
        }

        return $rows;
    }

    // ── FIX-2 ──────────────────────────────────────────────────────────

    public function testFix2NestedSameWordApplicationValid(): void
    {
        $dict = $this->dictionary();
        // W(W(x3,x2,x0), x1, (x2−x1)×x5) — репродуктор из EVO-DIAG-001
        $genome = [
            'word' => self::W1_NAME,
            'args' => [
                [
                    'word' => self::W1_NAME,
                    'args' => [[
                        'atom' => 'x3',
                    ], [
                        'atom' => 'x2',
                    ], [
                        'atom' => 'x0',
                    ]],
                ],
                [
                    'atom' => 'x1',
                ],
                [
                    'op' => '×',
                    'l' => [
                        'op' => '−',
                        'l' => [
                            'atom' => 'x2',
                        ],
                        'r' => [
                            'atom' => 'x1',
                        ],
                    ],
                    'r' => [
                        'atom' => 'x5',
                    ],
                ],
            ],
        ];
        $decoded = WordCodec::decode($genome, $dict);
        // семантика: внутреннее W(x3,x2,x0) = (x3/x2)−x0; внешнее = (внутр/x1)−B, B=(x2−x1)×x5
        $expected = WordCodec::parseDefinition('(((x3/x2)−x0)/x1)−((x2−x1)×x5)');
        $rows = $this->rows();
        foreach ($rows as $i => $row) {
            $got = ExpressionEvaluator::evalNode($decoded, $row, [], []);
            $exp = ExpressionEvaluator::evalNode($expected, $row, [], []);
            if ($exp === null) {
                self::assertNull($got, "row {$i}");

                continue;
            }
            self::assertNotNull($got, "row {$i}");
            self::assertEqualsWithDelta($exp, $got, 1e-9 * max(1.0, abs($exp)), "row {$i}");
        }
    }

    public function testFix2DirectCycleRejected(): void
    {
        // W→W: определение ссылается само на себя
        $dict = [[
            'name' => self::W1_NAME,
            'arity' => 2,
            'genome' => [
                'word' => self::W1_NAME,
                'args' => [[
                    'atom' => 'x0',
                ], [
                    'atom' => 'x1',
                ]],
            ],
        ]];
        try {
            WordCodec::decodeDefinition($dict[0], $dict);
            self::fail('прямой цикл W→W должен отклоняться');
        } catch (WordCodecException $e) {
            self::assertStringContainsString('cyclic', $e->getMessage());
        }
    }

    public function testFix2IndirectCycleRejected(): void
    {
        // W1→W2→W1: косвенный цикл через определения
        $dict = [
            [
                'name' => 'BWa1',
                'arity' => 2,
                'genome' => [
                    'word' => 'BWb2',
                    'args' => [[
                        'atom' => 'x0',
                    ], [
                        'atom' => 'x1',
                    ]],
                ],
            ],
            [
                'name' => 'BWb2',
                'arity' => 2,
                'genome' => [
                    'op' => '+',
                    'l' => [
                        'word' => 'BWa1',
                        'args' => [[
                            'atom' => 'x0',
                        ], [
                            'atom' => 'x1',
                        ]],
                    ],
                    'r' => [
                        'atom' => 'K1',
                    ],
                ],
            ],
        ];
        try {
            WordCodec::decodeDefinition($dict[0], $dict);
            self::fail('косвенный цикл W1→W2→W1 должен отклоняться');
        } catch (WordCodecException $e) {
            self::assertStringContainsString('cyclic', $e->getMessage());
        }
    }

    public function testFix2NestedSemanticsCleanProcessRoundtrip(): void
    {
        $dict = $this->dictionary();
        $genome = [
            'word' => self::W1_NAME,
            'args' => [
                [
                    'word' => self::W1_NAME,
                    'args' => [[
                        'atom' => 'x3',
                    ], [
                        'atom' => 'x2',
                    ], [
                        'atom' => 'x0',
                    ]],
                ],
                [
                    'atom' => 'x1',
                ],
                [
                    'op' => '×',
                    'l' => [
                        'op' => '−',
                        'l' => [
                            'atom' => 'x2',
                        ],
                        'r' => [
                            'atom' => 'x1',
                        ],
                    ],
                    'r' => [
                        'atom' => 'x5',
                    ],
                ],
            ],
        ];
        $bundle = WordCodec::serializeBundle($dict, [
            'nested' => $genome,
        ]);
        $rows = $this->rows();
        $envelope = [
            'db' => tempnam(sys_get_temp_dir(), 'evofix_') . '.sqlite',
            'genome' => 'nested',
            'rows' => $rows,
            'bundle' => json_decode($bundle, true),
        ];
        $envFile = tempnam(sys_get_temp_dir(), 'evofix_env_') . '.json';
        file_put_contents($envFile, json_encode($envelope, JSON_UNESCAPED_UNICODE));
        try {
            $out = shell_exec(
                'timeout 60 ' . escapeshellarg(PHP_BINARY) . ' '
                . escapeshellarg(dirname(__DIR__) . '/scripts/genome001/decode_execute.php')
                . ' ' . escapeshellarg($envFile) . ' 2>&1'
            );
            $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $out))));
            $result = json_decode((string) end($lines), true);
            self::assertIsArray($result);
            self::assertTrue($result['ok'] ?? false, 'subprocess: ' . (string) ($result['error'] ?? ''));
            $local = [];
            $decoded = WordCodec::decode($genome, $dict);
            foreach ($rows as $row) {
                $local[] = ExpressionEvaluator::evalNode($decoded, $row, [], []);
            }
            self::assertCount(count($local), $result['values']);
            foreach ($local as $i => $v) {
                $w = $result['values'][$i];
                if ($v === null) {
                    self::assertNull($w, "row {$i}");

                    continue;
                }
                self::assertEqualsWithDelta($v, $w, 1e-9 * max(1.0, abs($v)), "row {$i}");
            }
        } finally {
            @unlink($envFile);
            @unlink((string) $envelope['db']);
        }
    }

    public function testFix2ArityStillRejected(): void
    {
        $dict = $this->dictionary();
        try {
            WordCodec::decode([
                'word' => self::W1_NAME,
                'args' => [[
                    'atom' => 'x0',
                ]],
            ], $dict);
            self::fail('арность 1 != 3 должна отклоняться');
        } catch (WordCodecException $e) {
            self::assertStringContainsString('arity mismatch', $e->getMessage());
        }
    }

    // ── FIX-1 ──────────────────────────────────────────────────────────

    public function testFix1TransitionReevaluatesCarriedFitness(): void
    {
        // На старой реализации переносимые особи сохраняли старо-мировые
        // train/valid (EVO-DIAG-001 Q2) — отбор gen-1 шёл по устаревшей шкале.
        // Исправленный transitionReevaluate обязан дать оценки НОВОГО мира.
        require_once dirname(__DIR__) . '/scripts/word_cum001/transition.php';
        require_once dirname(__DIR__) . '/scripts/word_cum001/worlds.php';
        require_once dirname(__DIR__) . '/scripts/genome002/evolve_lib.php';

        $world = \WordCumWorlds::worlds()[2]; // W2
        $w = 2;
        $hash = \WordCumTransition::fitnessHash($w, $world);
        $tasks = [];
        foreach ($world['tasks']['train'] as $ti => $t) {
            $tasks[$ti] = \WordCumWorlds::task($t['formula'], 20261201 + 31 * $ti);
        }

        // "перенесённые" особи: валидные программы W2 с ПОДДЕЛЬНЫМИ старо-мировыми
        // оценками (шкала заведомо иная) + старый hash
        $pops = [];
        foreach ($tasks as $ti => $task) {
            $pop = [];
            for ($i = 0; $i < 4; $i++) {
                $ast = WordCodec::parseDefinition('(x0×x1)+(x2+x' . $i . ')');
                $pop[] = [
                    'ast' => $ast,
                    'g' => null,
                    'lin' => 'old-' . $ti . '-' . $i,
                    'chain' => 0,
                    'train' => 0.001,
                    'valid' => 0.001,
                    'world_id' => 0,
                    'fitness_hash' => 'old-world-hash',
                ];
            }
            $pops[$ti] = $pop;
        }

        $evals = 0;
        $fixed = \WordCumTransition::reevaluate($pops, $tasks, $w, $hash, 'A', null, $evals, 100000);

        foreach ($fixed as $ti => $pop) {
            foreach ($pop as $ind) {
                self::assertSame($w, $ind['world_id'], 'world_id обновлён');
                self::assertSame($hash, $ind['fitness_hash'], 'fitness_hash обновлён');
                // оценки равны свежему вычислению нового мира
                $ast = $ind['ast'];
                $train = \BeeSwarm\Core\ExpressionEvaluator::evalNode($ast, $tasks[$ti]['train'][0], [], []) !== null
                    ? \GenomeEvolveLib::rmse($ast, $tasks[$ti]['train'], $tasks[$ti]['y_train'])
                    : null;
                self::assertEqualsWithDelta($train, $ind['train'], 1e-12, 'train пересчитан на новом мире');
                self::assertNotEquals(0.001, $ind['train'], 'старая оценка не могла выжить');
            }
        }
        self::assertGreaterThan(0, $evals, 'переоценка дебетует пул');
    }

    public function testFix1MixedWorldComparisonBlocked(): void
    {
        // защитный гард: сравнение особей с разными fitness_hash запрещено
        require_once dirname(__DIR__) . '/scripts/word_cum001/transition.php';
        $this->expectException(\RuntimeException::class);
        \WordCumTransition::assertComparable([[
            'fitness_hash' => 'a',
        ], [
            'fitness_hash' => 'b',
        ]]);
    }
}
