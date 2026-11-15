<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Board;
use App\Entity\User;

final class WorkspaceAdministrationTest extends ApiFunctionalTestCase
{
    private string $adminToken = 'workspace-admin-test-token-123456789012345';
    private string $memberToken = 'workspace-member-test-token-12345678901234';

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeUser($this->workspace, 'admin@example.test', 'Admin', $this->adminToken);
        $this->makeUser($this->workspace, 'member@example.test', 'Editor', $this->memberToken);
    }

    public function testActiveMembersCanListMembersWithoutSecrets(): void
    {
        $this->requestAs($this->memberToken, 'GET', '/api/workspaces/'.$this->workspace->getId().'/members');
        self::assertResponseIsSuccessful();

        $payload = $this->jsonResponse();
        self::assertIsArray($payload['members']);
        self::assertCount(6, $payload['members']);
        $first = $payload['members'][0];
        self::assertArrayHasKey('id', $first);
        self::assertArrayHasKey('role', $first);
        self::assertArrayHasKey('status', $first);
        self::assertArrayHasKey('joinedAt', $first);
        self::assertArrayNotHasKey('password_hash', $first);
        self::assertArrayNotHasKey('api_token_hash', $first);
        self::assertStringNotContainsString('owner-test-token', (string) $this->client->getResponse()->getContent());

        $this->requestAs($this->outsiderToken, 'GET', '/api/workspaces/'.$this->workspace->getId().'/members');
        self::assertResponseStatusCodeSame(403);

        $this->requestAs(null, 'GET', '/api/workspaces/'.$this->workspace->getId().'/members');
        self::assertResponseStatusCodeSame(401);
    }

    public function testOwnerCanChangeMemberRolesButNotItsOwnOrTheOwnerRole(): void
    {
        $this->requestAs($this->ownerToken, 'PATCH', '/api/workspaces/'.$this->workspace->getId().'/members/'.$this->editor->getId(), ['role' => 'Admin']);
        self::assertResponseIsSuccessful();
        self::assertSame('Admin', $this->jsonResponse()['member']['role']);

        $this->requestAs($this->ownerToken, 'PATCH', '/api/workspaces/'.$this->workspace->getId().'/members/'.$this->editor->getId(), ['role' => 'Owner']);
        self::assertResponseStatusCodeSame(422);

        $this->requestAs($this->ownerToken, 'PATCH', '/api/workspaces/'.$this->workspace->getId().'/members/'.$this->owner->getId(), ['role' => 'Viewer']);
        self::assertResponseStatusCodeSame(422);

        $this->requestAs($this->ownerToken, 'PATCH', '/api/workspaces/'.$this->workspace->getId().'/members/'.$this->editor->getId(), ['role' => 'SuperAdmin']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAdminCanManageMembersButCannotPromoteToAdminOrTouchAdmins(): void
    {
        $admin = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'admin@example.test']);
        self::assertInstanceOf(User::class, $admin);

        $this->requestAs($this->adminToken, 'PATCH', '/api/workspaces/'.$this->workspace->getId().'/members/'.$this->viewer->getId(), ['role' => 'Editor']);
        self::assertResponseIsSuccessful();

        $this->requestAs($this->adminToken, 'PATCH', '/api/workspaces/'.$this->workspace->getId().'/members/'.$this->viewer->getId(), ['role' => 'Admin']);
        self::assertResponseStatusCodeSame(403);

        $this->requestAs($this->adminToken, 'PATCH', '/api/workspaces/'.$this->workspace->getId().'/members/'.$admin->getId(), ['role' => 'Viewer']);
        self::assertResponseStatusCodeSame(422);

        $this->requestAs($this->adminToken, 'PATCH', '/api/workspaces/'.$this->workspace->getId().'/members/'.$this->owner->getId(), ['role' => 'Viewer']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testOrdinaryMemberCannotManageMembersOrAttackAnotherWorkspace(): void
    {
        $this->requestAs($this->memberToken, 'PATCH', '/api/workspaces/'.$this->workspace->getId().'/members/'.$this->viewer->getId(), ['role' => 'Admin']);
        self::assertResponseStatusCodeSame(403);

        $this->requestAs($this->memberToken, 'DELETE', '/api/workspaces/'.$this->workspace->getId().'/members/'.$this->viewer->getId());
        self::assertResponseStatusCodeSame(403);

        $this->requestAs($this->ownerToken, 'PATCH', '/api/workspaces/'.$this->workspace->getId().'/members/'.$this->outsider->getId(), ['role' => 'Editor']);
        self::assertResponseStatusCodeSame(404);

        $this->requestAs($this->ownerToken, 'DELETE', '/api/workspaces/'.$this->otherWorkspace->getId().'/members/'.$this->editor->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testRemovingAMemberRevokesAccessAndBoardMemberships(): void
    {
        $this->requestAs($this->ownerToken, 'DELETE', '/api/workspaces/'.$this->workspace->getId().'/members/'.$this->editor->getId());
        self::assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $removed = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $this->editor->getEmail()]);
        self::assertInstanceOf(User::class, $removed);
        self::assertFalse($removed->hasActiveWorkspaceMembership());
        self::assertNull($removed->getApiTokenHash());

        $boardMemberships = (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM board_members WHERE user_id = :user',
            ['user' => $removed->getId()],
        );
        self::assertSame(0, $boardMemberships);

        $this->requestAs($this->editorToken, 'GET', '/api/boards');
        self::assertResponseStatusCodeSame(401);
        $this->requestAs($this->editorToken, 'GET', '/api/workspaces/'.$this->workspace->getId().'/members');
        self::assertResponseStatusCodeSame(401);

        $this->requestAs(null, 'POST', '/api/auth/login', ['email' => $this->editor->getEmail(), 'password' => 'Correct-Horse-2026!']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testOwnerAndSelfRemovalAreRejected(): void
    {
        $this->requestAs($this->ownerToken, 'DELETE', '/api/workspaces/'.$this->workspace->getId().'/members/'.$this->owner->getId());
        self::assertResponseStatusCodeSame(422);

        $admin = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'admin@example.test']);
        self::assertInstanceOf(User::class, $admin);
        $this->requestAs($this->adminToken, 'DELETE', '/api/workspaces/'.$this->workspace->getId().'/members/'.$admin->getId());
        self::assertResponseStatusCodeSame(422);
    }

    public function testAdminCannotRemoveOwnerOrAnotherAdmin(): void
    {
        $admin = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'admin@example.test']);
        self::assertInstanceOf(User::class, $admin);

        $this->requestAs($this->adminToken, 'DELETE', '/api/workspaces/'.$this->workspace->getId().'/members/'.$this->owner->getId());
        self::assertResponseStatusCodeSame(422);

        $this->requestAs($this->adminToken, 'DELETE', '/api/workspaces/'.$this->workspace->getId().'/members/'.$admin->getId());
        self::assertResponseStatusCodeSame(422);
    }

    public function testAdminCanRemoveAnOrdinaryMember(): void
    {
        $this->requestAs($this->adminToken, 'DELETE', '/api/workspaces/'.$this->workspace->getId().'/members/'.$this->viewer->getId());
        self::assertResponseStatusCodeSame(204);
    }

    public function testWorkspaceSummaryUsesActiveMemberCountAndOrdinaryMembersCannotReachPrivateBoards(): void
    {
        $this->requestAs($this->ownerToken, 'GET', '/api/workspaces/'.$this->workspace->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(6, $this->jsonResponse()['memberCount']);

        $private = new Board($this->workspace, 'Private board');
        $private->addMember($this->owner, 'Owner');
        $this->entityManager->persist($private);
        $this->entityManager->flush();

        $this->requestAs($this->editorToken, 'GET', '/api/boards/'.$private->getId());
        self::assertResponseStatusCodeSame(403);
        $this->requestAs($this->editorToken, 'GET', '/api/boards/'.$private->getId().'/elements');
        self::assertResponseStatusCodeSame(403);
        $this->requestAs($this->adminToken, 'GET', '/api/boards/'.$private->getId());
        self::assertResponseIsSuccessful();
    }

    public function testAdminCannotManageAnotherAdmin(): void
    {
        $otherAdminToken = 'workspace-other-admin-token-123456789012';
        $otherAdmin = $this->makeUser($this->workspace, 'other-admin@example.test', 'Admin', $otherAdminToken);

        $this->requestAs($this->adminToken, 'PATCH', '/api/workspaces/'.$this->workspace->getId().'/members/'.$otherAdmin->getId(), ['role' => 'Viewer']);
        self::assertResponseStatusCodeSame(403);

        $this->requestAs($this->adminToken, 'DELETE', '/api/workspaces/'.$this->workspace->getId().'/members/'.$otherAdmin->getId());
        self::assertResponseStatusCodeSame(403);

        $this->requestAs($this->adminToken, 'PATCH', '/api/workspaces/'.$this->workspace->getId().'/members/'.$otherAdmin->getId(), ['role' => 'Admin']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testWorkspaceSettingsAreAuthorizedValidatedAndPersisted(): void
    {
        $this->requestAs($this->ownerToken, 'PATCH', '/api/workspaces/'.$this->workspace->getId(), ['name' => 'Renamed Studio']);
        self::assertResponseIsSuccessful();
        self::assertSame('Renamed Studio', $this->jsonResponse()['name']);

        $this->requestAs($this->memberToken, 'GET', '/api/workspaces/'.$this->workspace->getId());
        self::assertResponseIsSuccessful();
        self::assertSame('Renamed Studio', $this->jsonResponse()['name']);

        $this->requestAs($this->memberToken, 'PATCH', '/api/workspaces/'.$this->workspace->getId(), ['name' => 'Member rename']);
        self::assertResponseStatusCodeSame(403);

        $this->requestAs($this->ownerToken, 'PATCH', '/api/workspaces/'.$this->workspace->getId(), ['name' => 'A']);
        self::assertResponseStatusCodeSame(422);

        $this->requestAs($this->ownerToken, 'PATCH', '/api/workspaces/'.$this->workspace->getId(), ['name' => 'Valid', 'seats' => 1]);
        self::assertResponseStatusCodeSame(422);

        $this->requestAs($this->outsiderToken, 'PATCH', '/api/workspaces/'.$this->workspace->getId(), ['name' => 'Hijack']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testWorkspaceInvitationIsHashedAndListedWithoutSecrets(): void
    {
        $created = $this->invite('new-editor@example.test', 'Editor');
        self::assertArrayHasKey('token', $created);
        self::assertTrue($created['emailSent']);

        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT token_hash FROM workspace_invitations WHERE id = :id',
            ['id' => $created['invitation']['id']],
        );
        self::assertIsArray($row);
        self::assertSame(hash('sha256', $created['token']), $row['token_hash']);
        self::assertNotSame($created['token'], $row['token_hash']);

        $this->requestAs($this->adminToken, 'GET', '/api/workspaces/'.$this->workspace->getId().'/invitations');
        self::assertResponseIsSuccessful();
        $invitations = $this->jsonResponse()['invitations'];
        self::assertIsArray($invitations);
        self::assertCount(1, $invitations);
        self::assertArrayNotHasKey('token', $invitations[0]);
        self::assertArrayNotHasKey('token_hash', $invitations[0]);
        self::assertStringNotContainsString($created['token'], (string) $this->client->getResponse()->getContent());

        $this->requestAs($this->memberToken, 'GET', '/api/workspaces/'.$this->workspace->getId().'/invitations');
        self::assertResponseStatusCodeSame(403);
    }

    public function testInvitationPermissionsAndValidation(): void
    {
        $this->requestAs($this->adminToken, 'POST', '/api/workspaces/'.$this->workspace->getId().'/invitations', ['email' => 'admin-invite@example.test', 'role' => 'Admin']);
        self::assertResponseStatusCodeSame(403);

        $this->requestAs($this->adminToken, 'POST', '/api/workspaces/'.$this->workspace->getId().'/invitations', ['email' => 'editor-invite@example.test', 'role' => 'Editor']);
        self::assertResponseStatusCodeSame(201);

        $this->requestAs($this->memberToken, 'POST', '/api/workspaces/'.$this->workspace->getId().'/invitations', ['email' => 'blocked@example.test', 'role' => 'Viewer']);
        self::assertResponseStatusCodeSame(403);

        $this->requestAs($this->ownerToken, 'POST', '/api/workspaces/'.$this->workspace->getId().'/invitations', ['email' => 'bad-email', 'role' => 'Viewer']);
        self::assertResponseStatusCodeSame(422);

        $this->requestAs($this->ownerToken, 'POST', '/api/workspaces/'.$this->workspace->getId().'/invitations', ['email' => 'bad-role@example.test', 'role' => 'Owner']);
        self::assertResponseStatusCodeSame(422);

        $this->requestAs($this->ownerToken, 'POST', '/api/workspaces/'.$this->workspace->getId().'/invitations', ['email' => $this->viewer->getEmail(), 'role' => 'Viewer']);
        self::assertResponseStatusCodeSame(409);

        $this->invite('duplicate@example.test', 'Viewer');
        $this->requestAs($this->ownerToken, 'POST', '/api/workspaces/'.$this->workspace->getId().'/invitations', ['email' => 'duplicate@example.test', 'role' => 'Viewer']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testWorkspaceInvitationCanBeAcceptedDuringRegistration(): void
    {
        $created = $this->invite('new-admin@example.test', 'Admin');

        $this->requestAs(null, 'POST', '/api/auth/register', [
            'name' => 'New Admin',
            'email' => 'new-admin@example.test',
            'password' => 'Valid-Password-2026!',
            'workspaceInvitationToken' => $created['token'],
        ]);
        self::assertResponseStatusCodeSame(201);

        $payload = $this->jsonResponse();
        self::assertSame($this->workspace->getId(), $payload['workspace']['id']);
        self::assertSame('Admin', $payload['user']['role']);
        self::assertSame('active', $payload['user']['status']);

        $this->requestAs($payload['token'], 'GET', '/api/workspaces/'.$this->workspace->getId().'/members');
        self::assertResponseIsSuccessful();

        $this->requestAs(null, 'POST', '/api/auth/register', [
            'name' => 'Replay',
            'email' => 'new-admin@example.test',
            'password' => 'Valid-Password-2026!',
            'workspaceInvitationToken' => $created['token'],
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testWorkspaceInvitationCannotBeUsedByAnotherEmail(): void
    {
        $created = $this->invite('intended@example.test', 'Viewer');

        $this->requestAs(null, 'POST', '/api/auth/register', [
            'name' => 'Wrong Person',
            'email' => 'other@example.test',
            'password' => 'Valid-Password-2026!',
            'workspaceInvitationToken' => $created['token'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testRevokedAndExpiredWorkspaceInvitationsAreRejected(): void
    {
        $created = $this->invite('revoked@example.test', 'Viewer');
        $this->requestAs($this->ownerToken, 'DELETE', '/api/workspaces/'.$this->workspace->getId().'/invitations/'.$created['invitation']['id']);
        self::assertResponseStatusCodeSame(204);

        $this->requestAs(null, 'POST', '/api/auth/register', [
            'name' => 'Revoked', 'email' => 'revoked@example.test', 'password' => 'Valid-Password-2026!',
            'workspaceInvitationToken' => $created['token'],
        ]);
        self::assertResponseStatusCodeSame(422);

        $expired = $this->invite('expired@example.test', 'Viewer');
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE workspace_invitations SET expires_at = :past WHERE id = :id',
            ['past' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:sP'), 'id' => $expired['invitation']['id']],
        );

        $this->requestAs(null, 'POST', '/api/auth/register', [
            'name' => 'Expired', 'email' => 'expired@example.test', 'password' => 'Valid-Password-2026!',
            'workspaceInvitationToken' => $expired['token'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAdminCanRevokeInvitationButMemberCannot(): void
    {
        $created = $this->invite('revoke-admin@example.test', 'Viewer');
        $this->requestAs($this->memberToken, 'DELETE', '/api/workspaces/'.$this->workspace->getId().'/invitations/'.$created['invitation']['id']);
        self::assertResponseStatusCodeSame(403);

        $this->requestAs($this->adminToken, 'DELETE', '/api/workspaces/'.$this->workspace->getId().'/invitations/'.$created['invitation']['id']);
        self::assertResponseStatusCodeSame(204);
    }

    public function testRemovedMemberCannotReachPrivateBoardEndpoints(): void
    {
        $this->requestAs($this->ownerToken, 'DELETE', '/api/workspaces/'.$this->workspace->getId().'/members/'.$this->editor->getId());
        self::assertResponseStatusCodeSame(204);

        $this->requestAs($this->editorToken, 'GET', '/api/boards/'.$this->board->getId());
        self::assertResponseStatusCodeSame(401);
        $this->requestAs($this->editorToken, 'GET', '/api/boards/'.$this->board->getId().'/elements');
        self::assertResponseStatusCodeSame(401);
    }

    /** @return array<string, mixed> */
    private function invite(string $email, string $role): array
    {
        $this->requestAs($this->ownerToken, 'POST', '/api/workspaces/'.$this->workspace->getId().'/invitations', [
            'email' => $email, 'role' => $role,
        ]);
        self::assertResponseStatusCodeSame(201);

        return $this->jsonResponse();
    }
}
