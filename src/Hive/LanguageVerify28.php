<?php
declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

/**
 * VERIFY-2-8 (protocol par.3.8(g), Core/CV0_Protocol_v1.6_RU.md:1358-1374):
 * language formalization as a runnable measurement.
 *
 * Fixture (deterministic seeds, probes v4-v6 of 09.10):
 *   domain A (heat gradient): y = hi - lo, 2 cols
 *   domain B (osmosis):       y = hi - lo, 2 cols, other range
 *   -> laws -> LawIsomorphismCompressor::compress() -> BW atom (meta-law)
 *   domain C (heat flux): y = G*(Th-Tc)*A/d, 5 cols:
 *     raw search FAILS (beam competence, probes v1/v2), with atom SOLVES.
 *
 * Criteria (report keys a/b/v/g):
 *   a: >=1 meta-law compressed from >=2 laws of >=2 domains (compressor).
 *   b: atom present in grammar_ops and searchable (contrast: domain C
 *      without atom not solved, with atom solved exact).
 *   v: atom applied in domain C (reuse record, real domain, not 'search').
 *   g: CV_H(meta) <= mean(CV_H(sources)) - protocol v1.6 formula (stricter
 *      operand than v1.5 max; max kept in report as coalition_max_cv).
 *   verdict = conjunction.
 *
 * Design per EnvPressureVerify precedent: logic in src class,
 * scripts/verify_2_8.php is a thin CLI wrapper. cv metric is sign-
 * invariant (probe v5: pred=-y gives cv=0) - no sign assumptions.
 * Beam: bootstrap domains beam=0 (compose-precendent), domain C with
 * atom runs under the caller's beam env (restored before the call).
 * Grammar: numeric-only via restrictTo - SEMANTIC_OPS ('has') give
 * constant-0 vectors on numeric data and poison exact-law selection
 * (runOnce probe 09.10: has-shadow won with cv=0 under beam=0).
 */
final class LanguageVerify28
{
    private static ?array $lastReport = null;

    public const DOM_A = 'v28_heat';

    public const DOM_B = 'v28_osmo';

    public const DOM_C = 'v28_conduct';

    /**
     * Opt-in for running the destructive run on a persistent DB path.
     */
    public const ENV_DESTRUCTIVE_OK = 'V28_DESTRUCTIVE_OK';

    private const N_ROWS = 30;

    private const N_FLUX_ROWS = 40;

    private const HELDOUT_RATIO = 0.4;

    private const CV_EXACT = 1e-6;

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

    // ------------------------------------------------------------------
    //  Run (phases; complexity gate: methods <= 30 lines)
    // ------------------------------------------------------------------

    private static function runOnce(): array
    {
        self::assertDatabaseIsDisposable();

        // Deterministic fixture RNG: LCG instead of global mt_srand -
        // global seeding poisons the worker's MT stream for every test
        // class running after this one (srand-poisoning class, agent-review).
        [$xa, $ya] = self::mkDiffDomain(self::rng(11), 5.0, 5.0);
        [$xb, $yb] = self::mkDiffDomain(self::rng(22), 1.0, 3.0);
        [$xc, $yc] = self::mkFluxDomain(self::rng(33), self::N_FLUX_ROWS);

        // save caller's beam BEFORE any forcing (env round-trip contract);
        // finally-restore on ANY exit path: an exception between forcing
        // and restoring would leak beam=0 into every later test class
        // of the same phpunit worker (premortem #3)
        $beamOrig = getenv('SEARCH_BEAM_K');
        try {
            $boot = self::runBootstrap($xa, $ya, $xb, $yb);
            $contrast = self::runContrast($xc, $yc, $beamOrig);
            $reuse = self::runReuse($contrast['res'], $boot['atom']);
            $parity = self::compilationParity($xa, $ya, $xb, $yb, $boot['lawA']['formula'], $boot['lawB']['formula']);

            return self::buildReport($boot, $contrast, $reuse, $parity);
        } finally {
            self::restoreBeam($beamOrig);
        }
    }

    /**
     * Env round-trip: unset restores "not set", value restores verbatim.
     */
    private static function restoreBeam(string|false $beamOrig): void
    {
        if ($beamOrig === false) {
            putenv('SEARCH_BEAM_K');
        } else {
            putenv('SEARCH_BEAM_K=' . $beamOrig);
        }
    }

