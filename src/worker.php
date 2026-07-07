<?php

declare(strict_types=1);

/**
 * RoadRunner worker entry point (F1).
 *
 * Each worker is a persistent PHP process.
 * HTTP requests arrive via RoadRunner relay (pipes).
 *
 * .rr.yaml: command="php src/worker.php", pool.num_workers=4
 */

use Spiral\RoadRunner\Http\PSR7Worker;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;

require_once __DIR__ . '/../vendor/autoload.php';

$factory = new Psr17Factory();
$worker = new PSR7Worker(
    \Spiral\RoadRunner\Worker::create(),
    $factory,
    $factory,
    $factory
);

while ($request = $worker->waitRequest()) {
    try {
        $uri = $request->getUri()->getPath();

        if ($uri === '/status') {
            $body = json_encode(['status' => 'ok', 'mode' => 'roadrunner-worker']);
            $response = new Response(200, ['Content-Type' => 'application/json'], $body);
        } elseif ($uri === '/task' && $request->getMethod() === 'POST') {
            $body = json_encode(['accepted' => true]);
            $response = new Response(200, ['Content-Type' => 'application/json'], $body);
        } else {
            $response = new Response(404, [], 'Not Found');
        }

        $worker->respond($response);
    } catch (\Throwable $e) {
        $worker->respond(new Response(500, [], 'Error: ' . $e->getMessage()));
    }
}
