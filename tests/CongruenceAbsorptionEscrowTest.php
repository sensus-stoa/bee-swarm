<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\ExpressionNormalizer;
use BeeSwarm\Hive\EscrowStore;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;

/**
 * V0.15 WU-4: эскроу и конгруэнтные классы.
 *
 * Критерий стори: конгруэнтные формулы делят эскроу — не удваивают награду.
 * Механика WU-2 закрывает это структурно: поглощённая форма не создаёт
 * вторую строку закона, её recordDiscovery выходит по inserted=false-ветке
 * ДО payDiscovery и ДО spawnForLaw. Эти тесты — регрессионные пины контракта
 * «одно наблюдательное явление = одна выплата» (поимённо в progress.md).
 */
final class CongruenceAbsorptionEscrowTest extends TestCase
{
    private string $logFile;

    private Hive $hive;

    protected function setUp(): void
    {
        Database::reset();
        Database::get();
        // Split-механика (grace-путь — отдельное поведение, EscrowWiringTest).
        putenv('ESCROW_GRACE_TASKS=0');
        $this->logFile = tempnam(sys_get_temp_dir(), 'congesc_');
        $this->hive = new Hive(maxTicks: 0, logFile: $this->logFile);
        $this->hive->run();
    }

    protected function tearDown(): void
    {
        putenv('ESCROW_GRACE_TASKS');
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        Database::setPath(':memory:');
        Database::reset();
    }

    private function injectRoutedBee(): void
    {
        $bees = new \ReflectionProperty(Hive::class, 'bees');
        $bees->setAccessible(true);
        $all = $bees->getValue($this->hive);
        $first = array_values(array_filter($all, fn ($b) => $b->isAlive()))[0] ?? null;
        self::assertNotNull($first, 'bootstrap дал живую пчелу');
        $rb = new \ReflectionProperty(Hive::class, 'routedBee');
        $rb->setAccessible(true);
        $rb->setValue($this->hive, $first);
    }

    /**
     * Живой путь recordDiscovery (ReflectionMethod + by-ref, invokeArgs-прецедент).
     * rows = [x0, x1, y=(x0+x1)^2], детерминированный домен [0,40).
     */
    private function recordForm(string $atom, string $fp, float $shift): void
    {
        $this->injectRoutedBee();
        $rows = [];
        for ($i = 0; $i < 120; ++$i) {
            $x0 = fmod($i * 0.41 + $shift, 40.0);
            $x1 = fmod($i * 1.37 + $shift, 40.0);
            $s = $x0 + $x1;
            $rows[] = [$x0, $x1, $s * $s];
        }
        $X = array_map(static fn (array $r): array => [$r[0], $r[1]], $rows);
        $y = array_column($rows, 2);
        $foundAny = false;
        $m = new \ReflectionMethod(Hive::class, 'recordDiscovery');
        $m->setAccessible(true);
        $m->invokeArgs($this->hive, [
            [
                'atom' => $atom,
                'cv' => 0.01,
                'class' => 'EMPIRICAL',
            ],
            [
                'name' => 'cong4',
                'domain' => 'escC',
                'fingerprint' => $fp,
                'data' => $rows,
            ],
            'escC',
            &$foundAny,
            $X,
            $y,
        ]);
    }

    private function countRows(string $table): int
    {
        return (int) Database::get()->query("SELECT COUNT(*) FROM {$table} WHERE domain='escC'")->fetchColumn();
    }

    public function testAbsorbedMemberDoesNotCreateSecondEscrow(): void
    {
        $this->recordForm('((x0+x1)×(x0+x1))', 'fp_1', 0.0);
        $escrowAfterA = $this->countRows('law_escrow');
        $vTasksAfterA = $this->countRows('verification_tasks');
        $this->assertSame(1, $escrowAfterA, 'закон A: один депозит (split-ветка)');
        $this->assertGreaterThan(0, $vTasksAfterA, 'санити: V-батч A создан');

        $this->recordForm('(((x0×x0)+((x0×x1)+(x0×x1)))+(x1×x1))', 'fp_2', 3.0);

        $this->assertSame(1, $this->countRows('laws'), 'одна строка закона (WU-2)');
        $this->assertSame($escrowAfterA, $this->countRows('law_escrow'), 'второй депозит не создан');
        $this->assertSame($vTasksAfterA, $this->countRows('verification_tasks'), 'V-батч не удвоен');
    }

    public function testSettleOncePaysClass(): void
    {
        $this->recordForm('((x0+x1)×(x0+x1))', 'fp_1', 0.0);
        $this->recordForm('(((x0×x0)+((x0×x1)+(x0×x1)))+(x1×x1))', 'fp_2', 3.0);
        $canonA = ExpressionNormalizer::normalize('((x0+x1)×(x0+x1))');
        $canonB = ExpressionNormalizer::normalize('(((x0×x0)+((x0×x1)+(x0×x1)))+(x1×x1))');
        $esc = new EscrowStore();

        $paid = $esc->settle($canonA, 'escC', 'consensus');
        $this->assertGreaterThan(0.0, $paid, 'один settle закрывает класс');
        // У B нет собственной эскроу-строки — выплатить «второй раз» нечего.
        $this->assertSame(0.0, $esc->settle($canonB, 'escC', 'double-pay attempt'));
    }

    public function testIncongruentLawsPaySeparately(): void
    {
        $this->recordForm('(x0+x1)', 'fp_1', 0.0);
        $this->recordForm('(x0×x1)', 'fp_2', 1.0);

        $this->assertSame(2, $this->countRows('laws'), 'два разных закона');
        $this->assertSame(2, $this->countRows('law_escrow'), 'два явления = два эскроу (экономика не слила лишнего)');
    }
}
