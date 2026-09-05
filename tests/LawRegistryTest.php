<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\LawRegistry;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * T-диссипация Phase 3 (story DISSIPATION-LOOP): LawRegistry + preservation аудит.
 *
 * §2.5.4 Law preservation: реестр законов по поколениям. Аудит на gen 15:
 * закон существует И CV подтверждается на свежих данных задачи → иначе
 * событие LOSS (закон потерян роем — диссипация).
 *
 * Контракт (progress.md): наблюдатель, не стоп-кран. LOSS — событие лога,
 * потребитель — atom-penalty §2.5.6 (Phase 5).
 */
final class LawRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        Database::reset();
        Database::get();
    }

    protected function tearDown(): void
    {
        Database::setPath(':memory:');
        Database::reset();
    }

    private function seedLaw(string $formula, int $generation): void
    {
        Database::get()->prepare(
            'INSERT INTO laws (name, formula, cv, domain, generation) VALUES (?, ?, 0.005, ?, ?)'
        )->execute(['law_' . md5($formula), $formula, 'test_pres', $generation]);
    }

    /** RED: register пишет закон с поколением открытия. */
    public function testRegisterStoresGeneration(): void
    {
        $registry = new LawRegistry(preserveCheckGen: 15, eps: 0.15);
        $registry->register('(x0×K2)', 'test_pres', generation: 2);

        self::assertTrue($registry->exists('(x0×K2)', 'test_pres'));
        self::assertSame(2, $registry->generationOf('(x0×K2)', 'test_pres'));
    }

    /** RED: аудит до порога поколений — пусто (ещё рано). */
    public function testAuditBeforeThresholdReturnsEmpty(): void
    {
        $registry = new LawRegistry(preserveCheckGen: 15, eps: 0.15);
        $registry->register('(x0×K2)', 'test_pres', generation: 2);

        self::assertSame([], $registry->audit(currentGeneration: 10, eps: 0.15));
    }

    /** RED: закон жив и CV подтверждается → нет LOSS. */
    public function testAliveLawNoLoss(): void
    {
        $registry = new LawRegistry(preserveCheckGen: 15, eps: 0.15);
        $registry->register('(x0×K2)', 'test_pres', generation: 1);

        // закон жив в текущем reservoir
        $losses = $registry->audit(
            currentGeneration: 20,
            eps: 0.15,
            aliveFormulas: ['(x0×K2)'],
            revalidate: fn (string $f, string $dom): bool => true // CV подтверждается
        );

        self::assertSame([], $losses);
    }

    /** RED: закон исчез из reservoir → LOSS-событие. */
    public function testVanishedLawLoss(): void
    {
        $registry = new LawRegistry(preserveCheckGen: 15, eps: 0.15);
        $registry->register('(x0×K2)', 'test_pres', generation: 1);
        $registry->register('(x0+K1)', 'test_pres', generation: 1);

        // (x0×K2) исчез из reservoir, (x0+K1) жив
        $losses = $registry->audit(
            currentGeneration: 20,
            eps: 0.15,
            aliveFormulas: ['(x0+K1)'],
            revalidate: fn (string $f, string $dom): bool => true
        );

        self::assertCount(1, $losses, 'исчезнувший закон = LOSS');
        self::assertSame('(x0×K2)', $losses[0]['formula']);
        self::assertSame('LOSS', $losses[0]['event']);
    }

    /** RED: закон жив, но CV не подтверждается на свежих данных → LOSS. */
    public function testDeadCVLoss(): void
    {
        $registry = new LawRegistry(preserveCheckGen: 15, eps: 0.15);
        $registry->register('(x0×K2)', 'test_pres', generation: 1);

        $losses = $registry->audit(
            currentGeneration: 20,
            eps: 0.15,
            aliveFormulas: ['(x0×K2)'],
            revalidate: fn (string $f, string $dom): bool => false // CV сломался
        );

        self::assertCount(1, $losses);
        self::assertSame('LOSS', $losses[0]['event']);
        self::assertArrayHasKey('evidence', $losses[0]);
    }

    /** LOSS-событие несёт атрибуцию (formula, domain, generation, evidence). */
    public function testLossEventAttribution(): void
    {
        $registry = new LawRegistry(preserveCheckGen: 15, eps: 0.15);
        $registry->register('(x0×K2)', 'test_pres', generation: 3);

        $losses = $registry->audit(
            currentGeneration: 20,
            eps: 0.15,
            aliveFormulas: [],
            revalidate: fn (string $f, string $dom): bool => false
        );

        self::assertSame('test_pres', $losses[0]['domain']);
        self::assertSame(3, $losses[0]['generation']);
    }
}
