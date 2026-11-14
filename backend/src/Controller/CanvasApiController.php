<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Board;
use App\Entity\CanvasElement;
use App\Entity\User;
use App\Repository\BoardRepository;
use App\Repository\CanvasElementRepository;
use App\Service\BoardService;
use App\Service\CanvasSerializer;
use App\Service\TimelineService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[Route('/api', name: 'api_canvas_')]
final class CanvasApiController extends AbstractController
{
    use JsonPayloadTrait;

    public function __construct(
        private readonly BoardRepository $boards,
        private readonly CanvasElementRepository $elements,
        private readonly BoardService $boardService,
        private readonly CanvasSerializer $serializer,
        private readonly EntityManagerInterface $entityManager,
        private readonly TimelineService $timeline,
    ) {
    }

    #[Route('/boards/{boardId}/elements', name: 'index', methods: ['GET'])]
    public function index(string $boardId): JsonResponse
    {
        $board = $this->boards->find($boardId);
        if (!$board instanceof Board) return $this->json(['error' => 'Board not found.'], 404);
        try { $this->denyAccessUnlessGranted('BOARD_VIEW', $board); }
        catch (AccessDeniedException) {
            if (!$board->hasPublicAccess()) return $this->json(['error' => 'Forbidden.'], 403);
        }
        $public = $board->hasPublicAccess() && !$this->isGranted('BOARD_VIEW', $board);
        return $this->json(['elements' => array_map(fn (CanvasElement $element): array => $public ? $this->serializer->publicElement($element) : $this->serializer->element($element), $this->elements->findByBoard($board))]);
    }

    #[Route('/boards/{boardId}/elements', name: 'create', methods: ['POST'])]
    public function create(string $boardId, Request $request): JsonResponse
    {
        $board = $this->boards->find($boardId);
        if (!$board instanceof Board) return $this->json(['error' => 'Board not found.'], 404);
        try {
            $this->denyAccessUnlessGranted('BOARD_EDIT', $board);
            $actor = $this->actor();
            $element = $this->boardService->addElement($board, $actor, $this->decodePayload($request));
            $this->timeline->record($board, $actor, sprintf('%s added a %s', $actor->getName(), $element->getType()), $element->getType());
        } catch (\JsonException) { return $this->json(['error' => 'Malformed JSON payload.'], 400); }
        catch (\InvalidArgumentException $exception) { return $this->json(['error' => $exception->getMessage()], 422); }
        catch (AccessDeniedException) { return $this->json(['error' => 'Forbidden.'], 403); }
        return $this->json($this->serializer->element($element), 201);
    }

    #[Route('/elements/{id}', name: 'update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $element = $this->elements->find($id);
        if (!$element instanceof CanvasElement) return $this->json(['error' => 'Canvas element not found.'], 404);
        try {
            $board = $element->getBoard();
            $this->denyAccessUnlessGranted('BOARD_EDIT', $board);
            $actor = $this->actor();
            $payload = $this->decodePayload($request);
            if (array_intersect(array_keys($payload), ['x', 'y', 'width', 'height', 'rotation']) !== []) {
                $element->updateGeometry(
                    array_key_exists('x', $payload) ? (float) $payload['x'] : $element->getX(),
                    array_key_exists('y', $payload) ? (float) $payload['y'] : $element->getY(),
                    array_key_exists('width', $payload) ? (float) $payload['width'] : $element->getWidth(),
                    array_key_exists('height', $payload) ? (float) $payload['height'] : $element->getHeight(),
                    array_key_exists('rotation', $payload) ? (float) $payload['rotation'] : $element->getRotation(),
                );
            }
            if (array_key_exists('content', $payload)) $element->updateContent(null === $payload['content'] ? null : (string) $payload['content']);
            if (array_key_exists('color', $payload) || array_key_exists('metadata', $payload)) {
                $metadata = array_key_exists('metadata', $payload) ? $payload['metadata'] : $element->getMetadata();
                if (null !== $metadata && !is_array($metadata)) throw new \InvalidArgumentException('Element metadata must be an object.');
                $element->updateStyle(array_key_exists('color', $payload) && null !== $payload['color'] ? (string) $payload['color'] : $element->getColor(), $metadata);
            }
            $this->entityManager->flush();
            $this->timeline->record($board, $actor, sprintf('%s updated a %s', $actor->getName(), $element->getType()), $element->getType());
        } catch (\JsonException) { return $this->json(['error' => 'Malformed JSON payload.'], 400); }
        catch (\InvalidArgumentException $exception) { return $this->json(['error' => $exception->getMessage()], 422); }
        catch (AccessDeniedException) { return $this->json(['error' => 'Forbidden.'], 403); }
        return $this->json($this->serializer->element($element));
    }

    #[Route('/elements/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $element = $this->elements->find($id);
        if (!$element instanceof CanvasElement) return $this->json(['error' => 'Canvas element not found.'], 404);
        $board = $element->getBoard();
        try { $this->denyAccessUnlessGranted('BOARD_EDIT', $board); }
        catch (AccessDeniedException) { return $this->json(['error' => 'Forbidden.'], 403); }
        $actor = $this->actor();
        $type = $element->getType();
        $snapshot = array_map(
            fn (CanvasElement $candidate): array => $this->serializer->element($candidate),
            array_values(array_filter($this->elements->findByBoard($board), static fn (CanvasElement $candidate): bool => $candidate->getId() !== $element->getId())),
        );
        $this->entityManager->remove($element);
        $board->touch();
        $this->entityManager->flush();
        $this->timeline->record($board, $actor, sprintf('%s deleted a %s', $actor->getName(), $type), $type, null, $snapshot);
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function actor(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) throw new AccessDeniedException('Authentication required.');
        return $user;
    }
}
