<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Board;
use App\Entity\BoardInvitation;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;

final class BoardApiControllerTest extends ApiFunctionalTestCase
{
    use MailerAssertionsTrait;

    public function testBoardsRequireAuthenticationAndAreScopedToMembers(): void
    {
        $this->requestAs(null, 'GET', '/api/boards');
        self::assertResponseStatusCodeSame(401);

        $this->requestAs($this->ownerToken, 'GET', '/api/boards');
        self::assertResponseIsSuccessful();
        $boards = $this->jsonResponse()['boards'];
        self::assertCount(1, $boards);
        self::assertSame($this->board->getId(), $boards[0]['id']);

        $this->requestAs($this->outsiderToken, 'GET', '/api/boards/'.$this->board->getId());
        self::assertResponseStatusCodeSame(403);
    }

    public function testBoardWritesHonorBoardSpecificRoles(): void
    {
        $payload = ['title' => 'Renamed'];
        $this->requestAs($this->viewerToken, 'PATCH', '/api/boards/'.$this->board->getId(), $payload);
        self::assertResponseStatusCodeSame(403);

        $this->requestAs($this->editorToken, 'PATCH', '/api/boards/'.$this->board->getId(), $payload);
        self::assertResponseIsSuccessful();
        self::assertSame('Renamed', $this->jsonResponse()['title']);
    }

    public function testBoardUpdatesAcceptJsonObjectBodies(): void
    {
        $uri = '/api/boards/'.$this->board->getId();

        $this->requestWithRawBody('PATCH', $uri, '{"title":"test"}', $this->ownerToken);
        self::assertResponseIsSuccessful();
        self::assertSame('test', $this->jsonResponse()['title']);

        // Unknown keys stay a no-op, as before the payload validation changed.
        $this->requestWithRawBody('PATCH', $uri, '{"name":"test"}', $this->ownerToken);
        self::assertResponseIsSuccessful();

        // An empty object is still a valid JSON object.
        $this->requestWithRawBody('PATCH', $uri, '{}', $this->ownerToken);
        self::assertResponseIsSuccessful();
        self::assertSame('test', $this->jsonResponse()['title']);
    }

    /** @return iterable<string, array{string, int}> */
    public static function nonObjectBodies(): iterable
    {
        yield 'empty array' => ['[]', 422];
        yield 'array with a value' => ['["unexpected"]', 422];
        yield 'string' => ['"text"', 422];
        yield 'number' => ['123', 422];
        yield 'null' => ['null', 422];
        yield 'malformed json' => ['{"title":', 400];
    }

    #[DataProvider('nonObjectBodies')]
    public function testBoardUpdatesRejectNonObjectBodies(string $body, int $status): void
    {
        $this->requestWithRawBody('PATCH', '/api/boards/'.$this->board->getId(), $body, $this->ownerToken);
        self::assertResponseStatusCodeSame($status);
    }

