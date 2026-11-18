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

    /** Every worker the scenario expects to dispatch before the lock is released. */
    private const WORKER_IDS = ['a', 'b'];

    /** Upper bound for a worker to produce its result file. */
    private const WORKER_TIMEOUT_SECONDS = 30.0;

    /** Upper bound for all workers to announce that their request was issued. */
    private const DISPATCH_TIMEOUT_SECONDS = 20.0;

    private string $directory = '';

    /**
     * Workers still owned by the test, keyed by worker id, so a failure before
     * the results are collected cannot leave an OS process behind.
     *
     * @var array<string, array{process: resource, result: string, log: string}>
     */
    private array $workers = [];

    protected function tearDown(): void
    {
        $this->stopWorkers();
        $this->removeTemporaryDirectory();
        parent::tearDown();
    }

    public function testTwoConcurrentCreatesOfOneMutationStoreExactlyOneElement(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is disabled, so two requests cannot be issued at once.');
        }

        $this->directory = $this->createTemporaryDirectory();
        $barrier = $this->createBarrierDirectory();

        $holder = DriverManager::getConnection($this->entityManager->getConnection()->getParams());
        $holder->beginTransaction();
        $holder->executeStatement('SELECT id FROM boards WHERE id = ? FOR UPDATE', [$this->board->getId()]);

        $workers = [];
        try {
            foreach (self::WORKER_IDS as $id) {
                $workers[$id] = $this->startWorker($id, $barrier);
                $this->workers[$id] = $workers[$id];
            }

            // Both requests must be issued while the row is still locked, so the
            // comparison below is about two in-flight requests, not two calls.
            // The assertion is explicit: a worker that never dispatched would
            // otherwise let the lock be released and turn the pairwise checks
            // below into a description of two sequential requests.
            $this->assertAllWorkersDispatched($barrier, self::WORKER_IDS, $workers, self::DISPATCH_TIMEOUT_SECONDS);

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
            $results[$id] = $this->waitForWorkerResult($id, $worker);
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

    /**
     * Pins the failure path of the dispatch wait without starting a process: only
     * one of the two expected workers announces itself, which is what a worker
     * dying during boot looks like. The wait must report the missing worker, stay
     * bounded and name it in the failure message instead of releasing the lock.
     */
    public function testDispatchWaitReportsEveryWorkerThatNeverArrives(): void
    {
        $this->directory = $this->createTemporaryDirectory();
        $barrier = $this->createBarrierDirectory();
        touch($barrier.'/dispatched-a');

        $startedAt = microtime(true);
        $missing = $this->awaitDispatchedWorkers($barrier, self::WORKER_IDS, 0.3);
        $elapsed = microtime(true) - $startedAt;

        self::assertSame(['b'], $missing, 'The wait must report the worker that never dispatched.');
        self::assertLessThan(5.0, $elapsed, 'The wait is bounded by its timeout instead of hanging the suite.');

        $message = $this->describeMissingWorkers($missing, self::WORKER_IDS, []);
        self::assertStringContainsString('1 of 2', $message);
        self::assertStringContainsString('missing b', $message);
        self::assertStringContainsString('worker "b"', $message);
    }

    /**
     * Waits for every expected dispatch marker, then fails with the workers that
     * never arrived. Releasing the board row without all of them would weaken the
     * test: the "both were in flight" assertions would pass on two sequential
     * requests.
     *
     * @param list<string>                                                         $expectedIds
     * @param array<string, array{process: resource, result: string, log: string}> $workers
     */
    private function assertAllWorkersDispatched(string $barrier, array $expectedIds, array $workers, float $timeout): void
    {
        $missing = $this->awaitDispatchedWorkers($barrier, $expectedIds, $timeout);
        if ([] === $missing) {
            return;
        }

        self::fail($this->describeMissingWorkers($missing, $expectedIds, $workers));
    }

    /**
     * Blocks until every expected worker has written its dispatch marker or the
     * timeout expires.
     *
     * @param list<string> $expectedIds
     *
     * @return list<string> the worker ids whose marker is still absent
     */
    private function awaitDispatchedWorkers(string $barrier, array $expectedIds, float $timeout): array
    {
        $deadline = microtime(true) + $timeout;
        while (true) {
            $missing = array_values(array_filter(
                $expectedIds,
                static fn (string $id): bool => !is_file($barrier.'/dispatched-'.$id),
            ));
            if ([] === $missing || microtime(true) >= $deadline) {
                return $missing;
            }
            usleep(10_000);
        }
    }

    /**
     * Builds the failure message for workers that never dispatched. It names the
     * absent markers and includes each worker's output, because a worker that
     * died during kernel boot normally explains why in its log.
     *
     * @param list<string>                                                         $missing
     * @param list<string>                                                         $expectedIds
     * @param array<string, array{process: resource, result: string, log: string}> $workers
     */
    private function describeMissingWorkers(array $missing, array $expectedIds, array $workers): string
    {
        $details = [];
        foreach ($missing as $id) {
            $log = isset($workers[$id]) && is_file($workers[$id]['log'])
                ? trim((string) file_get_contents($workers[$id]['log']))
                : '';
            $details[] = sprintf('worker "%s"%s', $id, '' === $log ? ' (no output)' : ': '.$log);
        }

        return sprintf(
            'Only %d of %d concurrent request workers dispatched before the deadline: missing %s. Expected markers: %s. The board row lock is still held, so no concurrency assertion was evaluated. Worker details: %s',
            count($expectedIds) - count($missing),
            count($expectedIds),
            implode(', ', $missing),
            implode(', ', array_map(static fn (string $id): string => 'dispatched-'.$id, $expectedIds)),
            implode(' | ', $details),
        );
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
    private function waitForWorkerResult(string $id, array $worker): array
    {
        $deadline = microtime(true) + self::WORKER_TIMEOUT_SECONDS;
        while (microtime(true) < $deadline && !is_file($worker['result'])) {
            usleep(25_000);
        }

        $hasResult = is_file($worker['result']);
        if ($hasResult) {
            // The worker exited after writing its result; reaping it here keeps
            // the handle out of tearDown().
            $this->closeWorker($id, $worker);
        } else {
            // A worker that wrote nothing is still running or hung. Stop it
            // before reading the log so the failure is reported and the process
            // tree does not outlive the test.
            $this->terminateWorker($id, $worker);
            $log = is_file($worker['log']) ? (string) file_get_contents($worker['log']) : '';
            self::fail('The concurrent request produced no result within '.self::WORKER_TIMEOUT_SECONDS.' seconds. Worker output: '.$log);
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

    /** @param array{process: resource, result: string, log: string} $worker */
    private function closeWorker(string $id, array $worker): void
    {
        unset($this->workers[$id]);
        if (is_resource($worker['process'])) {
            proc_close($worker['process']);
        }
    }

    /** @param array{process: resource, result: string, log: string} $worker */
    private function terminateWorker(string $id, array $worker): void
    {
        unset($this->workers[$id]);
        if (!is_resource($worker['process'])) {
            return;
        }

        $status = proc_get_status($worker['process']);
        if ($status['running']) {
            proc_terminate($worker['process']);
        }
        proc_close($worker['process']);
    }

    /**
     * Terminates and reaps every worker the test still owns. Both the success and
     * the failure path end here, so a failed dispatch assertion cannot leave a
     * process waiting on a row lock that no longer belongs to the test.
     */
    private function stopWorkers(): void
    {
        foreach ($this->workers as $id => $worker) {
            $this->terminateWorker($id, $worker);
        }
        $this->workers = [];
    }

    private function createTemporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/mosaiq-concurrency-'.bin2hex(random_bytes(6));
        if (!mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new \RuntimeException('The concurrency test directory could not be created.');
        }

        return $directory;
    }

    private function createBarrierDirectory(): string
    {
        $barrier = $this->directory.'/barrier';
        if (!mkdir($barrier, 0o777) && !is_dir($barrier)) {
            throw new \RuntimeException('The concurrency barrier directory could not be created.');
        }

        return $barrier;
    }

    private function removeTemporaryDirectory(): void
    {
        if ('' === $this->directory) {
            return;
        }

        if (is_dir($this->directory)) {
            $this->removePath($this->directory);
        }
        $this->directory = '';
    }

    /** Removes files, the barrier directory and any nested directory in the workspace. */
    private function removePath(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ('.' === $entry || '..' === $entry) {
                    continue;
                }
                $this->removePath($path.'/'.$entry);
            }
            rmdir($path);

            return;
        }

        if (is_file($path) || is_link($path)) {
            unlink($path);
        }
    }
}
