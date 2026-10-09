<?php
declare(strict_types=1);

/**
 * VERIFY-2-2b CLI wrapper (thin, per EnvPressureVerify precedent).
 *
 * S2.2-TER (§3.2.2) form invariance: one mathematical FORM recurring in
 * >=3 unrelated domains with parameters >=10x and cv <= 0.15 everywhere;
 * FPR guard (shuffle) must add zero invariants.
 *
 * Usage:
 *   php scripts/verify_2_2b.php            (report to stdout, exit 0/1)
 *   php scripts/verify_2_2b.php --out FILE (JSON report to file)
 *
 * Env contract (facts logged in the report 'env' map):
 *   SWARM_DB_PATH=:memory:  FORAGER_SOURCES=:  SEARCH_BEAM_K=0
 *   (persistent DB path requires V22B_DESTRUCTIVE_OK=1 - the run deletes
 *   the birth dictionary and fixture laws)
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

// -- Env header (facts, not assumptions; canonical env-header rule) --
putenv('FORAGER_SOURCES=:');
putenv('SEARCH_BEAM_K=0');
if (getenv('SWARM_DB_PATH') === false) {
    putenv('SWARM_DB_PATH=:memory:');
}
// NO auto-opt-in (H3, agent-review deleg_839e61a4): on :memory: the guard
// passes without it; on a persistent path the run MUST fail loudly until
// the operator sets V22B_DESTRUCTIVE_OK=1 EXPLICITLY - the verify deletes
// the birth dictionary and form_registry.

$rep = \BeeSwarm\Hive\FormInvarianceVerify::report();

$outArg = $argv[1] ?? '';
if ($outArg === '--out') {
    if (! isset($argv[2]) || $argv[2] === '') {
        fwrite(STDERR, "--out requires a file path\n");
        exit(2);
    }
    file_put_contents($argv[2], json_encode($rep, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fwrite(STDERR, 'report written: ' . $argv[2] . "\n");
} elseif ($outArg !== '') {
    fwrite(STDERR, "unknown argument: {$outArg} (supported: --out FILE)\n");
    exit(2);
}

echo json_encode($rep, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
exit($rep['pass'] === true ? 0 : 1);