    public function testBoardCreationAcceptsAStringTitle(): void
    {
        $this->requestAs($this->ownerToken, 'POST', '/api/boards', ['title' => '  Roadmap 2027  ']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Roadmap 2027', $this->jsonResponse()['title']);
        // One board, its owner membership and its opening history entry.
        self::assertSame(2, $this->countRows('boards'));
        self::assertSame(5, $this->countRows('board_members'));
        self::assertSame(1, $this->countRows('timeline_entries'));
    }

    /**
     * A title that is not a string used to be cast to one, so `["a"]` became the
     * board title "Array" and the board was created. The invalid payload must be
     * rejected before the transaction opens, leaving no board, membership or
     * history entry behind.
     *
     * @return iterable<string, array{string}>
     */
    public static function nonStringTitles(): iterable
    {
        yield 'empty array' => ['{"title":[]}'];
        yield 'array with a value' => ['{"title":["Renamed"]}'];
        yield 'object' => ['{"title":{"name":"Renamed"}}'];
        yield 'empty object' => ['{"title":{}}'];
        yield 'integer' => ['{"title":42}'];
        yield 'float' => ['{"title":1.5}'];
        yield 'boolean true' => ['{"title":true}'];
        yield 'boolean false' => ['{"title":false}'];
    }

    #[DataProvider('nonStringTitles')]
    public function testBoardCreationRejectsANonStringTitle(string $body): void
    {
        $this->requestWithRawBody('POST', '/api/boards', $body, $this->ownerToken);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Board title must be a string.'], $this->jsonResponse());
        self::assertSame(1, $this->countRows('boards'));
        self::assertSame(4, $this->countRows('board_members'));
        self::assertSame(0, $this->countRows('timeline_entries'));
    }

    public function testBoardCreationKeepsItsBehaviourForAMissingTitle(): void
    {
        // The body is an object that simply has no title key.
        $this->requestWithRawBody('POST', '/api/boards', '{}', $this->ownerToken);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Board title is required.'], $this->jsonResponse());
        $this->assertNothingWasCreated();
    }

    public function testBoardCreationKeepsItsBehaviourForAnEmptyTitle(): void
    {
        $this->requestAs($this->ownerToken, 'POST', '/api/boards', ['title' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Board title is required.'], $this->jsonResponse());
        $this->assertNothingWasCreated();
    }

    public function testBoardCreationRejectsAWhitespaceOnlyTitle(): void
    {
        $this->requestAs($this->ownerToken, 'POST', '/api/boards', ['title' => "   \t  "]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Board title is required.'], $this->jsonResponse());
        $this->assertNothingWasCreated();
    }

    private function assertNothingWasCreated(): void
    {
        self::assertSame(1, $this->countRows('boards'));
        self::assertSame(4, $this->countRows('board_members'));
        self::assertSame(0, $this->countRows('timeline_entries'));
    }

    public function testCreatingBoardUsesAuthenticatedOwnerAndCannotSelectAnotherWorkspace(): void
    {
        $this->requestAs($this->ownerToken, 'POST', '/api/boards', [
            'title' => 'Owned by actor', 'workspaceId' => $this->otherWorkspace->getId(),
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->requestAs($this->ownerToken, 'POST', '/api/boards', ['title' => 'Owned by actor']);
        self::assertResponseStatusCodeSame(201);
        $created = $this->jsonResponse();
        self::assertSame($this->owner->getId(), $created['members'][0]['id']);
        self::assertSame('Owner', $created['members'][0]['boardRole']);
    }

    public function testOwnerCanChangeAndRemoveBoardMembership(): void
    {
        $this->requestAs($this->ownerToken, 'PATCH', '/api/boards/'.$this->board->getId().'/members/'.$this->viewer->getId(), ['role' => 'Commenter']);
        self::assertResponseIsSuccessful();
        $roles = array_column($this->jsonResponse()['members'], 'boardRole', 'id');
        self::assertSame('Commenter', $roles[$this->viewer->getId()]);

        $this->requestAs($this->ownerToken, 'DELETE', '/api/boards/'.$this->board->getId().'/members/'.$this->viewer->getId());
        self::assertResponseStatusCodeSame(204);
        $this->requestAs($this->viewerToken, 'GET', '/api/boards/'.$this->board->getId());
        self::assertResponseStatusCodeSame(403);
    }

    public function testPublicLinkControlsUnauthenticatedBoardAndCanvasReads(): void
    {
        $this->requestAs($this->ownerToken, 'PATCH', '/api/boards/'.$this->board->getId().'/access', ['publicAccess' => true]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonResponse()['publicAccess']);

        $this->requestAs(null, 'GET', '/api/boards/'.$this->board->getId());
        self::assertResponseIsSuccessful();
        $this->requestAs(null, 'GET', '/api/boards/'.$this->board->getId().'/elements');
        self::assertResponseIsSuccessful();

        $this->requestAs($this->ownerToken, 'PATCH', '/api/boards/'.$this->board->getId().'/access', ['publicAccess' => false]);
        self::assertResponseIsSuccessful();
        $this->requestAs(null, 'GET', '/api/boards/'.$this->board->getId().'/elements');
        self::assertResponseStatusCodeSame(403);
    }

    public function testPublicLinkReturnsOnlyReadOnlyBoardData(): void
    {
        $this->createElement($this->ownerToken, 'Public workshop note');
        $this->board->setPublicAccess(true);
        $this->entityManager->flush();

        $this->requestAs(null, 'GET', '/api/boards/'.$this->board->getId());
        self::assertResponseIsSuccessful();
        $public = $this->jsonResponse();
        self::assertSame([], $public['members']);
        self::assertArrayNotHasKey('comments', $public);
        self::assertArrayNotHasKey('timeline', $public);
        self::assertSame('Public workshop note', $public['elements'][0]['content']);
        self::assertArrayNotHasKey('email', $public['elements'][0]['author']);
        self::assertArrayNotHasKey('city', $public['elements'][0]['author']);

        $this->requestAs(null, 'PATCH', '/api/boards/'.$this->board->getId(), ['title' => 'Tampered']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testPublicBoardsAreNotListedForOtherWorkspaces(): void
    {
        $this->board->setPublicAccess(true);
        $this->entityManager->flush();

        // The direct link still works for the outsider...
        $this->requestAs($this->outsiderToken, 'GET', '/api/boards/'.$this->board->getId());
        self::assertResponseIsSuccessful();

        // ...but it must not appear in their board list.
        $this->requestAs($this->outsiderToken, 'GET', '/api/boards');
        self::assertResponseIsSuccessful();
        $ids = array_column($this->jsonResponse()['boards'], 'id');
        self::assertNotContains($this->board->getId(), $ids);
    }

    public function testInvitationIsEmailBoundSingleUseAndGrantsOnlyBoardRole(): void
    {
        $this->requestAs($this->ownerToken, 'POST', '/api/boards/'.$this->board->getId().'/invitations', [
            'email' => 'new-collaborator@example.test', 'role' => 'Editor',
        ]);
        self::assertResponseStatusCodeSame(201);
        $invitation = $this->jsonResponse();
        self::assertArrayHasKey('token', $invitation);
        self::assertSame('new-collaborator@example.test', $invitation['email']);

        $storedHash = $this->entityManager->getConnection()->fetchOne('SELECT token_hash FROM board_invitations');
        self::assertSame(hash('sha256', $invitation['token']), $storedHash);
        self::assertNotSame($invitation['token'], $storedHash);

        $this->requestAs(null, 'POST', '/api/auth/register', [
            'name' => 'Wrong Person', 'email' => 'wrong@example.test', 'password' => 'Long-Password-2026!', 'invitationToken' => $invitation['token'],
        ]);
        self::assertResponseStatusCodeSame(422);

        $this->requestAs(null, 'POST', '/api/auth/register', [
            'name' => 'New Collaborator', 'email' => 'new-collaborator@example.test', 'password' => 'Long-Password-2026!', 'invitationToken' => $invitation['token'],
        ]);
        self::assertResponseStatusCodeSame(201);
        $registered = $this->jsonResponse();
        self::assertSame($this->workspace->getId(), $registered['workspace']['id']);
        self::assertSame('Viewer', $registered['user']['role']);

        $this->requestAs($registered['token'], 'POST', '/api/boards/invitations/'.$invitation['token'].'/accept');
        self::assertResponseStatusCodeSame(404);

        $this->requestAs($registered['token'], 'GET', '/api/boards/'.$this->board->getId());
        self::assertResponseIsSuccessful();
        $roles = array_column($this->jsonResponse()['members'], 'boardRole', 'email');
        self::assertSame('Editor', $roles['new-collaborator@example.test']);
    }

    public function testExistingAccountCanAcceptInvitationOnlyForMatchingEmailAndWorkspace(): void
    {
        $this->requestAs($this->ownerToken, 'POST', '/api/boards/'.$this->board->getId().'/invitations', [
            'email' => $this->editor->getEmail(), 'role' => 'Commenter',
        ]);
        self::assertResponseStatusCodeSame(201);
        $token = $this->jsonResponse()['token'];

        $this->requestAs($this->editorToken, 'POST', '/api/boards/invitations/'.$token.'/accept');
        self::assertResponseIsSuccessful();
        $this->requestAs($this->editorToken, 'POST', '/api/boards/invitations/'.$token.'/accept');
        self::assertResponseStatusCodeSame(404);

        $this->requestAs($this->ownerToken, 'POST', '/api/boards/'.$this->board->getId().'/invitations', [
            'email' => $this->outsider->getEmail(), 'role' => 'Viewer',
        ]);
        self::assertResponseStatusCodeSame(201);
        $otherToken = $this->jsonResponse()['token'];
        $this->requestAs($this->outsiderToken, 'POST', '/api/boards/invitations/'.$otherToken.'/accept');
        self::assertResponseStatusCodeSame(403);
    }

    public function testExpiredInvitationCannotBeAccepted(): void
    {
        $token = 'expired-invitation-token';
        $invitation = new BoardInvitation($this->board, $this->owner, $this->editor->getEmail(), 'Viewer', hash('sha256', $token), new \DateTimeImmutable('-8 days'));
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        $this->requestAs($this->editorToken, 'POST', '/api/boards/invitations/'.$token.'/accept');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTokenInvitationDispatchesBrandedEmail(): void
    {
        $this->requestAs($this->ownerToken, 'POST', '/api/boards/'.$this->board->getId().'/invitations', [
            'email' => 'mail-invite@example.test', 'role' => 'Editor',
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertTrue($this->jsonResponse()['emailSent']);

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', 'mail-invite@example.test');
        self::assertEmailSubjectContains($email, 'Mosaiq invitation');
        self::assertEmailTextBodyContains($email, 'Editor');
        self::assertEmailHtmlBodyContains($email, '#6D4AFF');
    }

    public function testAddingAnExistingMemberAlsoSendsNotificationEmail(): void
    {
        $this->requestAs($this->ownerToken, 'POST', '/api/boards/'.$this->board->getId().'/members', [
            'email' => $this->viewer->getEmail(), 'role' => 'Commenter',
        ]);
        self::assertResponseIsSuccessful();

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', $this->viewer->getEmail());
    }
}
