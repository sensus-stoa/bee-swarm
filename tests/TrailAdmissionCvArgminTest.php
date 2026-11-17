<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Search;
use BeeSwarm\Infra\Database;

/**
 * EXP-061 prereg: TRAIL_ADMISSION_MODE=cv_argmin — выбор записи дороги по
 * per-candidate flight cv (argmin первым) вместо admission-proxy порядка.
 *
 * Механизм E60 (Trail-телеметрия): write-gate (eval-based, proxy-порядок)
 * писал контаминацию (c0+c5) каждый полёт, а cv-argmin партиал
 * (c5+(c0×c2²)) оставался незаписанным — proxy-порядок + admission-окно
 * хоронили лучшую форму. Данные cv УЖЕ в plausible-кандидатах
 * (signalForms/stairForms/petalForms: 'cv' => $cv, Search.php:1917-1979) —
 * флаг меняет только ключ ранжирования: candidate cv asc, proxy — fallback
 * для cv-меньших. Default (env нет) = proxy-порядок байт-в-байт.
 *
 * Связь с E59 amendment 2: write-decision = road protocol (не mutation/
 * ranking/beam width) — owner-классификация 11.11.
 */
final class TrailAdmissionCvArgminTest extends TestCase
{
    private ?string $modeBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        // The fresh default regardless of the neighbors (the review #9): a
        // neighbor's skipped tearDown (a fatal) leaks cv_argmin into the
        // worker env — the WriteOn-class leak. The test = starts the default,
        // pins per-test, ends the default.
        putenv('TRAIL_ADMISSION_MODE');
        $this->modeBackup = null;
        Database::get()->exec('DELETE FROM trail_marks');
    }

    protected function tearDown(): void
    {
        // Мои метки не должны жить в :memory: БД воркера после теста:
        // сосед (RouteExecutorWriteOnTest) на той же БД получает забитую
        // дорогу -> capacity/ghost режут его записи до нуля (pitfall #52).
        Database::get()->exec('DELETE FROM trail_marks');
        if ($this->modeBackup === null) {
            putenv('TRAIL_ADMISSION_MODE');
        } else {
            putenv('TRAIL_ADMISSION_MODE=' . $this->modeBackup);
        }
        parent::tearDown();
    }

    /**
     * @return array{0: list<list<float>>, 1: list<float>}
     */
    private static function task(int $nRows = 40): array
    {
        mt_srand(11);
        $X = [];
        $y = [];
        for ($i = 0; $i < $nRows; $i++) {
            $row = [];
            for ($j = 0; $j < 6; $j++) {
                $row[] = -2.0 + mt_rand() / mt_getrandmax() * 4.0; // [-2, 2]
            }
            $X[] = $row;
            // y тянет corr((c0+c5), y) вверх: прокси-порядок детерминирован
            $y[] = $row[0] + $row[5] + 0.1 * ($row[3] * $row[4]);
        }

        return [$X, $y];
    }

    /**
     * @param list<array<string, mixed>> $plausible
     * @return list<string>
     */
    private function invokeWrite(array $plausible, array $X, array $y): array
    {
        $labels = [];
        foreach (range(0, 5) as $j) {
            $labels[$j] = 'c' . $j;
        }
        $m = new \ReflectionMethod(Search::class, 'writeTrailMarks');
        $m->setAccessible(true);
        $m->invoke(null, $labels, $plausible, 9.99, INF, [], [], $X, $y);

        return Search::$lastTrailWritten ?? [];
    }

    public function testCvArgminWritesBestCvFormFirst(): void
    {
        putenv('TRAIL_ADMISSION_MODE=cv_argmin');
        [$X, $y] = self::task();
        // A = proxy-winner (высокий corr с y), B = cv-argmin (лучший flight cv)
        $plausible = [
            ['name' => '(c0+c5)', 'cv' => 0.30, 'corr' => 0.40],
            ['name' => '(c5+(c0×c2²))', 'cv' => 0.15, 'corr' => 0.35],
        ];
        $written = $this->invokeWrite($plausible, $X, $y);
        $this->assertNotEmpty($written, 'both candidates eligible and admitted under capacity');
        $this->assertSame(
            '(c5+(c0×c2²))',
            $written[0],
            'E60 mechanism fix: cv-argmin form must be written FIRST (the road carries the best partial, not the proxy winner)'
        );
        $this->assertContains('(c0+c5)', $written, 'second candidate still admitted under capacity');
    }

    public function testDefaultModeKeepsProxyOrder(): void
    {
        // env отсутствует = eval mode = текущее поведение без изменений
        [$X, $y] = self::task();
        $plausible = [
            ['name' => '(c0+c5)', 'cv' => 0.30, 'corr' => 0.40],
            ['name' => '(c5+(c0×c2²))', 'cv' => 0.15, 'corr' => 0.35],
        ];
        $written = $this->invokeWrite($plausible, $X, $y);
        $this->assertNotEmpty($written);
        $this->assertSame(
            '(c0+c5)',
            $written[0],
            'default mode must keep the proxy order byte-identical (E53-E60 semantics preserved)'
        );
    }

    public function testEvalEligibilityGateStaysInCvMode(): void
    {
        putenv('TRAIL_ADMISSION_MODE=cv_argmin');
        [$X, $y] = self::task();
        $plausible = [
            ['name' => '(c5+(c0×c2²))', 'cv' => 0.15, 'corr' => 0.35],
            ['name' => '(cZZ×c4)', 'cv' => 0.01, 'corr' => 0.90], // unresolvable label = EVAL_FAIL
        ];
        $written = $this->invokeWrite($plausible, $X, $y);
        $this->assertContains('(c5+(c0×c2²))', $written, 'eligible candidate admitted in cv mode');
        $this->assertNotContains(
            '(cZZ×c4)',
            $written,
            'eval eligibility gate must stay in cv mode: unresolvable labels never written'
        );
    }
}
