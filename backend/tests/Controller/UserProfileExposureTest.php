<?php

declare(strict_types=1);

namespace App\Tests\Controller;

/**
 * Presence, canvas authors, comments and the public payload only need a
 * collaborator profile (id, name, role, accent). The member directory keeps the
 * email because the share dialog and the workspace member table display it, and
 * only users who can already list their colleagues can read it.
 */
final class UserProfileExposureTest extends ApiFunctionalTestCase
{
    public function testPresenceDoesNotExposeEmailOrCity(): void
    {
        $this->requestAs($this->editorToken, 'POST', '/api/boards/'.$this->board->getId().'/presence', ['x' => 12, 'y' => 34, 'tool' => 'select']);
        self::assertResponseIsSuccessful();

        $this->requestAs($this->viewerToken, 'GET', '/api/boards/'.$this->board->getId().'/presence');
        self::assertResponseIsSuccessful();
        $presence = $this->jsonResponse()['presence'];
        self::assertCount(1, $presence);

        $profile = $presence[0]['user'];
        self::assertSame($this->editor->getId(), $profile['id']);
        self::assertSame($this->editor->getName(), $profile['name']);
        self::assertArrayHasKey('accent', $profile);
        self::assertArrayNotHasKey('email', $profile);
        self::assertArrayNotHasKey('city', $profile);
    }

    public function testCanvasCommentAndTimelineAuthorsDoNotExposeEmailOrCity(): void
    {
        $this->createElement($this->editorToken, 'Privacy note');

        $this->requestAs($this->commenterToken, 'POST', '/api/boards/'.$this->board->getId().'/comments', [
            'x' => 10, 'y' => 20, 'content' => 'First remark',
        ]);
        self::assertResponseStatusCodeSame(201);
        $createdAuthor = $this->jsonResponse()['messages'][0]['author'];
        self::assertSame($this->commenter->getName(), $createdAuthor['name']);
        self::assertArrayNotHasKey('email', $createdAuthor);
        self::assertArrayNotHasKey('city', $createdAuthor);

        $this->requestAs($this->viewerToken, 'GET', '/api/boards/'.$this->board->getId());
        self::assertResponseIsSuccessful();
        $detail = $this->jsonResponse();

        $authors = [
            'element' => $detail['elements'][0]['author'],
            'comment' => $detail['comments'][0]['messages'][0]['author'],
            'timeline' => $detail['timeline'][0]['author'],
        ];
        foreach ($authors as $source => $author) {
            self::assertArrayNotHasKey('email', $author, $source.' author exposes an email.');
            self::assertArrayNotHasKey('city', $author, $source.' author exposes a city.');
        }
    }

    public function testPublicBoardPayloadDoesNotExposeAuthorProfiles(): void
    {
        $this->createElement($this->editorToken, 'Public privacy note');
        $this->board->setPublicAccess(true);
        $this->entityManager->flush();

        $this->requestAs(null, 'GET', '/api/boards/'.$this->board->getId());
        self::assertResponseIsSuccessful();
        $public = $this->jsonResponse();

        self::assertSame([], $public['members']);
        $author = $public['elements'][0]['author'];
        self::assertSame($this->editor->getName(), $author['name']);
        self::assertArrayNotHasKey('email', $author);
        self::assertArrayNotHasKey('city', $author);
    }

    public function testBoardMemberDirectoryKeepsEmailsForCollaboratorsOnly(): void
    {
        $this->requestAs($this->editorToken, 'GET', '/api/boards/'.$this->board->getId());
        self::assertResponseIsSuccessful();
        $emails = array_column($this->jsonResponse()['members'], 'email', 'id');
        self::assertSame($this->editor->getEmail(), $emails[$this->editor->getId()]);

        $this->requestAs($this->outsiderToken, 'GET', '/api/boards/'.$this->board->getId());
        self::assertResponseStatusCodeSame(403);
    }
}
