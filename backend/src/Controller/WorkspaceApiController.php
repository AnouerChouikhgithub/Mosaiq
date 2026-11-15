<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceInvitation;
use App\Repository\BoardRepository;
use App\Repository\UserRepository;
use App\Repository\WorkspaceInvitationRepository;
use App\Repository\WorkspaceRepository;
use App\Security\WorkspaceMembershipPolicy;
use App\Service\BoardInvitationMailer;
use App\Service\BoardSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[Route('/api/workspaces', name: 'api_workspaces_')]
final class WorkspaceApiController extends AbstractController
{
    use JsonPayloadTrait;

    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly UserRepository $users,
        private readonly BoardRepository $boards,
        private readonly WorkspaceInvitationRepository $invitations,
        private readonly WorkspaceMembershipPolicy $policy,
        private readonly BoardSerializer $serializer,
        private readonly BoardInvitationMailer $mailer,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $user = $this->requireUser();
        $workspace = $user->getWorkspace();
        $boards = $this->boards->findAccessibleInWorkspace($user, $workspace);

        return $this->json(['workspaces' => [$this->summary($workspace, count($boards))]]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $workspace = $this->workspace($id);
        try {
            $this->denyAccessUnlessGranted('WORKSPACE_VIEW', $workspace);
        } catch (AccessDeniedException) {
            return $this->json(['error' => 'Forbidden.'], 403);
        }

        $user = $this->requireUser();
        $boards = $this->boards->findAccessibleInWorkspace($user, $workspace);

        return $this->json($this->summary($workspace, count($boards)) + [
            'boards' => array_map(fn ($board): array => $this->serializer->summary($board), $boards),
        ]);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $workspace = $this->workspace($id);
        try {
            $this->denyAccessUnlessGranted('WORKSPACE_ADMINISTER', $workspace);
            $payload = $this->decodePayload($request);
            if ([] === $payload || [] !== array_diff(array_keys($payload), ['name'])) {
                return $this->json(['error' => 'Only the workspace name can be updated.'], 422);
            }
            $name = $payload['name'] ?? null;
            if (!is_string($name)) {
                return $this->json(['error' => 'Workspace name must be a string.'], 422);
            }
            $workspace->rename($name);
            $this->entityManager->flush();
            $this->log($workspace, 'workspace.settings_updated', ['name' => $workspace->getName()]);
        } catch (AccessDeniedException) {
            return $this->json(['error' => 'Forbidden.'], 403);
        } catch (\JsonException $exception) {
            return $this->json(['error' => 'Malformed JSON payload.'], 400);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }

        return $this->json($this->summary($workspace, count($this->boards->findAccessibleInWorkspace($this->requireUser(), $workspace))));
    }

    #[Route('/{id}/members', name: 'members_index', methods: ['GET'])]
    public function members(string $id): JsonResponse
    {
        $workspace = $this->workspace($id);
        try {
            $this->denyAccessUnlessGranted('WORKSPACE_VIEW', $workspace);
        } catch (AccessDeniedException) {
            return $this->json(['error' => 'Forbidden.'], 403);
        }

        return $this->json([
            'members' => array_map($this->member(...), $this->users->findActiveByWorkspace($workspace)),
        ]);
    }

    #[Route('/{id}/members/{userId}', name: 'members_update', methods: ['PATCH'])]
    public function updateMember(string $id, string $userId, Request $request): JsonResponse
    {
        $workspace = $this->workspace($id);
        $target = $this->memberOf($workspace, $userId);
        if (!$target instanceof User) {
            return $this->json(['error' => 'Workspace member not found.'], 404);
        }
        $actor = $this->requireUser();

        try {
            $this->denyAccessUnlessGranted('WORKSPACE_ADMINISTER', $workspace);
            $payload = $this->decodePayload($request);
            $role = $payload['role'] ?? null;
            if (!is_string($role) || !in_array($role, User::ROLES, true)) {
                return $this->json(['error' => 'Workspace role is not supported.'], 422);
            }
            if ('Owner' === $role) {
                return $this->json(['error' => 'Workspace ownership cannot be granted.'], 422);
            }
            if ('Owner' === $target->getRole()) {
                return $this->json(['error' => 'The workspace owner role cannot be changed.'], 422);
            }
            if ($actor->getId() === $target->getId()) {
                return $this->json(['error' => 'You cannot change your own workspace role.'], 422);
            }
            if (!$this->policy->canChangeRole($actor, $target, $role)) {
                return $this->json(['error' => 'Forbidden.'], 403);
            }

            $previous = $target->getRole();
            $target->changeRole($role);
            $this->entityManager->flush();
            $this->log($workspace, 'workspace.member_role_changed', [
                'target' => $target->getId(), 'from' => $previous, 'to' => $role,
            ]);
        } catch (AccessDeniedException) {
            return $this->json(['error' => 'Forbidden.'], 403);
        } catch (\JsonException) {
            return $this->json(['error' => 'Malformed JSON payload.'], 400);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }

        return $this->json(['member' => $this->member($target)]);
    }

