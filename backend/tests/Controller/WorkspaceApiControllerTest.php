<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Board;

final class WorkspaceApiControllerTest extends ApiFunctionalTestCase
{
    private Board $privateBoard;
    private string $adminToken = 'workspace-admin-token-123456789012345';
    private string $memberToken = 'plain-member-token-1234567890123456';

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeUser($this->workspace, 'workspace-admin@example.test', 'Admin', $this->adminToken);
        $this->makeUser($this->workspace, 'plain-member@example.test', 'Editor', $this->memberToken);

        // Private board: neither the workspace administrator's role nor ordinary
        // workspace membership should be inferred here; only membership or the
        // workspace-admin rule from the voters may grant access.
        $this->privateBoard = new Board($this->workspace, 'Confidential hiring plan');
        $this->privateBoard->addMember($this->owner, 'Owner');
        $this->entityManager->persist($this->privateBoard);
        $this->entityManager->flush();
    }

    public function testWorkspaceOwnerSeesAuthorizedBoardSummaries(): void
    {
        $this->requestAs($this->ownerToken, 'GET', '/api/workspaces/'.$this->workspace->getId());
        self::assertResponseIsSuccessful();

        $payload = $this->jsonResponse();
        self::assertSame($this->workspace->getId(), $payload['id']);
        self::assertSame(2, $payload['boardCount']);
        $boards = $this->boardsById($payload);
        self::assertArrayHasKey($this->board->getId(), $boards);
        self::assertArrayHasKey($this->privateBoard->getId(), $boards);
        self::assertSame('Authorized board', $boards[$this->board->getId()]['title']);
    }

    public function testWorkspaceAdministratorSeesOnlyBoardsInTheirOwnWorkspace(): void
    {
        $this->requestAs($this->adminToken, 'GET', '/api/workspaces/'.$this->workspace->getId());
        self::assertResponseIsSuccessful();

        $payload = $this->jsonResponse();
        self::assertSame(2, $payload['boardCount']);
        self::assertArrayHasKey($this->privateBoard->getId(), $this->boardsById($payload));

        $this->requestAs($this->adminToken, 'GET', '/api/workspaces/'.$this->otherWorkspace->getId());
        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString((string) $this->otherWorkspace->getName(), (string) $this->client->getResponse()->getContent());
    }

    public function testOrdinaryWorkspaceMemberCannotSeeUnjoinedPrivateBoards(): void
    {
        $this->requestAs($this->memberToken, 'GET', '/api/workspaces/'.$this->workspace->getId());
        self::assertResponseIsSuccessful();

        $payload = $this->jsonResponse();
        self::assertSame([], $payload['boards']);
        self::assertSame(0, $payload['boardCount']);

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString($this->privateBoard->getTitle(), $body);
        self::assertStringNotContainsString($this->privateBoard->getId(), $body);
        self::assertStringNotContainsString($this->board->getTitle(), $body);
        self::assertStringNotContainsString($this->owner->getEmail(), $body);
    }

    public function testUsersOutsideTheWorkspaceCannotRetrieveWorkspaceInformation(): void
    {
        $this->requestAs($this->outsiderToken, 'GET', '/api/workspaces/'.$this->workspace->getId());
        self::assertResponseStatusCodeSame(403);

        $this->requestAs($this->ownerToken, 'GET', '/api/workspaces/'.$this->otherWorkspace->getId());
        self::assertResponseStatusCodeSame(403);
    }

    public function testSwappingWorkspaceOrBoardIdentifiersCannotBypassAuthorization(): void
    {
        $this->requestAs($this->memberToken, 'GET', '/api/workspaces/'.$this->workspace->getId());
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonResponse()['boards']);

        $this->requestAs($this->memberToken, 'GET', '/api/workspaces/'.$this->otherWorkspace->getId());
        self::assertResponseStatusCodeSame(403);

        $this->requestAs($this->memberToken, 'GET', '/api/workspaces/00000000-0000-0000-0000-000000000000');
        self::assertResponseStatusCodeSame(404);

        $this->requestAs($this->memberToken, 'GET', '/api/boards/'.$this->privateBoard->getId());
        self::assertResponseStatusCodeSame(403);

        $this->requestAs($this->memberToken, 'GET', '/api/boards?workspaceId='.$this->otherWorkspace->getId());
        self::assertResponseStatusCodeSame(403);
    }

    public function testHiddenBoardMetadataDoesNotLeakThroughNestedResponses(): void
    {
        $this->requestAs($this->memberToken, 'GET', '/api/workspaces');
        self::assertResponseIsSuccessful();

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString($this->privateBoard->getTitle(), $body);
        self::assertStringNotContainsString($this->privateBoard->getId(), $body);

        $this->requestAs($this->memberToken, 'GET', '/api/boards?workspaceId='.$this->workspace->getId());
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonResponse()['boards']);
    }

    public function testWorkspaceBoardListMatchesDirectBoardAuthorization(): void
    {
        $this->requestAs($this->adminToken, 'GET', '/api/workspaces/'.$this->workspace->getId());
        self::assertResponseIsSuccessful();
        $ids = array_keys($this->boardsById($this->jsonResponse()));

        self::assertCount(2, $ids);
        foreach ($ids as $id) {
            $this->requestAs($this->adminToken, 'GET', '/api/boards/'.$id);
            self::assertResponseIsSuccessful();
        }

        $this->requestAs($this->memberToken, 'GET', '/api/workspaces/'.$this->workspace->getId());
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonResponse()['boards']);

        foreach ([$this->board->getId(), $this->privateBoard->getId()] as $id) {
            $this->requestAs($this->memberToken, 'GET', '/api/boards/'.$id);
            self::assertResponseStatusCodeSame(403);
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, array<string, mixed>>
     */
    private function boardsById(array $payload): array
    {
        self::assertIsArray($payload['boards']);

        $boards = [];
        foreach ($payload['boards'] as $board) {
            self::assertIsArray($board);
            self::assertIsString($board['id']);
            $boards[$board['id']] = $board;
        }

        return $boards;
    }
}