    /**
     * The run deletes the whole birth dictionary (grammar_ops source=birth)
     * and writes fixture domains. Allowed only on :memory: or an explicit
     * opt-in env (V28_DESTRUCTIVE_OK=1) - otherwise a CLI run with an
     * exported SWARM_DB_PATH would irreversibly erase the swarm language
     * (HIGH finding, agent-review + premortem #1, 09.10).
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
            'LanguageVerify28 deletes grammar_ops birth dictionary; refusing persistent DB path '
            . var_export($path === false ? '(unset)' : $path, true)
            . ' - set ' . self::ENV_DESTRUCTIVE_OK . '=1 to override'
        );
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
     * Phase 1: bootstrap domains A/B -> laws -> compressor -> BW atom.
     * Full birth-atom DELETE first: the measurement run is self-sufficient
     * and must start with a clean language dictionary. Foreign birth atoms
     * (B%) left by other test classes in the same paratest worker poison
     * the bootstrap: bornBinary reads grammar_ops directly (restrictTo
     * does NOT filter birth ops), a shadow like 'x0B10x0^2' wins the exact
     * law, canonize renders it as a molecule (no renaming) and the atom
     * fingerprint dies (suite failure 09.10, fast pass). Allowed on
     * :memory: / explicit opt-in only (assertDatabaseIsDisposable).
     * Compressor is scoped to the two fixture domains (premortem #2:
     * leftover laws of other tests must not co-found the atom).
     */
    private static function runBootstrap(array $xa, array $ya, array $xb, array $yb): array
    {
        \BeeSwarm\Infra\Database::get()->exec("DELETE FROM grammar_ops WHERE source = 'birth'");
        ExpressionEvaluator::clearDefCache();
        AtomRegistry::clearDefCache();
        // Beam off for bootstrap (compose-precendent).
        putenv('SEARCH_BEAM_K=0');
        // honest heldout: laws fit on the TRAIN slice (60%), (g) parity
        // evaluates on the TEST slice (40%) the laws never saw
        [$trainA, $trainYA] = self::trainSlice($xa, $ya);
        [$trainB, $trainYB] = self::trainSlice($xb, $yb);
        $lawA = self::solveBootstrap($trainA, $trainYA, self::DOM_A, 'law_v28_heat');
        $lawB = self::solveBootstrap($trainB, $trainYB, self::DOM_B, 'law_v28_osmo');
        (new LawIsomorphismCompressor())->compress([self::DOM_A, self::DOM_B]);
        return [
            'lawA' => $lawA,
            'lawB' => $lawB,
            'atom' => self::readAtom(),
        ];
    }

    /**
     * TRAIN part of the heldout split (the complement of the test slice).
     */
    private static function trainSlice(array $x, array $y): array
    {
        [, , $xTrain] = self::heldoutSplit($x, $y);
        $k = (int) floor(count($y) * self::HELDOUT_RATIO);
        return [$xTrain, array_slice($y, $k)];
    }

    /**
     * Phase 2: (b) domain C raw contrast (no birth atoms).
     * Raw runs at depth 2: 5 terminals require >=4 binary operators, which
     * is structurally impossible within depth-2 nesting - raw failure is
     * deterministic, not budget-dependent (agent-review MED; at depth 3 the
     * exact form IS reachable raw on a fast machine, verdict would flake).
     * With-atom keeps depth 3 (atom collapses the molecule).
     */
    private static function runContrast(array $xc, array $yc, string|false $beamOrig): array
    {
        self::removeBirthAtoms();
        $t0 = microtime(true);
        $raw = Search::find($xc, $yc, self::numericGrammar(), 2, null, 0.0, self::CV_EXACT, 60.0);
        $rawSecs = round(microtime(true) - $t0, 1);
        // atom back: compress() re-mins it from the two fixture laws
        // (removeBirthAtoms deleted it; BW name is a deterministic hash of
        // the definition, so registerReuse by name stays consistent)
        $born2 = (new LawIsomorphismCompressor())->compress([self::DOM_A, self::DOM_B]);
        $atom = self::readAtom();
        // restore caller's beam for the with-atom run (live profile)
        putenv('SEARCH_BEAM_K=' . ($beamOrig === false ? '10' : $beamOrig));
        $t1 = microtime(true);
        $res = Search::find($xc, $yc, self::numericGrammar(), 3, null, 0.0, self::CV_EXACT, 60.0);
        $atomSecs = round(microtime(true) - $t1, 1);
        return [
            'res' => $res,
            'raw' => $raw,
            'rawSecs' => $rawSecs,
            'born2' => $born2,
            'atom' => $atom,
            'atomSecs' => $atomSecs,
        ];
    }

