<?php

/**
 * GENOME-001 CHECKPOINT A: subprocess-раннер чистой линии.
 *
 * Принимает envelope JSON: {db, genome, rows, bundle}. В НОВОМ процессе:
 * ставит SWARM_DB_PATH на чистую БД, регистрирует словарь из bundle,
 * декодирует геном, исполняет по строкам через evalNode, печатает JSON.
 *
 * Паттерн probe1 (HANDOFF питфолл 4): компрессор/оценка в свежем процессе —
 * никакое process-local состояние не течёт через линию. Отказ (unknown word,
 * cycle, arity) — ok=false + error, exit 3.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Core\WordCodec;
use BeeSwarm\Core\WordCodecException;
use BeeSwarm\Infra\Database;

/**
 * @noinspection PhpUnusedParameterInspection параметры для единообразия ответа
 */
function fail(string $class, string $message): never
{
    echo json_encode([
        'ok' => false,
        'error_class' => $class,
        'error' => $message,
    ], JSON_UNESCAPED_UNICODE) . "\n";
    exit(3);
}

$envelopePath = $argv[1] ?? '';
if ($envelopePath === '' || ! is_file($envelopePath)) {
    fail('MissingEnvelope', "envelope file not found: {$envelopePath}");
}
try {
    $env = json_decode((string) file_get_contents($envelopePath), true, 512, JSON_THROW_ON_ERROR);
} catch (\JsonException $e) {
    fail('JsonException', $e->getMessage());
}
if (! is_array($env)) {
    fail('MalformedEnvelope', 'envelope must be a JSON object');
}

putenv('SWARM_DB_PATH=' . (string) ($env['db'] ?? ':memory:'));
Database::reset();
ExpressionEvaluator::resetCaches();

try {
    $bundleJson = json_encode($env['bundle'] ?? null, JSON_THROW_ON_ERROR);
    [$entries, $genomes] = WordCodec::loadBundle($bundleJson);
    WordCodec::registerDictionary($entries);
    $name = (string) ($env['genome'] ?? '');
    $genome = $genomes[$name] ?? null;
    if (! is_array($genome)) {
        fail('MissingGenome', "genome '{$name}' not in bundle");
    }
    $ast = WordCodec::decode($genome, $entries);
    $values = [];
    foreach ((array) ($env['rows'] ?? []) as $row) {
        $values[] = ExpressionEvaluator::evalNode($ast, (array) $row, [], []);
    }
    echo json_encode([
        'ok' => true,
        'values' => $values,
    ], JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
} catch (WordCodecException $e) {
    fail('WordCodecException', $e->getMessage());
} catch (\Throwable $e) {
    fail(get_class($e), $e->getMessage());
}
