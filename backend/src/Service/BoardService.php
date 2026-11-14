<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Board;
use App\Entity\CanvasElement;
use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;

final class BoardService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param list<string> $noteColors
     */
    public function createBoard(Workspace $workspace, string $title, array $noteColors, ?User $owner = null): Board
    {
        $board = new Board($workspace, $title, $noteColors);
        if (null !== $owner) {
            $board->addMember($owner);
        }

        $this->entityManager->persist($board);
        $this->entityManager->flush();

        return $board;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function addElement(Board $board, User $author, array $input): CanvasElement
    {
        foreach (['type', 'x', 'y', 'width', 'height'] as $required) {
            if (!array_key_exists($required, $input)) {
                throw new \InvalidArgumentException(sprintf('Missing "%s" for the canvas element.', $required));
            }
        }

        $metadata = $input['metadata'] ?? null;
        if (null !== $metadata && !is_array($metadata)) {
            throw new \InvalidArgumentException('Element metadata must be an object.');
        }

        $element = new CanvasElement(
            $board,
            $author,
            (string) $input['type'],
            (float) $input['x'],
            (float) $input['y'],
            (float) $input['width'],
            (float) $input['height'],
            (float) ($input['rotation'] ?? 0.0),
            isset($input['content']) ? (string) $input['content'] : null,
            isset($input['color']) ? (string) $input['color'] : null,
            $metadata,
        );

        $this->entityManager->persist($element);
        $board->touch();
        $this->entityManager->flush();

        return $element;
    }

    public function renameBoard(Board $board, string $title): void
    {
        $board->rename($title);
        $this->entityManager->flush();
    }

    public function setStarred(Board $board, bool $starred): void
    {
        $board->setStarred($starred);
        $this->entityManager->flush();
    }
}