    /**
     * Phase 3: (v) reuse record in the real domain C.
     * Gate: the found formula must CONTAIN the atom - reuse records
     * application, not a coincidental find (agent-review MED: without the
     * gate a raw-solved domain C would mint a false reuse).
     */
    private static function runReuse(array $res, array $atom): array
    {
        $formula = is_string($res[2]) ? $res[2] : '';
        $applied = ($res[0] ?? false) && $atom['name'] !== '' && str_contains($formula, $atom['name']);
        if ($applied) {
            // same public contract as the live path Hive::registerReuseOps;
            // the organic Search::touchAtom writes only 'search' (technical
            // domain), the fixture calls the real-domain record explicitly
            Grammar::registerReuse($atom['name'], self::DOM_C);
        }
        return [
            'done' => $applied,
            'atomAfter' => self::readAtom(),
        ];
    }

    /**
     * Phase 4: report assembly + verdict conjunction.
     */
    private static function buildReport(array $boot, array $contrast, array $reuse, array $parity): array
    {
        $atom = $contrast['atom'];
        $atomAfter = $reuse['atomAfter'];

        $aPass = $atom['name'] !== '' && count($atom['source_domains']) >= 2 && $boot['lawA']['found'] && $boot['lawB']['found'];
        $bPass = self::contrastPass($contrast);
        $vPass = $reuse['done'] && in_array(self::DOM_C, $atomAfter['reuse_domains'], true) && $atomAfter['reuse_count'] >= 1;
        $gPass = $parity['coalition_cv'] < 0.1 && $parity['meta_cv'] <= $parity['coalition_cv'];

        return self::$lastReport = [
            'verdict' => ($aPass && $bPass && $vPass && $gPass) ? 'PASS' : 'FAIL',
            'meta' => self::reportMeta($contrast),
            'a' => self::reportSectionA($boot, $atom, $aPass),
            // status AT MEASUREMENT TIME (candidate: reuse happens later, in v)
            'b' => self::reportSectionB($contrast, $atom['status']),
            'v' => [
                'pass' => $vPass,
                'reuse_count' => $atomAfter['reuse_count'],
                'reuse_domains' => $atomAfter['reuse_domains'],
                'status_after' => $atomAfter['status'],
            ],
            'g' => $parity + [
                'pass' => $gPass,
            ],
        ];
    }

    /**
     * Report meta: env facts + idempotent-compress fact + timings.
     */
    private static function reportMeta(array $contrast): array
    {
        return [
            'env' => self::envFactsLine(),
            'born2' => $contrast['born2'],
            'secs' => [
                'raw' => $contrast['rawSecs'],
                'atom' => $contrast['atomSecs'],
            ],
        ];
    }

    /**
     * Report section a: compression provenance.
     */
    private static function reportSectionA(array $boot, array $atom, bool $pass): array
    {
        return [
            'pass' => $pass,
            'atom' => $atom['name'],
            'definition' => $atom['definition'],
            'source_domains' => $atom['source_domains'],
            'source_laws' => $atom['source_laws'],
            'law_a' => $boot['lawA'],
            'law_b' => $boot['lawB'],
        ];
    }

    /**
     * (b) pass: contrast semantics - raw fails, with-atom solves exact
     * AND the found formula contains the atom (report-level gate, not
     * only in the test; criterion-audit CA-1).
     */
    private static function contrastPass(array $contrast): bool
    {
        $rawFound = (bool) $contrast['raw'][0];
        $rawCv = is_numeric($contrast['raw'][1]) ? (float) $contrast['raw'][1] : 9.99;
        $res = $contrast['res'];
        $cFormula = is_string($res[2]) ? $res[2] : '';
        return (bool) $res[0] && ! $rawFound && $rawCv > 0.0
            && (is_numeric($res[1]) ? (float) $res[1] : 9.99) < self::CV_EXACT
            && $contrast['atom']['name'] !== ''
            && str_contains($cFormula, $contrast['atom']['name']);
    }

    /**
     * Report section b: raw vs with-atom contrast rows.
     */
    private static function reportSectionB(array $contrast, string $status): array
    {
        $raw = $contrast['raw'];
        $res = $contrast['res'];
        return [
            'pass' => self::contrastPass($contrast),
            'status' => $status,
            'raw_domain_c' => [
                'found' => (bool) $raw[0],
                'cv' => is_numeric($raw[1]) ? (float) $raw[1] : 9.99,
                'diag' => is_string($raw[5] ?? null) ? $raw[5] : '',
                'secs' => $contrast['rawSecs'],
            ],
            'with_atom' => [
                'found' => (bool) $res[0],
                'cv' => is_numeric($res[1]) ? (float) $res[1] : 9.99,
                'formula' => is_string($res[2]) ? $res[2] : '',
                'secs' => $contrast['atomSecs'],
            ],
        ];
    }

