<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BoardInvitation;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceInvitation;
use App\Repository\BoardInvitationRepository;
use App\Repository\UserRepository;
use App\Repository\WorkspaceInvitationRepository;
use App\Security\AuthThrottle;
use App\Security\PasswordPolicy;
use App\Service\CanvasSerializer;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth', name: 'api_auth_')]
final class AuthApiController extends AbstractController
{
    use JsonPayloadTrait;

    public function __construct(
        private readonly UserRepository $users,
        private readonly BoardInvitationRepository $invitations,
        private readonly WorkspaceInvitationRepository $workspaceInvitations,
        private readonly EntityManagerInterface $entityManager,
        private readonly CanvasSerializer $serializer,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly AuthThrottle $throttle,
        private readonly int $tokenTtl,
    ) {
        if ($this->tokenTtl < 60) {
            throw new \InvalidArgumentException('AUTH_TOKEN_TTL must be at least 60 seconds.');
        }
    }

    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        try {
            $payload = $this->decodePayload($request);
        } catch (\JsonException|\InvalidArgumentException) {
            return $this->json(['error' => 'Malformed JSON payload.'], 400);
        }

        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $password = (string) ($payload['password'] ?? '');
        $ip = $request->getClientIp();

        if (null !== $retryAfter = $this->throttle->loginRetryAfter($email, $ip)) {
            return $this->tooManyAttempts($retryAfter);
        }

        $user = $this->users->findByEmail($email);
        if (!$user instanceof User || !$user->hasActiveWorkspaceMembership() || null === $user->getPassword() || !$this->passwordHasher->isPasswordValid($user, $password)) {
            $this->throttle->recordLoginFailure($email, $ip);

            return $this->json(['error' => 'Invalid email or password.'], 401);
        }

        $this->throttle->clearLoginFailures($email, $ip);

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
            $workspaceInviteToken = (string) ($payload['workspaceInvitationToken'] ?? '');
            if ('' !== $inviteToken && '' !== $workspaceInviteToken) {
                return $this->json(['error' => 'Use one invitation token per registration.'], 422);
            }
            $ip = $request->getClientIp();

            if (null !== $retryAfter = $this->throttle->registrationRetryAfter($ip)) {
                return $this->tooManyAttempts($retryAfter);
            }
            $this->throttle->recordRegistrationAttempt($ip);

            if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->json(['error' => 'Provide a valid email address.'], 422);
            }
            if ('' === $name) {
                return $this->json(['error' => 'Name is required.'], 422);
            }
            $policyError = $this->passwordPolicy->validate($password);
            if (null !== $policyError) {
                return $this->json(['error' => $policyError], 422);
            }
            if ($this->users->findByEmail($email) instanceof User) {
                return $this->json(['error' => 'Email is already registered.'], 409);
            }

            $invitation = '' !== $inviteToken ? $this->invitations->findByToken($inviteToken) : null;
            if ('' !== $inviteToken && (!$invitation instanceof BoardInvitation || !$invitation->isPendingAt() || !$invitation->matchesEmail($email))) {
                return $this->json(['error' => 'Invitation is invalid, expired or belongs to another email.'], 422);
            }
            if ($invitation instanceof BoardInvitation && !$invitation->getBoard()->isActive()) {
                return $this->json(['error' => 'This board is no longer accepting invitations.'], 422);
            }
            $workspaceInvitation = '' !== $workspaceInviteToken ? $this->workspaceInvitations->findByToken($workspaceInviteToken) : null;
            if ('' !== $workspaceInviteToken && (!$workspaceInvitation instanceof WorkspaceInvitation || !$workspaceInvitation->isPendingAt() || !$workspaceInvitation->matchesEmail($email))) {
                return $this->json(['error' => 'Workspace invitation is invalid, expired or belongs to another email.'], 422);
            }
            $workspace = $workspaceInvitation?->getWorkspace() ?? $invitation?->getBoard()->getWorkspace();
            if (!$workspace instanceof Workspace) {
                // Public registration creates an isolated workspace; never attach a new account
                // to a workspace selected by an untrusted client.
                $workspace = new Workspace(sprintf('%s Workspace', mb_substr($name, 0, 100)), 'Starter', 0, 1, new \DateTimeImmutable('+30 days'));
                $this->entityManager->persist($workspace);
            }
            $role = $workspaceInvitation?->getRole() ?? ($invitation instanceof BoardInvitation ? 'Viewer' : 'Owner');
            $user = new User($workspace, $email, $name, $role, 'Tunis', 'cursor-1');
            $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));
            $this->entityManager->persist($user);
            if ($invitation instanceof BoardInvitation) {
                $invitation->accept();
                $invitation->getBoard()->addMember($user, $invitation->getRole());
            }
            $workspaceInvitation?->accept();
            // Flush keeps the account, board membership and accepted invitation in one
            // transaction. The unique users.email index rejects a concurrent duplicate
            // acceptance instead of creating a second membership.
            $this->entityManager->flush();
        } catch (\JsonException) {
            return $this->json(['error' => 'Malformed JSON payload.'], 400);
        } catch (UniqueConstraintViolationException) {
            return $this->json(['error' => 'Email is already registered.'], 409);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
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
        $user->revokeApiToken();
        $this->entityManager->flush();

        return $this->json(['loggedOut' => true]);
    }

    private function issueToken(User $user, int $status = 200): JsonResponse
    {
        $token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $expiresAt = (new \DateTimeImmutable())->modify(sprintf('+%d seconds', $this->tokenTtl));
        $user->issueApiToken(hash('sha256', $token), $expiresAt);
        $this->entityManager->flush();

        return $this->json([
            'token' => $token,
            'expiresAt' => $expiresAt->format(DATE_ATOM),
        ] + $this->profile($user), $status);
    }

    private function tooManyAttempts(int $retryAfter): JsonResponse
    {
        $response = $this->json(
            ['error' => 'Too many authentication attempts. Try again later.'],
            429,
        );
        $response->headers->set('Retry-After', (string) $retryAfter);

        return $response;
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
