<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\WordCodec;

require_once dirname(__DIR__) . '/scripts/genome001/corpus_lib.php';

/**
 * GENOME-001 CHECKPOINT B (п.8 инструкции owner): property-based roundtrip
 * на случайных допустимых AST со вложенными словами и разными binding.
 *
 * Детерминировано: мастер-сид 20261015 (зафиксирован), случаи выводятся из
 * него фиксированной формулой (case i → сид 20261015 + 7919*i). Словарь
 * каждого случая извлекается из 4 случайных REGULAR-train программ;
 * программа — REGULAR k∈{1..5}. Свойства:
 *   P1 decode(encode(AST)) ≡ AST (структурно/канонически);
 *   P2 decodeExpanded(encode(AST)) ≡ AST;
 *   P3 численная эквивалентность original/decoded/expanded (1e-9, null-паттерн);
 *   P4 одинаковые входы → байт-идентичный геном (детерминизм кодека).
 *
 * Небольшой по объёму (60 случаев, <1s) — не отдельный проект (рамка owner).
 */
final class GenomeCodecPropertyTest extends TestCase
{
    private const MASTER_SEED = 20261015;

    private const CASES = 60;

    public function testPropertyRoundtripRandomAstsWithWords(): void
    {
        $rows = \GenomeBenchmarkLib::evalRows();
        for ($i = 1; $i <= self::CASES; $i++) {
            $seed = self::MASTER_SEED + 7919 * $i;
            $train = [];
            for ($t = 1; $t <= 4; $t++) {
                $train[] = \GenomeBenchmarkLib::regularProgram(2, $this->rng($seed + 31 * $t));
            }
            $entries = \GenomeBenchmarkLib::extractDictionary($train);
            $prog = \GenomeBenchmarkLib::regularProgram(1 + $seed % 5, $this->rng($seed));
            $genome = WordCodec::encode($prog['ast'], $entries);
            $decoded = WordCodec::decode($genome, $entries);
            // P1
            self::assertTrue(
                WordCodec::astEqual($decoded, $prog['ast']),
                "case {$i} (seed {$seed}): P1 decode ≡ AST"
            );
            // P2
            $expanded = WordCodec::decodeExpanded($genome, $entries);
            self::assertTrue(
                WordCodec::astEqual($expanded, $prog['ast']),
                "case {$i} (seed {$seed}): P2 decodeExpanded ≡ AST"
            );
            // P3 (все программы корпуса — полная грамматика без R-атомов)
            $orig = \GenomeBenchmarkLib::evalAst($prog['ast'], $rows);
            self::assertTrue(
                \GenomeBenchmarkLib::vectorsEqual($orig, \GenomeBenchmarkLib::evalAst($decoded, $rows)),
                "case {$i} (seed {$seed}): P3 numeric decoded"
            );
            self::assertTrue(
                \GenomeBenchmarkLib::vectorsEqual($orig, \GenomeBenchmarkLib::evalAst($expanded, $rows)),
                "case {$i} (seed {$seed}): P3 numeric expanded"
            );
        }
        self::assertGreaterThan(0, self::CASES);
    }

    public function testPropertyEncodeDeterministicSameSeed(): void
    {
        // P4: одинаковые входы → байт-идентичный геном
        for ($i = 1; $i <= 8; $i++) {
            $seed = self::MASTER_SEED + 104729 * $i;
            $train = [];
            for ($t = 1; $t <= 4; $t++) {
                $train[] = \GenomeBenchmarkLib::regularProgram(2, $this->rng($seed + 31 * $t));
            }
            $entries = \GenomeBenchmarkLib::extractDictionary($train);
            $prog = \GenomeBenchmarkLib::regularProgram(3, $this->rng($seed));
            $g1 = WordCodec::encode($prog['ast'], $entries);
            $g2 = WordCodec::encode(\GenomeBenchmarkLib::cloneAst($prog['ast']), $entries);
            self::assertSame(
                json_encode($g1),
                json_encode($g2),
                "case {$i} (seed {$seed}): P4 детерминизм encode"
            );
        }
    }

    private function rng(int $seed): \Random\Randomizer
    {
        return new \Random\Randomizer(new \Random\Engine\Mt19937($seed));
    }
}
