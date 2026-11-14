<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CommentThread;

final class CommentApiControllerTest extends ApiFunctionalTestCase
{
    public function testCommenterCanCreateReplyResolveAndReopenThread(): void
    {
        $this->requestAs($this->commenterToken, 'POST', '/api/boards/'.$this->board->getId().'/comments', ['x' => 240, 'y' => 180, 'content' => 'Review this note']);
        self::assertResponseStatusCodeSame(201);
        $thread = $this->jsonResponse();
        self::assertSame('Review this note', $thread['messages'][0]['content']);
        $threadId = $thread['id'];

        $this->requestAs($this->commenterToken, 'POST', '/api/comments/threads/'.$threadId.'/messages', ['content' => 'I have a suggestion']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('I have a suggestion', $this->jsonResponse()['content']);

        $this->requestAs($this->commenterToken, 'PATCH', '/api/comments/threads/'.$threadId.'/resolve', ['resolved' => true]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonResponse()['resolved']);

        $this->requestAs($this->commenterToken, 'PATCH', '/api/comments/threads/'.$threadId.'/resolve', ['resolved' => false]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->jsonResponse()['resolved']);
    }

    public function testViewerCannotCommentAndThreadMessagesAreReturned(): void
    {
        $this->requestAs($this->viewerToken, 'POST', '/api/boards/'.$this->board->getId().'/comments', ['x' => 10, 'y' => 15, 'content' => 'No permission']);
        self::assertResponseStatusCodeSame(403);

        $thread = new CommentThread($this->board, $this->owner, 10, 15);
        $thread->addMessage($this->owner, 'Existing comment');
        $this->entityManager->persist($thread);
        $this->entityManager->flush();
        $this->requestAs($this->viewerToken, 'GET', '/api/boards/'.$this->board->getId().'/comments');
        self::assertResponseIsSuccessful();
        self::assertSame('Existing comment', $this->jsonResponse()['threads'][0]['messages'][0]['content']);
    }
}
