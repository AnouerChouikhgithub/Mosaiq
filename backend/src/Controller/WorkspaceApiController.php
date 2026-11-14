<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\WorkspaceRepository;
use App\Service\BoardSerializer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[Route('/api/workspaces', name: 'api_workspaces_')]
final class WorkspaceApiController extends AbstractController
{
    public function __construct(private readonly WorkspaceRepository $workspaces, private readonly BoardSerializer $serializer)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) return $this->json(['error' => 'Authentication required.'], 401);
        return $this->json(['workspaces' => [$this->summary($user->getWorkspace())]]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $workspace = $this->workspaces->find($id);
        if (!$workspace instanceof Workspace) return $this->json(['error' => 'Workspace not found.'], 404);
        try { $this->denyAccessUnlessGranted('WORKSPACE_VIEW', $workspace); }
        catch (AccessDeniedException) { return $this->json(['error' => 'Forbidden.'], 403); }
        return $this->json($this->summary($workspace) + ['boards' => array_map(fn ($board): array => $this->serializer->summary($board), $workspace->getBoards()->toArray())]);
    }

    /** @return array<string, mixed> */
    private function summary(Workspace $workspace): array
    {
        return [
            'id' => $workspace->getId(), 'name' => $workspace->getName(), 'plan' => $workspace->getPlan(),
            'pricePerMember' => $workspace->getPricePerMember(), 'currency' => $workspace->getCurrency(),
            'seats' => $workspace->getSeats(), 'renewsOn' => $workspace->getRenewsOn()->format('Y-m-d'),
            'memberCount' => $workspace->getUsers()->count(), 'boardCount' => $workspace->getBoards()->count(),
            'createdAt' => $workspace->getCreatedAt()->format(DATE_ATOM),
        ];
    }
}
