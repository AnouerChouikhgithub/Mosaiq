<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CanvasElement;
use App\Entity\CommentMessage;
use App\Entity\CommentThread;
use App\Entity\User;

final class CanvasSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function element(CanvasElement $element): array
    {
        return [
            'id' => $element->getId(),
            'type' => $element->getType(),
            'x' => $element->getX(),
            'y' => $element->getY(),
            'width' => $element->getWidth(),
            'height' => $element->getHeight(),
            'rotation' => $element->getRotation(),
            'content' => $element->getContent(),
            'color' => $element->getColor(),
            'metadata' => $this->metadata($element->getMetadata()),
            // Lets the client reconcile a retried create with the element the
            // server already committed under the same mutation id.
            'mutationId' => $element->getMutationId(),
            'author' => $this->collaborator($element->getAuthor()),
            'createdAt' => $element->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function publicElement(CanvasElement $element): array
    {
        return [
            'id' => $element->getId(),
            'type' => $element->getType(),
            'x' => $element->getX(),
            'y' => $element->getY(),
            'width' => $element->getWidth(),
            'height' => $element->getHeight(),
            'rotation' => $element->getRotation(),
            'content' => $element->getContent(),
            'color' => $element->getColor(),
            'metadata' => $this->metadata($element->getMetadata()),
            // Anonymous readers get the author's display name only.
            'author' => ['id' => '', 'name' => $element->getAuthor()->getName(), 'role' => 'Viewer', 'accent' => 'cursor-1'],
            'createdAt' => $element->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * An empty metadata object has to stay `{}` on the wire: a PHP empty array
     * would serialize as `[]` and look like a JSON array to the client. The root
     * value is stored as `[]` because metadata is never a JSON array, so it is
     * rebuilt here; nested empty objects come back from the database as their
     * original `stdClass` markers and encode to `{}` unchanged.
     *
     * @param array<string, mixed>|null $metadata
     */
    private function metadata(?array $metadata): array|\stdClass|null
    {
        return [] === $metadata ? new \stdClass() : $metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function thread(CommentThread $thread): array
    {
        return [
            'id' => $thread->getId(),
            'x' => $thread->getX(),
            'y' => $thread->getY(),
            'resolved' => $thread->isResolved(),
            'createdAt' => $thread->getCreatedAt()->format(\DATE_ATOM),
            'messages' => array_map(
                fn (CommentMessage $message): array => $this->message($message),
                $thread->getMessages()->toArray(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function message(CommentMessage $message): array
    {
        return [
            'id' => $message->getId(),
            'content' => $message->getContent(),
            'createdAt' => $message->getCreatedAt()->format(\DATE_ATOM),
            'author' => $this->collaborator($message->getAuthor()),
        ];
    }

    /**
     * Profile used inside a board: presence, canvas authors and comments only
     * need the display name, role and cursor accent. Email and city stay out of
     * these payloads because no board screen uses them.
     *
     * @return array<string, mixed>
     */
    public function collaborator(User $user): array
    {
        return [
            'id' => $user->getId(),
            'name' => $user->getName(),
            'role' => $user->getRole(),
            'accent' => $user->getAccent(),
        ];
    }

    /**
     * Member directory profile, used where the workspace already lists every
     * colleague with their email and city (board members, workspace members).
     *
     * @return array<string, mixed>
     */
    public function user(User $user): array
    {
        return [
            'id' => $user->getId(),
            'name' => $user->getName(),
            'email' => $user->getEmail(),
            'role' => $user->getRole(),
            'status' => $user->getMembershipStatus(),
            'city' => $user->getCity(),
            'accent' => $user->getAccent(),
        ];
    }
}
