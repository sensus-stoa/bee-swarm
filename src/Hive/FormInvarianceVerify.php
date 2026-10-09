<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Core\FormHash;
use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Hive\LawIsomorphismCompressor;
use BeeSwarm\Infra\Database;

/**
 * S2.2-TER (§3.2.2) measurement: verify_2_2b.
 *
 * Measures the story criterion (conjunctive, prereg 10.10):
 *  (a) >=1 form in >=3 DISTINCT domains with params >=10x in one pair
 *      and cv <= 0.15 everywhere -> form_registry status 'invariant';
 *  (b) FPR guard: shuffled domains (y permuted, X intact) produce ZERO
 *      invariants.
 * Two form carriers (probe-verified 10.10): class A power (y = k*x^2,
 * k in {1,5,25}) and class B flux (BW-atom molecule, kappa in {1,5,25}).
 *
 * Logic separated from the CLI script (scripts/verify/verify_2_2b.php):
 * unit-testable, script is a thin wrapper (EnvPressureVerify precedent).
 * Self-sufficient run: deletes birth dictionary + fixture laws first
 * (guard: :memory: or opt-in env only).
 */
final class FormInvarianceVerify
{
    private const ENV_DESTRUCTIVE_OK = 'V22B_DESTRUCTIVE_OK';

    private const N_ROWS = 60;

    private const CV_EXACT = 1e-6;

    private const KAPPA_VALS = [1.0, 5.0, 25.0];

    private const K_VALS = [1.0, 5.0, 25.0];

    public const REPORT_ENV_KEYS = ['SWARM_DB_PATH', 'FORAGER_SOURCES', 'SEARCH_BEAM_K', 'V22B_DESTRUCTIVE_OK'];

    private static ?array $lastReport = null;

    public static function report(): array
    {
        if (self::$lastReport !== null) {
            return self::$lastReport;
        }
        return self::$lastReport = self::runOnce();
    }

    public static function reset(): void
    {
        self::$lastReport = null;
    }

    private static function runOnce(): array
    {
        self::assertDatabaseIsDisposable();
        $db = Database::get();
        // Self-sufficient: clean the language dictionary and fixture laws.
        $db->exec("DELETE FROM grammar_ops WHERE source = 'birth'");
        $db->exec("DELETE FROM laws WHERE name LIKE 'law_v22b%'");
        $db->exec('DELETE FROM form_registry');
        ExpressionEvaluator::resetCaches();
        FormHash::clearCache();

        $envFacts = [];
        foreach (self::REPORT_ENV_KEYS as $key) {
            $envFacts[$key] = getenv($key) === false ? null : (string) getenv($key);
        }

        $bootAtoms = self::bootstrapFluxAtom();
        $invariants = self::runPowerClass();
        $flux = $bootAtoms !== null ? self::runFluxClass() : ['ok' => false, 'why' => 'no atom born'];
        if ($flux['ok'] ?? false) {
            $invariants = max($invariants, (int) $flux['invariants']);
        }
        // FPR baseline BEFORE shuffle: the guard counts invariants ADDED by
        // shuffled domains, not the table (power/flux invariants live there).
        $invBaseline = (int) Database::get()->query("SELECT COUNT(*) FROM form_registry WHERE status='invariant'")->fetchColumn();
        $fpr = self::runShuffleGuard($invBaseline);

        // Conjunctive criterion: >=1 invariant form (either carrier) AND
        // zero FPR invariants.
        $pass = $invariants >= 1 && ($fpr['invariants'] === 0);

        return [
            'pass' => $pass,
            'invariants' => $invariants,
            'power' => ['ok' => true, 'invariants' => $invariants],
            'flux' => $flux,
            'fpr' => $fpr,
            'env' => $envFacts,
            'pending' => self::pending($invariants, $fpr, $bootAtoms),
        ];
    }

    /**
     * Criterion-pending diagnostics (PENDING-строки для не-накопленных
     * условий — EnvPressureVerify-паттерн).
     *
     * @return list<string>
     */
    private static function pending(int $invariants, array $fpr, ?string $atom): array
    {
        $out = [];
        if ($atom === null) {
            $out[] = 'no BW atom born from bootstrap domains';
        }
        if ($invariants === 0) {
            $out[] = 'no invariant form yet (<3 domains or <10x params or cv>0.15)';
        }
        if (($fpr['invariants'] ?? 1) !== 0) {
            $out[] = 'FPR GUARD FAILED: shuffle produced an invariant';
        }
        return $out;
    }

