<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Board;
use App\Entity\CanvasElement;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A canvas create carries a client mutation id. Repeating one operation must
 * return the element the first attempt stored instead of creating a second one,
 * including when the first response never reached the client.
 *
 * These tests drive the endpoint one request at a time; the two-process test in
 * CanvasConcurrencyTest exercises the same guarantee with simultaneous requests.
 */
final class CanvasIdempotencyTest extends ApiFunctionalTestCase
{
    private const MUTATION = '9f1d4c9e-6a2b-4b7c-8f3a-1d2e3f4a5b6c';

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'mutationId' => self::MUTATION,
            'type' => 'note',
            'x' => 40,
            'y' => 50,
            'width' => 220,
            'height' => 140,
            'content' => 'Retried note',
            'color' => 'amber',
            'metadata' => ['stroke' => 'none', 'textSize' => 'm', 'align' => 'left'],
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function createNote(string $token, array $overrides = [], ?Board $board = null): void
    {
        $this->requestAs(
            $token,
            'POST',
            '/api/boards/'.($board ?? $this->board)->getId().'/elements',
            $this->payload($overrides),
        );
    }

    public function testFirstRequestCreatesOneElementAndEchoesTheMutation(): void
    {
        $this->createNote($this->editorToken);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(self::MUTATION, $this->jsonResponse()['mutationId']);
        self::assertSame(1, $this->countRows('canvas_elements'));
    }

    public function testRetryingTheSameOperationReturnsTheStoredElement(): void
    {
        $this->createNote($this->editorToken);
        self::assertResponseStatusCodeSame(201);
        $firstId = $this->jsonResponse()['id'];

        // The client never saw the first response, so it retries the operation.
        $this->createNote($this->editorToken);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($firstId, $this->jsonResponse()['id']);

        self::assertSame(1, $this->countRows('canvas_elements'));
        // One operation, one history entry: a retry is not a second edit.
        self::assertSame(1, $this->countRows('timeline_entries'));
    }

    public function testRetryAfterAnApplicationRestartStillReplaysTheStoredElement(): void
    {
        $this->createNote($this->editorToken);
        $firstId = $this->jsonResponse()['id'];

        // The guarantee lives in the database, not in a request-scoped cache:
        // drop the identity map and boot a fresh kernel before the retry.
        $this->entityManager->clear();
        self::ensureKernelShutdown();
        $this->client = self::createClient();

        $this->createNote($this->editorToken);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($firstId, $this->jsonResponse()['id']);
        self::assertSame(1, $this->countRows('canvas_elements'));
    }

    public function testSameMutationIdWithADifferentPayloadIsRejected(): void
    {
        $this->createNote($this->editorToken);
        $elementId = $this->jsonResponse()['id'];

        $this->createNote($this->editorToken, ['content' => 'A different note']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            ['error' => 'This mutation id was already used with a different payload.'],
            $this->jsonResponse(),
        );
        self::assertSame(1, $this->countRows('canvas_elements'));

        $this->entityManager->clear();
        $stored = $this->entityManager->find(CanvasElement::class, $elementId);
        self::assertInstanceOf(CanvasElement::class, $stored);
        self::assertSame('Retried note', $stored->getContent());
    }

    public function testMutationIdsAreScopedPerBoard(): void
    {
        $otherBoard = new Board($this->workspace, 'Second board');
        $otherBoard->addMember($this->editor, 'Editor');
        $this->entityManager->persist($otherBoard);
        $this->entityManager->flush();

        $this->createNote($this->editorToken);
        $firstId = $this->jsonResponse()['id'];

        // The same key on another board is another operation: it must create the
        // other board's element and never hand back the first board's note.
        $this->createNote($this->editorToken, [], $otherBoard);
        self::assertResponseStatusCodeSame(201);
        self::assertNotSame($firstId, $this->jsonResponse()['id']);
        self::assertSame(2, $this->countRows('canvas_elements'));
    }

