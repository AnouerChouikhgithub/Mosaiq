<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Board;
use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schema = new SchemaTool($this->entityManager);
        $schema->dropSchema($metadata);
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
        $user->setApiTokenHash(hash('sha256', $token));
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

    protected function createElement(string $token, string $content = 'snapshot note'): string
    {
        $this->requestAs($token, 'POST', '/api/boards/'.$this->board->getId().'/elements', [
            'type' => 'note', 'x' => 120, 'y' => 130, 'width' => 220, 'height' => 150, 'content' => $content, 'color' => 'amber',
        ]);
        self::assertResponseStatusCodeSame(201);

        return $this->jsonResponse()['id'];
    }
}