    #[Route('/{id}/members/{userId}', name: 'members_remove', methods: ['DELETE'])]
    public function removeMember(string $id, string $userId): JsonResponse
    {
        $workspace = $this->workspace($id);
        $target = $this->memberOf($workspace, $userId);
        if (!$target instanceof User) {
            return $this->json(['error' => 'Workspace member not found.'], 404);
        }
        $actor = $this->requireUser();

        try {
            $this->denyAccessUnlessGranted('WORKSPACE_ADMINISTER', $workspace);
            if ('Owner' === $target->getRole()) {
                return $this->json(['error' => 'The workspace owner cannot be removed.'], 422);
            }
            if ($actor->getId() === $target->getId()) {
                return $this->json(['error' => 'You cannot remove yourself from the workspace.'], 422);
            }
            if (!$this->policy->canRemove($actor, $target)) {
                return $this->json(['error' => 'Forbidden.'], 403);
            }

            $this->entityManager->wrapInTransaction(function () use ($workspace, $target): void {
                // Board access is independent of workspace membership. Removing the
                // workspace member must also remove every board membership it held.
                foreach ($this->boards->findByWorkspace($workspace) as $board) {
                    $board->removeMember($target);
                }
                $target->removeFromWorkspace();
                $this->entityManager->flush();
            });
            $this->log($workspace, 'workspace.member_removed', ['target' => $target->getId()]);
        } catch (AccessDeniedException) {
            return $this->json(['error' => 'Forbidden.'], 403);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }

        return new JsonResponse(null, 204);
    }

    #[Route('/{id}/invitations', name: 'invitations_index', methods: ['GET'])]
    public function invitations(string $id): JsonResponse
    {
        $workspace = $this->workspace($id);
        try {
            $this->denyAccessUnlessGranted('WORKSPACE_ADMINISTER', $workspace);
        } catch (AccessDeniedException) {
            return $this->json(['error' => 'Forbidden.'], 403);
        }

        return $this->json([
            'invitations' => array_map(
                $this->invitation(...),
                $this->invitations->findForWorkspace($workspace),
            ),
        ]);
    }