    public function testMutationIdsAreScopedPerUser(): void
    {
        $this->createNote($this->editorToken);
        $editorElementId = $this->jsonResponse()['id'];

        // The owner is an editor on this board, but another user must not be able
        // to retrieve the editor's element through the editor's key.
        $this->createNote($this->ownerToken);
        self::assertResponseStatusCodeSame(201);
        self::assertNotSame($editorElementId, $this->jsonResponse()['id']);
        self::assertSame(2, $this->countRows('canvas_elements'));
    }

    public function testUsersWithoutEditRightsCannotExploitAMutationId(): void
    {
        $this->createNote($this->editorToken);
        $elementId = $this->jsonResponse()['id'];

        $this->createNote($this->viewerToken);
        self::assertResponseStatusCodeSame(403);

        $this->createNote($this->outsiderToken);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(1, $this->countRows('canvas_elements'));
        self::assertSame($elementId, $this->entityManager->getConnection()->fetchOne('SELECT id FROM canvas_elements'));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidMutationIds(): iterable
    {
        yield 'not a uuid' => ['mutation-1'];
        yield 'too short' => ['9f1d4c9e'];
        yield 'too long' => [self::MUTATION.'x'];
        yield 'list' => [['9f1d4c9e-6a2b-4b7c-8f3a-1d2e3f4a5b6c']];
        yield 'number' => [42];
    }

    #[DataProvider('invalidMutationIds')]
    public function testInvalidMutationIdsAreRejected(mixed $mutationId): void
    {
        $this->createNote($this->editorToken, ['mutationId' => $mutationId]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => '"mutationId" must be a canonical UUID.'], $this->jsonResponse());
        self::assertSame(0, $this->countRows('canvas_elements'));
    }

    public function testDifferentMutationIdsCreateDifferentElements(): void
    {
        $this->createNote($this->editorToken);
        $firstId = $this->jsonResponse()['id'];

        $this->createNote($this->editorToken, ['mutationId' => 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d']);

        self::assertResponseStatusCodeSame(201);
        self::assertNotSame($firstId, $this->jsonResponse()['id']);
        self::assertSame(2, $this->countRows('canvas_elements'));
        self::assertSame(2, $this->countRows('timeline_entries'));
    }

    public function testCreatesWithoutAMutationIdKeepTheirPreviousBehaviour(): void
    {
        $payload = $this->payload();
        unset($payload['mutationId']);

        $this->requestAs($this->editorToken, 'POST', '/api/boards/'.$this->board->getId().'/elements', $payload);
        self::assertResponseStatusCodeSame(201);
        $firstId = $this->jsonResponse()['id'];
        self::assertNull($this->jsonResponse()['mutationId']);

        // Without a key there is nothing to deduplicate: two posts are two notes.
        $this->requestAs($this->editorToken, 'POST', '/api/boards/'.$this->board->getId().'/elements', $payload);
        self::assertResponseStatusCodeSame(201);
        self::assertNotSame($firstId, $this->jsonResponse()['id']);
        self::assertSame(2, $this->countRows('canvas_elements'));
    }

    public function testTheDatabaseRejectsASecondElementForTheSameMutation(): void
    {
        $this->createNote($this->editorToken);
        $elementId = $this->jsonResponse()['id'];

        // Two simultaneous requests cannot both win: copying the committed row
        // under a new element id must still violate the unique index, which is
        // the guarantee the controller's row lock relies on.
        $connection = DriverManager::getConnection($this->entityManager->getConnection()->getParams());
        try {
            $row = $connection->fetchAssociative('SELECT * FROM canvas_elements WHERE id = ?', [$elementId]);
            self::assertIsArray($row);
            $row['id'] = self::uuid();

            $this->expectException(UniqueConstraintViolationException::class);
            $connection->insert('canvas_elements', $row);
        } finally {
            $connection->close();
        }
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