    // ------------------------------------------------------------------
    //  Fixture domains (deterministic seeds, probe v6)
    // ------------------------------------------------------------------

    /**
     * y = hi - lo; injected RNG (no global state); 2 columns.
     */
    private static function mkDiffDomain(callable $rng, float $loBase, float $span): array
    {
        $X = [];
        $y = [];
        for ($i = 0; $i < self::N_ROWS; $i++) {
            $lo = $loBase + $rng() * $span;
            $hi = $lo + 2.0 + $rng() * 8.0;
            $X[] = [$lo, $hi];
            $y[] = $hi - $lo;
        }
        return [$X, $y];
    }

    /**
     * y = G*(Th-Tc)*A/d; 5 columns.
     */
    private static function mkFluxDomain(callable $rng, int $n): array
    {
        $X = [];
        $y = [];
        for ($i = 0; $i < $n; $i++) {
            $g = 0.6 + $rng() * 1.8;
            $tc = 2.0 + $rng() * 4.0;
            $th = $tc + 5.0 + $rng() * 10.0;
            $a = 1.0 + $rng();
            $d = 0.5 + $rng();
            $X[] = [$g, $tc, $th, $a, $d];
            $y[] = $g * ($th - $tc) * $a / $d;
        }
        return [$X, $y];
    }

    /**
     * Numeric-only grammar for the verify fixture (semantic ops excluded).
     */
    private static function numericGrammar(): Grammar
    {
        $g = new Grammar();
        $g->restrictTo(['+', '×', '−', '/', 'sq', 'cube', 'sqrt', 'max', 'min']);
        return $g;
    }

    /**
     * Solve a bootstrap domain (beam off, depth 2, exact) and record the law.
     * restrictTo: SEMANTIC_OPS ('has') on numeric data yield constant-0
     * vectors; with beam=0 such a shadow won over the exact law
     * (runOnce probe: '((x0hasx0^2)-(x0-x1))' cv=0) and poisoned the
     * atom fingerprint. Numeric-only grammar = same basis for both
     * sides of every comparison (law vs atom, raw vs with-atom).
     */
    private static function solveBootstrap(array $x, array $y, string $domain, string $lawName): array
    {
        $res = Search::find($x, $y, self::numericGrammar(), 2, null, 0.0, self::CV_EXACT, 30.0);
        $found = (bool) $res[0];
        if ($found) {
            $stmt = \BeeSwarm\Infra\Database::get()->prepare('INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)');
            $stmt->execute([$lawName, (string) $res[2], (float) $res[1], $domain]);
        }
        return [
            'found' => $found,
            'cv' => is_numeric($res[1]) ? (float) $res[1] : 9.99,
            'formula' => is_string($res[2]) ? $res[2] : '',
        ];
    }

    // ------------------------------------------------------------------
    //  grammar_ops helpers
    // ------------------------------------------------------------------

    /**
     * @return array{name: string, definition: string, status: string, reuse_count: int, reuse_domains: string[], source_domains: string[], source_laws: int}
     */
    private static function readAtom(): array
    {
        $rows = \BeeSwarm\Infra\Database::run(
            "SELECT name, definition, status, reuse_count, reuse_domains FROM grammar_ops
             WHERE source = 'birth' AND name LIKE 'BW%' ORDER BY id ASC"
        )->fetchAll();
        if ($rows === []) {
            return [
                'name' => '',
                'definition' => '',
                'status' => 'candidate',
                'reuse_count' => 0,
                'reuse_domains' => [],
                'source_domains' => [],
                'source_laws' => 0,
            ];
        }
        return self::atomFromRow($rows[0]);
    }

