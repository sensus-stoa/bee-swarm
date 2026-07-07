<?php

declare(strict_types=1);

/**
 * RoadRunner worker entry point (F1 Phase 2).
 *
 * Each worker holds one BeeWorker → one Bee → one grammar.
 * HTTP requests arrive via RoadRunner relay (pipes).
 */

use Spiral\RoadRunner\Http\PSR7Worker;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use BeeSwarm\Hive\Bee;
use BeeSwarm\Hive\BeeWorker;

require_once __DIR__ . '/../vendor/autoload.php';

// Seed grammar — baseline operations from protocol
$seedGrammar = ['add', 'mul', 'sub', 'div', 'sq', 'sqrt', 'abs', 'max', 'min'];

$bee = new Bee($seedGrammar);
$beeWorker = new BeeWorker($bee);

$factory = new Psr17Factory();
$psrWorker = new PSR7Worker(
    \Spiral\RoadRunner\Worker::create(),
    $factory,
    $factory,
    $factory
);

while (true) {
    try {
        $request = $psrWorker->waitRequest();
        if ($request === null) {
            break;
        }

        $uri = $request->getUri()->getPath();

        if ($uri === '/status') {
            $body = json_encode($beeWorker->status(), JSON_UNESCAPED_UNICODE);
            $response = new Response(200, ['Content-Type' => 'application/json'], (string) $body);
        } elseif ($uri === '/task' && $request->getMethod() === 'POST') {
            $rawBody = (string) $request->getBody();
            $result = $beeWorker->handleTask($rawBody);
            $response = new Response(200, ['Content-Type' => 'application/json'], json_encode($result));
        } else {
            $response = new Response(404, [], 'Not Found');
        }

        $psrWorker->respond($response);
    } catch (\Throwable $e) {
        error_log("Bee worker error: " . $e->getMessage());
        $psrWorker->respond(new Response(500, [], 'Internal error'));
    }
}
