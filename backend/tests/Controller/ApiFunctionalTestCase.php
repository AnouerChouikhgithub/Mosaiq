<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Board;
use App\Entity\TimelineEntry;
use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ApiFunctionalTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;
    protected Workspace $workspace;
    protected Workspace $otherWorkspace;
    protected User $owner;
    protected User $editor;
    protected User $commenter;
    protected User $viewer;
    protected User $outsider;
    protected Board $board;
    protected string $ownerToken = 'owner-test-token-12345678901234567890';
    protected string $editorToken = 'editor-test-token-123456789012345678';
    protected string $commenterToken = 'comment-test-token-123456789012345';
    protected string $viewerToken = 'viewer-test-token-1234567890123456';
    protected string $outsiderToken = 'outsider-test-token-12345678901234';

    /**
     * Captured before any request can replace the entity manager, so database
     * assertions keep working after a request shuts its own manager down.
     *
     * @var array<string, mixed>
     */
    private array $connectionParams = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connectionParams = $this->entityManager->getConnection()->getParams();
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schema = new SchemaTool($this->entityManager);

        // Tests own a disposable database (the test env appends "_test"). Refuse
        // to reset anything else, then drop every table rather than only mapped
        // ones: migration-created foreign keys make SchemaTool::dropSchema()
        // silently skip tables, which breaks a run after `migrations:migrate`.
        $database = (string) $this->entityManager->getConnection()->fetchOne('SELECT current_database()');
        if (!str_contains($database, '_test')) {
            throw new \RuntimeException(sprintf('Refusing to reset non-test database "%s".', $database));
        }
        $schema->dropDatabase();
        $schema->createSchema($metadata);

        $this->workspace = new Workspace('API Test Workspace', 'Pro', 50, 10, new \DateTimeImmutable('+30 days'));
        $this->otherWorkspace = new Workspace('Other API Workspace', 'Starter', 0, 2, new \DateTimeImmutable('+30 days'));
        $this->entityManager->persist($this->workspace);
        $this->entityManager->persist($this->otherWorkspace);
        $this->entityManager->flush();

        $this->owner = $this->makeUser($this->workspace, 'owner@example.test', 'Owner', 'owner-test-token-12345678901234567890');
        $this->editor = $this->makeUser($this->workspace, 'editor@example.test', 'Editor', $this->editorToken);
        $this->commenter = $this->makeUser($this->workspace, 'commenter@example.test', 'Commenter', $this->commenterToken);
        $this->viewer = $this->makeUser($this->workspace, 'viewer@example.test', 'Viewer', $this->viewerToken);
        $this->outsider = $this->makeUser($this->otherWorkspace, 'outsider@example.test', 'Owner', $this->outsiderToken);

        $this->board = new Board($this->workspace, 'Authorized board');
        $this->board->addMember($this->owner, 'Owner');
        $this->board->addMember($this->editor, 'Editor');
        $this->board->addMember($this->commenter, 'Commenter');
        $this->board->addMember($this->viewer, 'Viewer');
        $this->entityManager->persist($this->board);
        $this->entityManager->flush();
    }

    protected function makeUser(Workspace $workspace, string $email, string $role, string $token): User
    {
        static $accent = 0;
        $user = new User($workspace, $email, ucfirst(strstr($email, '@', true) ?: $email), $role, 'Tunis', User::ACCENTS[$accent++ % count(User::ACCENTS)]);
        $user->setPasswordHash(password_hash('Correct-Horse-2026!', PASSWORD_DEFAULT));
        $user->issueApiToken(hash('sha256', $token), new \DateTimeImmutable('+1 day'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /** @param array<string, mixed>|null $payload */
    protected function requestAs(?string $token, string $method, string $uri, ?array $payload = null): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $this->client->request($method, $uri, server: $server, content: null === $payload ? '' : json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    protected function jsonResponse(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** Sends an already-encoded body so non-object JSON documents can be tested. */
    protected function requestWithRawBody(string $method, string $uri, string $content, ?string $token = null): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $this->client->request($method, $uri, server: $server, content: $content);
    }

    /**
     * Fails every flush that schedules a timeline insert: the exact persistence
     * boundary a real storage failure would hit. The request under test must run
     * in this process's entity manager for the listener to observe its flush.
     */
    protected function failTimelinePersistence(): void
    {
        $this->entityManager->getEventManager()->addEventListener([Events::onFlush], new class {
            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                    if ($entity instanceof TimelineEntry) {
                        throw new \RuntimeException('Simulated timeline storage failure.');
                    }
                }
            }
        });
    }

    /**
     * Runs a request that is expected to die at the persistence boundary. The
     * failure must surface: a swallowed exception would hide a partial write.
     */
    protected function assertRequestFailedWithStorageError(callable $request): void
    {
        $failed = false;
        try {
            $request();
            $failed = $this->client->getResponse()->getStatusCode() >= 500;
        } catch (\RuntimeException $exception) {
            $failed = str_contains($exception->getMessage(), 'Simulated timeline storage failure.');
        }

        self::assertTrue($failed, 'The timeline failure must surface instead of being swallowed.');
    }

    /**
     * Counts rows over a dedicated connection so the assertion is unaffected by
     * a request that closed or replaced the entity manager.
     */
    protected function countRows(string $table): int
    {
        $connection = DriverManager::getConnection($this->connectionParams);
        try {
            return (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
        } finally {
            $connection->close();
        }
    }

    protected function createElement(string $token, string $content = 'snapshot note'): string
    {
        $this->requestAs($token, 'POST', '/api/boards/'.$this->board->getId().'/elements', [
            'type' => 'note', 'x' => 120, 'y' => 130, 'width' => 220, 'height' => 150, 'content' => $content, 'color' => 'amber',
        ]);
        self::assertResponseStatusCodeSame(201);

        return $this->jsonResponse()['id'];
    }
}
