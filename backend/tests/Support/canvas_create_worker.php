<?php

declare(strict_types=1);

/*
 * Runs one canvas create through the real test kernel in its own OS process.
 * The two-process test uses it to keep two requests in flight at the same time,
 * which a single-process functional test cannot do.
 *
 * Usage: php canvas_create_worker.php <config.json>
 */

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$configPath = $_SERVER['argv'][1] ?? '';

/** @var array{id: string, boardId: string, token: string, mutationId: string, content: string, barrier: string, result: string}|null $config */
$config = is_string($configPath) && is_file($configPath)
    ? json_decode((string) file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR)
    : null;

if (!is_array($config)) {
    fwrite(STDERR, "Usage: php canvas_create_worker.php <config.json>\n");
    exit(1);
}

// Both workers announce themselves before dispatching, so the two requests are
// issued at the same moment instead of one after the other.
touch($config['barrier'].'/ready-'.$config['id']);
$deadline = microtime(true) + 10.0;
while (count(glob($config['barrier'].'/ready-*') ?: []) < 2 && microtime(true) < $deadline) {
    usleep(5_000);
}

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');

$kernel = new Kernel('test', true);
$request = Request::create(
    '/api/boards/'.$config['boardId'].'/elements',
    'POST',
    server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$config['token'],
    ],
    content: json_encode([
        'mutationId' => $config['mutationId'],
        'type' => 'note',
        'x' => 10,
        'y' => 20,
        'width' => 220,
        'height' => 140,
        'content' => $config['content'],
        'color' => 'amber',
        'metadata' => ['stroke' => 'thin', 'layout' => new stdClass()],
    ], JSON_THROW_ON_ERROR),
);

// Announced right before the request is handled: the test releases the board
// row only once both requests have been issued, and its assertions prove both
// were still in flight at that instant.
touch($config['barrier'].'/dispatched-'.$config['id']);
$startedAt = microtime(true);
$response = $kernel->handle($request);
$finishedAt = microtime(true);
$kernel->terminate($request, $response);

file_put_contents($config['result'], json_encode([
    'status' => $response->getStatusCode(),
    'body' => (string) $response->getContent(),
    'startedAt' => $startedAt,
    'finishedAt' => $finishedAt,
], JSON_THROW_ON_ERROR));
