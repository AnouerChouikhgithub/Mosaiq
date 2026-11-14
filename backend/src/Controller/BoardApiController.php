<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Board;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\BoardRepository;
use App\Repository\BoardInvitationRepository;
use App\Entity\BoardInvitation;
use App\Repository\UserRepository;
use App\Repository\WorkspaceRepository;
use App\Service\BoardSerializer;
use App\Service\BoardService;
use App\Service\BoardInvitationMailer;
use App\Service\TimelineService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[Route('/api/boards', name: 'api_boards_')]
final class BoardApiController extends AbstractController
{
    use JsonPayloadTrait;

    public function __construct(
        private readonly BoardRepository $boards,
        private readonly WorkspaceRepository $workspaces,
        private readonly UserRepository $users,
        private readonly BoardInvitationRepository $invitations,
        private readonly BoardService $boardService,
        private readonly BoardInvitationMailer $invitationMailer,
        private readonly BoardSerializer $serializer,
        private readonly TimelineService $timeline,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $workspaceId = $request->query->get('workspaceId');
        if (null !== $workspaceId && '' !== $workspaceId) {
            $workspace = $this->workspaces->find($workspaceId);
            if (!$workspace instanceof Workspace) return $this->json(['error' => 'Workspace not found.'], 404);
            if ($workspace->getId() !== $user->getWorkspace()->getId()) return $this->json(['error' => 'Forbidden.'], 403);
            try { $this->denyAccessUnlessGranted('WORKSPACE_VIEW', $workspace); }
            catch (AccessDeniedException) { return $this->json(['error' => 'Forbidden.'], 403); }
            $boards = $this->boards->findAccessibleInWorkspace($user, $workspace);
        } else {
            $boards = $this->boards->findAccessibleTo($user);
        }

        return $this->json(['boards' => array_map(fn (Board $board): array => $this->serializer->summary($board), $boards)]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        try {
            $payload = $this->decodePayload($request);
            $workspace = $this->workspaces->find((string) ($payload['workspaceId'] ?? $user->getWorkspace()->getId()));
            if (!$workspace instanceof Workspace) return $this->json(['error' => 'Workspace not found.'], 404);
            $this->denyAccessUnlessGranted('WORKSPACE_VIEW', $workspace);
            $colors = $payload['noteColors'] ?? ['amber', 'sky', 'mint'];
            if (!is_array($colors) || array_filter($colors, static fn ($color): bool => !is_string($color)) !== []) {
                throw new \InvalidArgumentException('noteColors must be an array of color names.');
            }
            if (!$this->isGranted('WORKSPACE_ADMINISTER', $workspace)) throw new AccessDeniedException('Only workspace administrators can create boards.');
            $board = $this->boardService->createBoard($workspace, (string) ($payload['title'] ?? ''), array_values($colors), $user);
            $board->addMember($user, 'Owner');
            $this->timeline->record($board, $user, sprintf('%s created the board', $user->getName()), 'board');
        } catch (\JsonException) {
            return $this->json(['error' => 'Malformed JSON payload.'], 400);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        } catch (AccessDeniedException) {
            return $this->json(['error' => 'Forbidden.'], 403);
        }

        return $this->json($this->serializer->detail($board), 201);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $board = $this->boards->find($id);
        if (!$board instanceof Board) return $this->json(['error' => 'Board not found.'], 404);
        try { $this->denyAccessUnlessGranted('BOARD_VIEW', $board); }
        catch (AccessDeniedException) {
            if (!$board->hasPublicAccess()) return $this->json(['error' => 'Forbidden.'], 403);
            return $this->json($this->serializer->publicDetail($board));
        }
        return $this->json($this->serializer->detail($board));
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $board = $this->boards->find($id);
        if (!$board instanceof Board) return $this->json(['error' => 'Board not found.'], 404);
        try {
            $this->denyAccessUnlessGranted('BOARD_EDIT', $board);
            $user = $this->requireUser();
            $payload = $this->decodePayload($request);
            if (array_key_exists('title', $payload)) {
                $this->boardService->renameBoard($board, (string) $payload['title']);
                $this->timeline->record($board, $user, sprintf('%s renamed the board', $user->getName()), 'rename');
            }
            if (array_key_exists('starred', $payload)) $this->boardService->setStarred($board, (bool) $payload['starred']);
        } catch (\JsonException) {
            return $this->json(['error' => 'Malformed JSON payload.'], 400);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        } catch (AccessDeniedException) {
            return $this->json(['error' => 'Forbidden.'], 403);
        }
        return $this->json($this->serializer->detail($board));
    }

    #[Route('/{id}/star', name: 'toggle_star', methods: ['POST'])]
    public function toggleStar(string $id): JsonResponse
    {
        $board = $this->boards->find($id);
        if (!$board instanceof Board) return $this->json(['error' => 'Board not found.'], 404);
        try { $this->denyAccessUnlessGranted('BOARD_VIEW', $board); }
        catch (AccessDeniedException) { return $this->json(['error' => 'Forbidden.'], 403); }
        $this->boardService->setStarred($board, !$board->isStarred());
        return $this->json($this->serializer->detail($board));
    }

    #[Route('/{id}/timeline', name: 'timeline', methods: ['GET'])]
    public function timeline(string $id): JsonResponse
    {
        $board = $this->boards->find($id);
        if (!$board instanceof Board) return $this->json(['error' => 'Board not found.'], 404);
        try { $this->denyAccessUnlessGranted('BOARD_VIEW', $board); }
        catch (AccessDeniedException) {
            if (!$board->hasPublicAccess()) return $this->json(['error' => 'Forbidden.'], 403);
            return $this->json(['timeline' => []]);
        }
        return $this->json(['timeline' => array_map(static fn ($entry): array => [
            'id' => $entry->getId(),
            'at' => $entry->getAt()->format(DATE_ATOM),
            'label' => $entry->getLabel(),
            'kind' => $entry->getKind(),
            'reveals' => $entry->getReveals(),
            'snapshot' => $entry->getSnapshot(),
            'author' => ['id' => $entry->getAuthor()->getId(), 'name' => $entry->getAuthor()->getName()],
        ], $this->timeline->forBoard($board))]);
    }

    #[Route('/{id}/restore/{timelineEntryId}', name: 'restore', methods: ['POST'])]
    public function restore(string $id, string $timelineEntryId): JsonResponse
    {
        $board = $this->boards->find($id);
        if (!$board instanceof Board) return $this->json(['error' => 'Board not found.'], 404);
        try {
            $this->denyAccessUnlessGranted('BOARD_EDIT', $board);
            $entry = null;
            foreach ($this->timeline->forBoard($board) as $candidate) if ($candidate->getId() === $timelineEntryId) $entry = $candidate;
            if (null === $entry) return $this->json(['error' => 'Timeline entry not found.'], 404);
            $user = $this->requireUser();
            $this->timeline->restore($board, $entry, $user);
        } catch (AccessDeniedException) {
            return $this->json(['error' => 'Forbidden.'], 403);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }
        return $this->json($this->serializer->detail($board));
    }

    #[Route('/{id}/members', name: 'members_add', methods: ['POST'])]
    public function addMember(string $id, Request $request): JsonResponse
    {
        $board = $this->boards->find($id);
        if (!$board instanceof Board) return $this->json(['error' => 'Board not found.'], 404);
        try {
            $this->denyAccessUnlessGranted('BOARD_SHARE', $board);
            $payload = $this->decodePayload($request);
            $user = $this->users->findByEmail((string) ($payload['email'] ?? ''));
            $role = (string) ($payload['role'] ?? 'Viewer');
            if (!$user instanceof User) return $this->json(['error' => 'User not found.'], 404);
            if (!in_array($role, ['Viewer', 'Commenter', 'Editor'], true)) throw new \InvalidArgumentException('Share role is not supported.');
            if ($user->getWorkspace()->getId() !== $board->getWorkspace()->getId()) return $this->json(['error' => 'User must belong to the board workspace.'], 422);
            $board->addMember($user, $role);
            $this->entityManager->flush();
            $this->invitationMailer->send($user->getEmail(), $board->getTitle(), $this->requireUser()->getName(), $role);
        } catch (AccessDeniedException) { return $this->json(['error' => 'Forbidden.'], 403); }
        catch (\JsonException|\InvalidArgumentException $exception) { return $this->json(['error' => $exception->getMessage()], 422); }
        return $this->json($this->serializer->summary($board), 200);
    }

    #[Route('/{id}/invitations', name: 'invitations_create', methods: ['POST'])]
    public function createInvitation(string $id, Request $request): JsonResponse
    {
        $board = $this->boards->find($id);
        if (!$board instanceof Board) return $this->json(['error' => 'Board not found.'], 404);
        try {
            $this->denyAccessUnlessGranted('BOARD_SHARE', $board);
            $actor = $this->requireUser();
            $payload = $this->decodePayload($request);
            $email = strtolower(trim((string) ($payload['email'] ?? '')));
            $role = (string) ($payload['role'] ?? 'Viewer');
            if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Invitation email is invalid.');
            if (!in_array($role, ['Viewer', 'Commenter', 'Editor'], true)) throw new \InvalidArgumentException('Share role is not supported.');
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $invitation = new BoardInvitation($board, $actor, $email, $role, hash('sha256', $token));
            $this->entityManager->persist($invitation);
            $this->entityManager->flush();
            $emailSent = $this->invitationMailer->send($email, $board->getTitle(), $actor->getName(), $role, $token);
            return $this->json(['token' => $token, 'email' => $email, 'role' => $role, 'expiresAt' => $invitation->getExpiresAt()->format(DATE_ATOM), 'emailSent' => $emailSent], 201);
        } catch (AccessDeniedException) { return $this->json(['error' => 'Forbidden.'], 403); }
        catch (\JsonException|\InvalidArgumentException $exception) { return $this->json(['error' => $exception->getMessage()], 422); }
    }

    #[Route('/{id}/members/{userId}', name: 'members_update', methods: ['PATCH'])]
    public function updateMember(string $id, string $userId, Request $request): JsonResponse
    {
        $board = $this->boards->find($id);
        $member = $this->users->find($userId);
        if (!$board instanceof Board || !$member instanceof User) return $this->json(['error' => 'Board or user not found.'], 404);
        try {
            $this->denyAccessUnlessGranted('BOARD_SHARE', $board);
            $payload = $this->decodePayload($request);
            $role = (string) ($payload['role'] ?? '');
            if (!in_array($role, ['Viewer', 'Commenter', 'Editor'], true)) throw new \InvalidArgumentException('Share role is not supported.');
            if ($member->getWorkspace()->getId() !== $board->getWorkspace()->getId() || !$board->isMember($member)) return $this->json(['error' => 'Board member not found.'], 404);
            if ('Owner' === $board->roleFor($member)) return $this->json(['error' => 'Cannot change the board owner role.'], 422);
            $board->addMember($member, $role);
            $this->entityManager->flush();
        } catch (AccessDeniedException) { return $this->json(['error' => 'Forbidden.'], 403); }
        catch (\JsonException|\InvalidArgumentException $exception) { return $this->json(['error' => $exception->getMessage()], 422); }
        return $this->json($this->serializer->summary($board));
    }

    #[Route('/{id}/members/{userId}', name: 'members_remove', methods: ['DELETE'])]
    public function removeMember(string $id, string $userId): JsonResponse
    {
        $board = $this->boards->find($id);
        $member = $this->users->find($userId);
        if (!$board instanceof Board || !$member instanceof User) return $this->json(['error' => 'Board or user not found.'], 404);
        try { $this->denyAccessUnlessGranted('BOARD_SHARE', $board); }
        catch (AccessDeniedException) { return $this->json(['error' => 'Forbidden.'], 403); }
        if ('Owner' === $board->roleFor($member)) return $this->json(['error' => 'Cannot remove the board owner.'], 422);
        $board->removeMember($member);
        $this->entityManager->flush();
        return new JsonResponse(null, 204);
    }

    #[Route('/invitations/{token}/accept', name: 'invitations_accept', methods: ['POST'])]
    public function acceptInvitation(string $token): JsonResponse
    {
        $actor = $this->requireUser();
        $invitation = $this->invitations->findByToken($token);
        if (!$invitation instanceof BoardInvitation || !$invitation->isPendingAt()) return $this->json(['error' => 'Invitation is invalid, expired or already accepted.'], 404);
        $board = $invitation->getBoard();
        if (!$invitation->matchesEmail($actor->getEmail())) return $this->json(['error' => 'Invitation belongs to another email address.'], 403);
        if ($actor->getWorkspace()->getId() !== $board->getWorkspace()->getId()) return $this->json(['error' => 'Account must belong to the board workspace.'], 403);
        try {
            $this->entityManager->wrapInTransaction(function () use ($invitation, $board, $actor): void {
                $invitation->accept();
                $board->addMember($actor, $invitation->getRole());
                $this->entityManager->flush();
            });
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Invitation is invalid, expired or already accepted.'], 404);
        }
        return $this->json($this->serializer->detail($board));
    }

    #[Route('/{id}/access', name: 'access', methods: ['PATCH'])]
    public function updateAccess(string $id, Request $request): JsonResponse
    {
        $board = $this->boards->find($id);
        if (!$board instanceof Board) return $this->json(['error' => 'Board not found.'], 404);
        try {
            $this->denyAccessUnlessGranted('BOARD_SHARE', $board);
            $payload = $this->decodePayload($request);
            if (!is_bool($payload['publicAccess'] ?? null)) throw new \InvalidArgumentException('publicAccess must be boolean.');
            $board->setPublicAccess($payload['publicAccess']);
            $this->entityManager->flush();
        } catch (AccessDeniedException) { return $this->json(['error' => 'Forbidden.'], 403); }
        catch (\JsonException|\InvalidArgumentException $exception) { return $this->json(['error' => $exception->getMessage()], 422); }
        return $this->json($this->serializer->summary($board));
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) throw new AccessDeniedException('Authentication required.');
        return $user;
    }
}