    /**
     * Guard: the run deletes the birth dictionary and fixture laws.
     */
    private static function assertDatabaseIsDisposable(): void
    {
        $path = getenv('SWARM_DB_PATH');
        if ($path !== false && str_ends_with($path, ':memory:')) {
            return;
        }
        if (getenv(self::ENV_DESTRUCTIVE_OK) === '1') {
            return;
        }
        throw new \RuntimeException(
            'FormInvarianceVerify deletes grammar_ops birth dictionary; refusing persistent DB path '
            . var_export($path === false ? '(unset)' : $path, true)
            . ' - set ' . self::ENV_DESTRUCTIVE_OK . '=1 to override'
        );
    }

    /**
     * Numeric-only grammar (semantic ops excluded - has-тень класс).
     */
    private static function numericGrammar(): Grammar
    {
        $g = new Grammar();
        $g->restrictTo(['+', '×', '−', '/', 'sq', 'cube', 'sqrt', 'max', 'min']);
        return $g;
    }

    /**
     * Deterministic LCG in [0,1): no global MT state touched.
     */
    private static function rng(int $seed): callable
    {
        $state = $seed & 0x7FFFFFFF;
        if ($state === 0) {
            $state = 1;
        }
        return static function () use (&$state): float {
            $state = (int) (($state * 48271) % 2147483647);

            return $state / 2147483647;
        };
    }

    /**
     * Class B bootstrap: two diff domains -> laws -> compressor -> BW atom.
     * Returns the atom name or null.
     */
    private static function bootstrapFluxAtom(): ?string
    {
        $db = Database::get();
        foreach ([[11, 5.0, 5.0, 'dA'], [22, 1.0, 3.0, 'dB']] as [$seed, $loBase, $span, $dom]) {
            [$X, $y] = self::mkDiffDomain(self::rng($seed), $loBase, $span);
            $res = Search::find($X, $y, self::numericGrammar(), 2, null, 0.0, self::CV_EXACT, 30.0);
            if (! $res[0]) {
                return null;
            }
            $stmt = $db->prepare('INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)');
            $stmt->execute(['law_v22b_boot_' . $dom, (string) $res[2], (float) $res[1], $dom]);
        }
        (new LawIsomorphismCompressor())->compress(['dA', 'dB']);
        $row = $db->query("SELECT name FROM grammar_ops WHERE source='birth' LIMIT 1")->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? (string) $row['name'] : null;
    }

    /**
     * Class A: three power domains (y = k*x^2) -> laws -> FormRegistry.
     * Returns the number of invariant forms in form_registry.
     */
    private static function runPowerClass(): int
    {
        $db = Database::get();
        foreach (self::K_VALS as $i => $k) {
            [$X, $y] = self::mkPowerDomain(self::rng(101 + $i * 7), $k);
            $res = Search::find($X, $y, self::numericGrammar(), 2, null, 0.0, self::CV_EXACT, 30.0);
            if ($res[0]) {
                self::recordViaHiveHook('pow' . $i, (string) $res[2], (float) $res[1], $X, $y, 'law_v22b_pow' . $i);
            }
        }
        return (int) $db->query("SELECT COUNT(*) FROM form_registry WHERE status='invariant'")->fetchColumn();
    }

    /**
     * Class B: three flux domains through the BW atom -> FormRegistry.
     *
     * @return array{ok: bool, invariants?: int, atom?: string, found?: int, why?: string}
     */
    private static function runFluxClass(): array
    {
        $atom = (string) Database::get()->query("SELECT name FROM grammar_ops WHERE source='birth' LIMIT 1")->fetchColumn();
        if ($atom === '') {
            return ['ok' => false, 'why' => 'no atom'];
        }
        $found = 0;
        foreach (self::KAPPA_VALS as $i => $kappa) {
            [$X, $y] = self::mkFluxDomain(self::rng(501 + $i * 13), $kappa);
            $res = Search::find($X, $y, self::numericGrammar(), 3, null, 0.0, self::CV_EXACT, 40.0);
            if ($res[0]) {
                $found++;
                self::recordViaHiveHook('flux' . $i, (string) $res[2], (float) $res[1], $X, $y, 'law_v22b_flux' . $i);
            }
        }
        if ($found === 0) {
            return ['ok' => false, 'why' => 'no flux domain solved', 'atom' => $atom];
        }
        $invariants = (int) Database::get()->query("SELECT COUNT(*) FROM form_registry WHERE status='invariant'")->fetchColumn();

        return ['ok' => true, 'invariants' => $invariants, 'atom' => $atom, 'found' => $found];
    }