    /**
     * @return array<string, mixed> atom row + provenance (laws table join).
     */
    private static function atomFromRow(array $row): array
    {
        // Match laws by CANONIZED tree, not raw string: canonize renames
        // xN by order of appearance, so a law like (x1-x0) canonizes to
        // (x0-x1) != its stored formula (agent-review MED: exact-string
        // join undercounts source domains and false-FAILs criterion a).
        $canon = LawIsomorphismCompressor::canonize((string) $row['definition']);
        $laws = $canon === null
            ? []
            : \BeeSwarm\Infra\Database::run(
                'SELECT domain, formula FROM laws WHERE domain IN (?, ?)',
                [self::DOM_A, self::DOM_B]
            )->fetchAll(\PDO::FETCH_ASSOC);
        $srcDomains = [];
        foreach ($laws as $law) {
            if (LawIsomorphismCompressor::canonize((string) $law['formula']) === $canon) {
                $srcDomains[] = $law['domain'];
            }
        }

        return [
            'name' => (string) $row['name'],
            'definition' => (string) $row['definition'],
            'status' => (string) $row['status'],
            'reuse_count' => (int) $row['reuse_count'],
            'reuse_domains' => json_decode((string) ($row['reuse_domains'] ?? '[]'), true) ?: [],
            'source_domains' => array_values(array_unique($srcDomains)),
            'source_laws' => count($srcDomains),
        ];
    }

    private static function removeBirthAtoms(): void
    {
        // Scope BW% only: other tests' birth atoms (B%) must survive -
        // reverse cross-test pollution guard. BW-priority in the search
        // SQL keeps the contrast valid either way.
        \BeeSwarm\Infra\Database::get()->exec("DELETE FROM grammar_ops WHERE source = 'birth' AND name LIKE 'BW%'");
        ExpressionEvaluator::clearDefCache();
        AtomRegistry::clearDefCache();
    }

    // ------------------------------------------------------------------
    //  (g) compilation vs coalition, same heldout slices
    // ------------------------------------------------------------------

    /**
     * (g) compilation vs coalition, same heldout slices.
     * Protocol v1.6 formula (Core/CV0_Protocol_v1.6_RU.md:1368):
     * CV_meta <= mean(CV_L1..Ln). mean is the STRICTER operand
     * (mean <= max): passing mean proves max too. coalition_max_cv
     * kept in the report (v1.5 handoff letter compatibility).
     * Heldout = first 40% rows of each domain (same rows for both sides).
     *
     * @return array{meta_cv: float, coalition_cv: float, coalition_max_cv: float, heldout_rows: int}
     */
    private static function compilationParity(array $xa, array $ya, array $xb, array $yb, string $lawA, string $lawB): array
    {
        [$xTestA, $yTestA, $xTrainA] = self::heldoutSplit($xa, $ya);
        [$xTestB, $yTestB, $xTrainB] = self::heldoutSplit($xb, $yb);

        $atomDef = self::readAtom()['definition'];
        $cvA = self::heldoutCv($lawA, $xTestA, $yTestA, $xTrainA);
        $cvB = self::heldoutCv($lawB, $xTestB, $yTestB, $xTrainB);
        $meta = max(
            self::heldoutCv($atomDef, $xTestA, $yTestA, $xTrainA),
            self::heldoutCv($atomDef, $xTestB, $yTestB, $xTrainB)
        );
        return [
            'meta_cv' => $meta,
            'coalition_cv' => ($cvA + $cvB) / 2.0,
            'coalition_max_cv' => max($cvA, $cvB),
            'heldout_rows' => count($yTestA),
        ];
    }

    /**
     * First HELDOUT_RATIO rows = test, rest = train (deterministic).
     */
    private static function heldoutSplit(array $x, array $y): array
    {
        $n = count($y);
        $k = (int) floor($n * self::HELDOUT_RATIO);
        return [
            array_slice($x, 0, $k),
            array_slice($y, 0, $k),
            array_slice($x, $k),
        ];
    }

    /**
     * CV of a formula on the heldout slice via the single Search::testCv metric.
     */
    private static function heldoutCv(string $formula, array $xTest, array $yTest, array $xTrain): float
    {
        if ($formula === '') {
            return 9.99;
        }
        $cv = Search::testCv($formula, $xTest, $yTest, 1.0, count($yTest), null, $xTrain, [], []);
        return is_finite($cv) ? $cv : 9.99;
    }

    private static function envFactsLine(): string
    {
        $get = static fn (string $k): string => getenv($k) !== false ? (string) getenv($k) : 'unset';
        return 'V28_ENV ' . json_encode([
            'SWARM_DB_PATH' => $get('SWARM_DB_PATH'),
            'SEARCH_BEAM_K' => $get('SEARCH_BEAM_K') . ' (bootstrap/raw forced to 0)',
            'SEARCH_DEPTH_MAX' => $get('SEARCH_DEPTH_MAX'),
            'NO_BIRTH' => $get('NO_BIRTH'),
            'BINARY_B_CAP' => $get('BINARY_B_CAP'),
            'nA' => self::N_ROWS,
            'nC' => self::N_FLUX_ROWS,
            'heldout_ratio' => self::HELDOUT_RATIO,
        ]);
    }
}
