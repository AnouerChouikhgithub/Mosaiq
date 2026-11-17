<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Doctrine\DBAL\DriverManager;

/**
 * Idempotency under real concurrency.
 *
 * The rest of the functional suite runs in one process and cannot interleave two
 * HTTP requests. This test starts two OS processes that each boot the test kernel
 * and dispatch a real `POST /api/boards/{id}/elements` for the same board, author
 * and mutation id. The test holds the board row lock until both requests have
 * been issued, so both have to queue on `SELECT ... FOR UPDATE` before either can
 * insert — the exact contention the row lock exists for. Each worker reports when
 * its request started and finished, so the test can prove both were still in
 * flight when the lock was released instead of running one after the other.
 */
final class CanvasConcurrencyTest extends ApiFunctionalTestCase
{
    private const MUTATION = '3c9f7a1e-5b2d-4e8a-9f10-2b3c4d5e6f70';

    private string $directory = '';

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
        parent::tearDown();
    }

    public function testTwoConcurrentCreatesOfOneMutationStoreExactlyOneElement(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is disabled, so two requests cannot be issued at once.');
        }

        $this->directory = $this->createTemporaryDirectory();
        $barrier = $this->directory.'/barrier';
        mkdir($barrier);

        $holder = DriverManager::getConnection($this->entityManager->getConnection()->getParams());
        $holder->beginTransaction();
        $holder->executeStatement('SELECT id FROM boards WHERE id = ? FOR UPDATE', [$this->board->getId()]);

        $workers = [];
        try {
            foreach (['a', 'b'] as $id) {
                $workers[$id] = $this->startWorker($id, $barrier);
            }

            // Both requests must be issued while the row is still locked, so the
            // comparison below is about two in-flight requests, not two calls.
            $this->waitForDispatchedRequests($barrier, 2);
            // Kept locked a moment longer so each request demonstrably waits on
            // the row: the duration assertion below fails if either request went
            // past the lock instead of queueing on it.
            usleep(500_000);
            $releasedAt = microtime(true);
        } finally {
            if ($holder->isTransactionActive()) {
                $holder->commit();
            }
            $holder->close();
        }

        $results = [];
        foreach ($workers as $id => $worker) {
            $results[$id] = $this->waitForWorkerResult($worker);
        }

        // Neither request could finish before the row was released: both were
        // blocked on the lock while the other one was in flight.
        self::assertGreaterThan($releasedAt, $results['a']['finishedAt'], 'The first request was still in flight when the lock was released.');
        self::assertGreaterThan($releasedAt, $results['b']['finishedAt'], 'The second request was still in flight when the lock was released.');

        self::assertGreaterThan(0.4, $results['a']['finishedAt'] - $results['a']['startedAt'], 'The first request waited on the board row lock.');
        self::assertGreaterThan(0.4, $results['b']['finishedAt'] - $results['b']['startedAt'], 'The second request waited on the board row lock.');

        $statuses = [$results['a']['status'], $results['b']['status']];
        sort($statuses);
        self::assertSame([200, 201], $statuses, 'One request creates the element, the other replays it.');

        // Both requests resolve to the same element: no second note, no second
        // history entry, and the retry answers the stored row.
        self::assertNotSame('', $results['a']['id']);
        self::assertSame($results['a']['id'], $results['b']['id']);
        self::assertSame(1, $this->countRows('canvas_elements'));
        self::assertSame(1, $this->countRows('timeline_entries'));
        self::assertSame($results['a']['id'], $this->fetchColumn('SELECT id FROM canvas_elements'));
    }

    private function waitForDispatchedRequests(string $barrier, int $expected): void
    {
        $deadline = microtime(true) + 30.0;
        while (microtime(true) < $deadline && count(glob($barrier.'/dispatched-*') ?: []) < $expected) {
            usleep(10_000);
        }
    }

    /** @return array{process: resource, result: string, log: string} */
    private function startWorker(string $id, string $barrier): array
    {
        $configPath = $this->directory.'/worker-'.$id.'.json';
        $resultPath = $this->directory.'/result-'.$id.'.json';
        $logPath = $this->directory.'/worker-'.$id.'.log';

        file_put_contents($configPath, json_encode([
            'id' => $id,
            'boardId' => $this->board->getId(),
            'token' => $this->editorToken,
            'mutationId' => self::MUTATION,
            'content' => 'Answered while another request was in flight',
            'barrier' => $barrier,
            'result' => $resultPath,
        ], JSON_THROW_ON_ERROR));

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__).'/Support/canvas_create_worker.php', $configPath],
            [0 => ['pipe', 'r'], 1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('The concurrent request worker could not be started.');
        }
        fclose($pipes[0]);

        return ['process' => $process, 'result' => $resultPath, 'log' => $logPath];
    }

    /**
     * @param array{process: resource, result: string, log: string} $worker
     *
     * @return array{status: int, id: string, startedAt: float, finishedAt: float}
     */
    private function waitForWorkerResult(array $worker): array
    {
        $deadline = microtime(true) + 30.0;
        while (microtime(true) < $deadline && !is_file($worker['result'])) {
            usleep(25_000);
        }

        proc_close($worker['process']);

        if (!is_file($worker['result'])) {
            $log = is_file($worker['log']) ? (string) file_get_contents($worker['log']) : '';
            self::fail('The concurrent request produced no result. Worker output: '.$log);
        }

        $payload = json_decode((string) file_get_contents($worker['result']), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)
            || !is_int($payload['status'] ?? null)
            || !is_string($payload['body'] ?? null)
            || !is_float($payload['startedAt'] ?? null)
            || !is_float($payload['finishedAt'] ?? null)
        ) {
            self::fail('The concurrent request result is malformed.');
        }

        $body = json_decode($payload['body'], true, 512, JSON_THROW_ON_ERROR);
        $id = is_array($body) ? ($body['id'] ?? '') : '';

        return [
            'status' => $payload['status'],
            'id' => is_string($id) ? $id : '',
            'startedAt' => $payload['startedAt'],
            'finishedAt' => $payload['finishedAt'],
        ];
    }

    private function createTemporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/mosaiq-concurrency-'.bin2hex(random_bytes(6));
        if (!mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new \RuntimeException('The concurrency test directory could not be created.');
        }

        return $directory;
    }

    private function removeTemporaryDirectory(): void
    {
        if ('' === $this->directory || !is_dir($this->directory)) {
            return;
        }

        $paths = array_merge(
            glob($this->directory.'/*') ?: [],
            glob($this->directory.'/barrier/*') ?: [],
        );
        foreach ($paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->directory.'/barrier')) {
            rmdir($this->directory.'/barrier');
        }
        rmdir($this->directory);
    }
}