    #[Route('/{id}/invitations', name: 'invitations_create', methods: ['POST'])]
    public function createInvitation(string $id, Request $request): JsonResponse
    {
        $workspace = $this->workspace($id);
        try {
            $this->denyAccessUnlessGranted('WORKSPACE_ADMINISTER', $workspace);
            $actor = $this->requireUser();
            $payload = $this->decodePayload($request);
            $email = strtolower(trim((string) ($payload['email'] ?? '')));
            $role = (string) ($payload['role'] ?? '');
            if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->json(['error' => 'Invitation email is invalid.'], 422);
            }
            if (!in_array($role, WorkspaceInvitation::ROLES, true)) {
                return $this->json(['error' => 'Invitation role is not supported.'], 422);
            }
            if (!$this->policy->canInvite($actor, $role)) {
                return $this->json(['error' => 'You cannot invite this role.'], 403);
            }
            if ($this->users->findByEmail($email) instanceof User) {
                return $this->json(['error' => 'An account with this email already exists. Workspace invitations support new accounts only.'], 409);
            }
            if ($this->invitations->findPendingFor($workspace, $email) instanceof WorkspaceInvitation) {
                return $this->json(['error' => 'A pending invitation already exists for this email.'], 409);
            }

            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $invitation = new WorkspaceInvitation($workspace, $actor, $email, $role, hash('sha256', $token));
            $this->entityManager->persist($invitation);
            $this->entityManager->flush();
            $emailSent = $this->mailer->sendWorkspaceInvitation(
                $email,
                $workspace->getName(),
                $actor->getName(),
                $role,
                $token,
                $invitation->getExpiresAt(),
            );
            $this->log($workspace, 'workspace.invitation_created', [
                'invitation' => $invitation->getId(), 'email' => $email, 'role' => $role, 'emailSent' => $emailSent,
            ]);

            return $this->json([
                'invitation' => $this->invitation($invitation),
                'token' => $token,
                'emailSent' => $emailSent,
            ], 201);
        } catch (AccessDeniedException) {
            return $this->json(['error' => 'Forbidden.'], 403);
        } catch (\JsonException) {
            return $this->json(['error' => 'Malformed JSON payload.'], 400);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }
    }

    #[Route('/{id}/invitations/{invitationId}', name: 'invitations_revoke', methods: ['DELETE'])]
    public function revokeInvitation(string $id, string $invitationId): JsonResponse
    {
        $workspace = $this->workspace($id);
        $invitation = $this->invitations->find($invitationId);
        if (!$invitation instanceof WorkspaceInvitation || $invitation->getWorkspace()->getId() !== $workspace->getId()) {
            return $this->json(['error' => 'Invitation not found.'], 404);
        }
        try {
            $this->denyAccessUnlessGranted('WORKSPACE_ADMINISTER', $workspace);
            if (!$invitation->isPendingAt()) {
                return $this->json(['error' => 'Invitation is expired, revoked or already accepted.'], 422);
            }
            $invitation->revoke();
            $this->entityManager->flush();
            $this->log($workspace, 'workspace.invitation_revoked', ['invitation' => $invitation->getId()]);
        } catch (AccessDeniedException) {
            return $this->json(['error' => 'Forbidden.'], 403);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }

        return new JsonResponse(null, 204);
    }

    private function workspace(string $id): Workspace
    {
        $workspace = $this->workspaces->find($id);
        if (!$workspace instanceof Workspace) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Workspace not found.');
        }

        return $workspace;
    }

    private function memberOf(Workspace $workspace, string $userId): ?User
    {
        $member = $this->users->findOneBy(['id' => $userId, 'workspace' => $workspace]);

        return $member instanceof User && $member->hasActiveWorkspaceMembership() ? $member : null;
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->hasActiveWorkspaceMembership()) {
            throw new AccessDeniedException('Authentication required.');
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function summary(Workspace $workspace, int $boardCount): array
    {
        return [
            'id' => $workspace->getId(), 'name' => $workspace->getName(), 'plan' => $workspace->getPlan(),
            'pricePerMember' => $workspace->getPricePerMember(), 'currency' => $workspace->getCurrency(),
            'seats' => $workspace->getSeats(), 'renewsOn' => $workspace->getRenewsOn()->format('Y-m-d'),
            'memberCount' => $this->users->countActiveByWorkspace($workspace), 'boardCount' => $boardCount,
            'createdAt' => $workspace->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function member(User $user): array
    {
        return [
            'id' => $user->getId(),
            'name' => $user->getName(),
            'email' => $user->getEmail(),
            'role' => $user->getRole(),
            'status' => $user->getMembershipStatus(),
            'city' => $user->getCity(),
            'accent' => $user->getAccent(),
            'joinedAt' => $user->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $user->getMembershipUpdatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function invitation(WorkspaceInvitation $invitation): array
    {
        return [
            'id' => $invitation->getId(),
            'email' => $invitation->getEmail(),
            'role' => $invitation->getRole(),
            'status' => $invitation->statusAt(),
            'createdAt' => $invitation->getCreatedAt()->format(DATE_ATOM),
            'expiresAt' => $invitation->getExpiresAt()->format(DATE_ATOM),
            'acceptedAt' => $invitation->getAcceptedAt()?->format(DATE_ATOM),
            'revokedAt' => $invitation->getRevokedAt()?->format(DATE_ATOM),
            'invitedBy' => ['id' => $invitation->getInvitedBy()->getId(), 'name' => $invitation->getInvitedBy()->getName()],
        ];
    }

    /** @param array<string, mixed> $context */
    private function log(Workspace $workspace, string $action, array $context): void
    {
        $actor = $this->getUser();
        $this->logger->info($action, $context + [
            'workspace' => $workspace->getId(),
            'actor' => $actor instanceof User ? $actor->getId() : null,
        ]);
    }
}
