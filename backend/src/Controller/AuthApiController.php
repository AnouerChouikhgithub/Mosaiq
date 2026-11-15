<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BoardInvitation;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\BoardInvitationRepository;
use App\Repository\UserRepository;
use App\Service\CanvasSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth', name: 'api_auth_')]
final class AuthApiController extends AbstractController
{
    use JsonPayloadTrait;

    public function __construct(
        private readonly UserRepository $users,
        private readonly BoardInvitationRepository $invitations,
        private readonly EntityManagerInterface $entityManager,
        private readonly CanvasSerializer $serializer,
    ) {
    }

    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        try {
            $payload = $this->decodePayload($request);
        } catch (\JsonException|\InvalidArgumentException) {
            return $this->json(['error' => 'Malformed JSON payload.'], 400);
        }

        $user = $this->users->findByEmail((string) ($payload['email'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        if (!$user instanceof User || null === $user->getPassword() || !password_verify($password, $user->getPassword())) {
            return $this->json(['error' => 'Invalid email or password.'], 401);
        }

        return $this->issueToken($user);
    }

    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        try {
            $payload = $this->decodePayload($request);
            $email = strtolower(trim((string) ($payload['email'] ?? '')));
            $name = trim((string) ($payload['name'] ?? ''));
            $password = (string) ($payload['password'] ?? '');
            $inviteToken = (string) ($payload['invitationToken'] ?? '');
            if (false === filter_var($email, FILTER_VALIDATE_EMAIL) || '' === $name || strlen($password) < 12) {
                return $this->json(['error' => 'Provide a valid email, name and password of at least 12 characters.'], 422);
            }
            if ($this->users->findByEmail($email) instanceof User) {
                return $this->json(['error' => 'Email is already registered.'], 409);
            }

            $invitation = '' !== $inviteToken ? $this->invitations->findByToken($inviteToken) : null;
            if ('' !== $inviteToken && (!$invitation instanceof BoardInvitation || !$invitation->isPendingAt() || !$invitation->matchesEmail($email))) {
                return $this->json(['error' => 'Invitation is invalid, expired or belongs to another email.'], 422);
            }
            $workspace = $invitation?->getBoard()->getWorkspace();
            if (!$workspace instanceof Workspace) {
                // Public registration creates an isolated workspace; never attach a new account
                // to a workspace selected by an untrusted client.
                $workspace = new Workspace(sprintf('%s Workspace', $name), 'Starter', 0, 1, new \DateTimeImmutable('+30 days'));
                $this->entityManager->persist($workspace);
            }
            $user = new User($workspace, $email, $name, $invitation instanceof BoardInvitation ? 'Viewer' : 'Owner', 'Tunis', 'cursor-1');
            $user->setPasswordHash(password_hash($password, PASSWORD_DEFAULT));
            $this->entityManager->persist($user);
            if ($invitation instanceof BoardInvitation) {
                $invitation->accept();
                $invitation->getBoard()->addMember($user, $invitation->getRole());
            }
            $this->entityManager->flush();
        } catch (\JsonException) {
            return $this->json(['error' => 'Malformed JSON payload.'], 400);
        }

        return $this->issueToken($user, 201);
    }

    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();

        return $user instanceof User ? $this->json($this->profile($user)) : $this->json(['error' => 'Authentication required.'], 401);
    }

    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Authentication required.'], 401);
        }
        $user->setApiTokenHash(null);
        $this->entityManager->flush();

        return $this->json(['loggedOut' => true]);
    }

    private function issueToken(User $user, int $status = 200): JsonResponse
    {
        $token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $user->setApiTokenHash(hash('sha256', $token));
        $this->entityManager->flush();

        return $this->json(['token' => $token] + $this->profile($user), $status);
    }

    /** @return array<string, mixed> */
    private function profile(User $user): array
    {
        $workspace = $user->getWorkspace();

        return [
            'user' => $this->serializer->user($user),
            'workspace' => ['id' => $workspace->getId(), 'name' => $workspace->getName(), 'plan' => $workspace->getPlan()],
        ];
    }
}