    /**
     * FPR guard: shuffle (y permuted within domain, X intact) on the three
     * power domains. Zero invariants ADDED by shuffled domains expected.
     *
     * @return array{invariants: int, found: int}
     */
    private static function runShuffleGuard(int $invBaseline): array
    {
        $found = 0;
        foreach (self::K_VALS as $i => $k) {
            [$X, $y] = self::mkPowerDomain(self::rng(101 + $i * 7), $k);
            // Fisher-Yates with own LCG: x untouched, y permuted
            $shuf = self::rng(901 + $i);
            for ($j = count($y) - 1; $j > 0; $j--) {
                $t = (int) floor($shuf() * ($j + 1));
                [$y[$j], $y[$t]] = [$y[$t], $y[$j]];
            }
            $res = Search::find($X, $y, self::numericGrammar(), 2, null, 0.0, self::CV_EXACT, 30.0);
            if ($res[0]) {
                $found++;
                self::recordViaHiveHook('shuf' . $i, (string) $res[2], (float) $res[1], $X, $y, 'law_v22b_shuf' . $i);
            }
        }
        $invariants = (int) Database::get()->query("SELECT COUNT(*) FROM form_registry WHERE status='invariant'")->fetchColumn() - $invBaseline;

        return ['invariants' => $invariants, 'found' => $found];
    }

    /**
     * Record a discovery through the LIVE Hive hook (ReflectionMethod on
     * the private registerFormInvariance - the measurement exercises the
     * production path, not a side door).
     */
    private static function recordViaHiveHook(string $domain, string $formula, float $cv, array $X, array $y, string $lawName): void
    {
        $stmt = Database::get()->prepare('INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)');
        $stmt->execute([$lawName, $formula, $cv, $domain]);
        // maxTicks 0 + run() = bootstrap only, no ticks (ContradictionWiringTest
        // precedent). FORAGER_SOURCES guard: an unset env makes bootstrap scan
        // the operator's home dirs and overwrite the fixture pool.
        $hadSources = getenv('FORAGER_SOURCES');
        if ($hadSources === false) {
            putenv('FORAGER_SOURCES=:');
        }
        $logFile = tempnam(sys_get_temp_dir(), 'v22b_hive_');
        $hive = new \BeeSwarm\Hive\Hive(maxTicks: 0, logFile: $logFile);
        $hive->run();
        $m = new \ReflectionMethod(\BeeSwarm\Hive\Hive::class, 'registerFormInvariance');
        $m->setAccessible(true);
        $m->invoke($hive, ['atom' => $formula, 'cv' => $cv, 'name' => $lawName], $domain, $X, $y);
        if (is_file($logFile)) {
            unlink($logFile);
        }
        if ($hadSources === false) {
            putenv('FORAGER_SOURCES');
        }
    }

    private static function mkPowerDomain(callable $r, float $k): array
    {
        $X = [];
        $y = [];
        for ($i = 0; $i < self::N_ROWS; $i++) {
            $x = 0.5 + $r() * 9.0;
            $X[] = [$x];
            $y[] = $k * $x * $x;
        }

        return [$X, $y];
    }

    private static function mkDiffDomain(callable $r, float $loBase, float $span): array
    {
        $X = [];
        $y = [];
        for ($i = 0; $i < 30; $i++) {
            $lo = $loBase + $r() * $span;
            $hi = $lo + 2.0 + $r() * 8.0;
            $X[] = [$lo, $hi];
            $y[] = $hi - $lo;
        }

        return [$X, $y];
    }

    private static function mkFluxDomain(callable $r, float $kappa): array
    {
        $X = [];
        $y = [];
        for ($i = 0; $i < 30; $i++) {
            $g = 0.6 + $r() * 1.8;
            $tc = 2.0 + $r() * 4.0;
            $th = $tc + 5.0 + $r() * 10.0;
            $a = 1.0 + $r();
            $d = 0.5 + $r();
            $X[] = [$g, $tc, $th, $a, $d];
            $y[] = $kappa * $g * ($th - $tc) * $a / $d;
        }

        return [$X, $y];
    }
}
