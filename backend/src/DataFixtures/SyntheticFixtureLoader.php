<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Board;
use App\Entity\CanvasElement;
use App\Entity\CommentThread;
use App\Entity\TimelineEntry;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\BoardRepository;
use App\Repository\UserRepository;
use App\Repository\WorkspaceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Loads the synthetic catalog once, then re-running it upserts by natural keys
 * (workspace name, user email, board title, element coordinates, label) and
 * never deletes existing rows.
 */
final class SyntheticFixtureLoader
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SyntheticFixtureCatalog $catalog,
        private readonly WorkspaceRepository $workspaces,
        private readonly UserRepository $users,
        private readonly BoardRepository $boards,
        private readonly string $environment,
        private readonly string $databaseUrl,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function load(): array
    {
        $this->assertSafeTarget();

        $definitions = $this->catalog->definitions();
        $now = new \DateTimeImmutable();
        $stats = array_fill_keys(['workspaces', 'users', 'boards', 'elements', 'threads', 'messages', 'timeline'], 0);

        /** @var array<string, Workspace> $workspaces */
        $workspaces = [];
        foreach ($definitions['workspaces'] as $definition) {
            $workspace = $this->workspaces->findByName((string) $definition['name']);
            if (!$workspace instanceof Workspace) {
                $workspace = new Workspace(
                    (string) $definition['name'],
                    (string) $definition['plan'],
                    (int) $definition['pricePerMember'],
                    (int) $definition['seats'],
                    $now->modify(sprintf('+%d days', (int) $definition['renewsInDays'])),
                    $now,
                );
                $this->entityManager->persist($workspace);
                ++$stats['workspaces'];
            }
            $workspaces[(string) $definition['key']] = $workspace;
        }

        /** @var array<string, User> $users */
        $users = [];
        foreach ($definitions['users'] as $definition) {
            $user = $this->users->findByEmail((string) $definition['email']);
            if (!$user instanceof User) {
                $user = new User(
                    $workspaces[(string) $definition['workspace']],
                    (string) $definition['email'],
                    (string) $definition['name'],
                    (string) $definition['role'],
                    (string) $definition['city'],
                    (string) $definition['accent'],
                    $now,
                );
                $this->entityManager->persist($user);
                ++$stats['users'];
            }
            if (null === $user->getPassword()) {
                $user->setPasswordHash(password_hash('MosaiqDemo2026!', \PASSWORD_DEFAULT));
            }
            $users[(string) $definition['key']] = $user;
        }

        /** @var array<string, Board> $boards */
        $boards = [];
        foreach ($definitions['boards'] as $definition) {
            $workspace = $workspaces[(string) $definition['workspace']];
            $board = $this->boards->findOneByWorkspaceAndTitle($workspace, (string) $definition['title']);
            if (!$board instanceof Board) {
                $board = new Board(
                    $workspace,
                    (string) $definition['title'],
                    array_map(strval(...), $definition['noteColors']),
                    false,
                    $now,
                );
                if (true === ($definition['starred'] ?? false)) {
                    $board->setStarred(true, $now);
                }
                $this->entityManager->persist($board);
                ++$stats['boards'];
            }
            foreach ($definition['members'] as $memberKey) {
                $member = $users[(string) $memberKey];
                $board->addMember($member, $member->getRole());
            }
            $boards[(string) $definition['key']] = $board;
        }

        $this->entityManager->flush();

        /** @var array<string, CanvasElement> $elements */
        $elements = [];
        foreach ($definitions['elements'] as $definition) {
            $board = $boards[(string) $definition['board']];
            $element = $this->entityManager->getRepository(CanvasElement::class)->findOneBy([
                'board' => $board,
                'type' => (string) $definition['type'],
                'x' => (float) $definition['x'],
                'y' => (float) $definition['y'],
            ]);

            $metadata = $definition['metadata'] ?? null;
            if (is_array($metadata)) {
                $resolved = [];
                foreach (['fromKey', 'toKey'] as $relation) {
                    if (isset($metadata[$relation])) {
                        $referenced = $elements[(string) $metadata[$relation]] ?? null;
                        if (!$referenced instanceof CanvasElement) {
                            throw new \RuntimeException(sprintf('Unknown connector target "%s".', (string) $metadata[$relation]));
                        }
                        $resolved['fromKey' === $relation ? 'fromId' : 'toId'] = $referenced->getId();
                    }
                }
                $metadata = $resolved;
            }

            if (!$element instanceof CanvasElement) {
                $element = new CanvasElement(
                    $board,
                    $users[(string) $definition['author']],
                    (string) $definition['type'],
                    (float) $definition['x'],
                    (float) $definition['y'],
                    (float) $definition['width'],
                    (float) $definition['height'],
                    (float) ($definition['rotation'] ?? 0.0),
                    isset($definition['content']) ? (string) $definition['content'] : null,
                    isset($definition['color']) ? (string) $definition['color'] : null,
                    $metadata,
                    $now,
                );
                $this->entityManager->persist($element);
                ++$stats['elements'];
            } elseif (is_array($metadata) && $element->getMetadata() !== $metadata) {
                // Repair rows seeded before the connector mapping fix without
                // deleting anything: a reload rewrites only divergent metadata.
                $element->updateStyle($element->getColor(), $metadata);
            }

            $elements[(string) $definition['key']] = $element;
        }

        $this->entityManager->flush();

        foreach ($definitions['threads'] as $definition) {
            $board = $boards[(string) $definition['board']];
            $thread = $this->entityManager->getRepository(CommentThread::class)->findOneBy([
                'board' => $board,
                'x' => (float) $definition['x'],
                'y' => (float) $definition['y'],
            ]);

            if (!$thread instanceof CommentThread) {
                $thread = new CommentThread(
                    $board,
                    $users[(string) $definition['author']],
                    (float) $definition['x'],
                    (float) $definition['y'],
                    $now->modify(sprintf('-%d minutes', (int) $definition['minutesAgo'])),
                );
                if (true === ($definition['resolved'] ?? false)) {
                    $thread->resolve();
                }
                $this->entityManager->persist($thread);
                ++$stats['threads'];
            }

            $existingContents = [];
            foreach ($thread->getMessages() as $message) {
                $existingContents[$message->getContent()] = true;
            }
            foreach ($definition['messages'] as $message) {
                if (isset($existingContents[(string) $message['content']])) {
                    continue;
                }
                $thread->addMessage(
                    $users[(string) $message['author']],
                    (string) $message['content'],
                    $now->modify(sprintf('-%d minutes', (int) $message['minutesAgo'])),
                );
                ++$stats['messages'];
            }
        }

        foreach ($definitions['timeline'] as $definition) {
            $board = $boards[(string) $definition['board']];
            $existing = $this->entityManager->getRepository(TimelineEntry::class)->findOneBy([
                'board' => $board,
                'label' => (string) $definition['label'],
            ]);
            if ($existing instanceof TimelineEntry) {
                continue;
            }

            $reveals = null;
            if (isset($definition['reveals'])) {
                $reveals = [];
                foreach ($definition['reveals'] as $key) {
                    $reveals[] = $elements[(string) $key]->getId();
                }
            }

            $snapshot = array_map(static fn (CanvasElement $element): array => [
                'id' => $element->getId(),
                'type' => $element->getType(),
                'x' => $element->getX(),
                'y' => $element->getY(),
                'width' => $element->getWidth(),
                'height' => $element->getHeight(),
                'rotation' => $element->getRotation(),
                'content' => $element->getContent(),
                'color' => $element->getColor(),
                'metadata' => $element->getMetadata(),
                'author' => ['id' => $element->getAuthor()->getId()],
                'createdAt' => $element->getCreatedAt()->format(DATE_ATOM),
            ], array_values(array_filter($elements, static fn (CanvasElement $element): bool => $element->getBoard()->getId() === $board->getId())));
            if (isset($definition['reveals'])) {
                $revealedIds = array_fill_keys($reveals ?? [], true);
                $snapshot = array_values(array_filter($snapshot, static fn (array $element): bool => isset($revealedIds[$element['id']])));
            }

            $this->entityManager->persist(new TimelineEntry(
                $board,
                $users[(string) $definition['author']],
                $now->modify(sprintf('-%d minutes', (int) $definition['minutesAgo'])),
                (string) $definition['label'],
                (string) $definition['kind'],
                $reveals,
                $snapshot,
            ));
            ++$stats['timeline'];
        }

        $this->entityManager->flush();

        return $stats;
    }

    /**
     * Refuses anything that does not look like a disposable local database.
     */
    public function assertSafeTarget(): void
    {
        if ('prod' === $this->environment) {
            throw new \RuntimeException('Synthetic fixtures are disabled in the prod environment.');
        }

        $parts = parse_url($this->databaseUrl);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $database = strtolower(ltrim((string) ($parts['path'] ?? ''), '/'));

        $loopback = in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true);
        $knownLocalDatabase = in_array($database, ['mosaiq', 'mosaiq_dev', 'mosaiq_test'], true);
        $disposableName = 1 === preg_match('/(test|dev|fixture)/', $database);

        if (!in_array($scheme, ['postgres', 'postgresql', 'pgsql'], true) || !$loopback || '' === $database || (!$knownLocalDatabase && !$disposableName)) {
            throw new \RuntimeException(sprintf('Refusing to load synthetic fixtures into "%s": expected a loopback PostgreSQL database named mosaiq, or one containing test/dev/fixture.', $this->databaseUrl));
        }
    }
}
