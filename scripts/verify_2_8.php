<?php
declare(strict_types=1);

/**
 * VERIFY-2-8 CLI wrapper (thin, per EnvPressureVerify precedent).
 *
 * Protocol par.3.8(g) v1.5 (non-strict): system creates its own identifier
 * for a compressed law, and other bees use this identifier as a grammar
 * atom in new domains.
 *
 * Usage:
 *   php scripts/verify_2_8.php            (report to stdout, exit 0/1)
 *   php scripts/verify_2_8.php --out FILE (JSON report to file)
 *
 * Env contract (facts logged in the first line of the report meta):
 *   SWARM_DB_PATH=:memory:  FORAGER_SOURCES=:  SEARCH_BEAM_K=10
 *   BINARY_B_CAP=3  (bootstrap/raw runs force beam=0 internally)
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

// -- Env header (facts, not assumptions; canonical env-header rule) --
putenv('FORAGER_SOURCES=:');
putenv('NO_BASE_TASKS=1');
putenv('SEARCH_BEAM_K=10');
putenv('BINARY_B_CAP=3');
putenv('SEARCH_DEPTH_MAX=4');
if (getenv('SWARM_DB_PATH') === false) {
    putenv('SWARM_DB_PATH=:memory:');
}

$rep = \BeeSwarm\Hive\LanguageVerify28::report();

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
exit($rep['verdict'] === 'PASS' ? 0 : 1);
