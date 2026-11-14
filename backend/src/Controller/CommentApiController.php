<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Board;
use App\Entity\CommentThread;
use App\Entity\User;
use App\Repository\BoardRepository;
use App\Repository\CommentThreadRepository;
use App\Repository\UserRepository;
use App\Service\CanvasSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[Route('/api', name: 'api_comments_')]
final class CommentApiController extends AbstractController
{
    use JsonPayloadTrait;

    public function __construct(
        private readonly BoardRepository $boards,
        private readonly CommentThreadRepository $threads,
        private readonly UserRepository $users,
        private readonly CanvasSerializer $serializer,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/boards/{id}/comments', name: 'index', methods: ['GET'])]
    public function index(string $id): JsonResponse
    {
        $board = $this->boards->find($id);
        if (!$board instanceof Board) return $this->json(['error' => 'Board not found.'], 404);
        try { $this->denyAccessUnlessGranted('BOARD_VIEW', $board); }
        catch (AccessDeniedException) { return $this->json(['error' => 'Forbidden.'], 403); }
        return $this->json(['threads' => array_map(fn (CommentThread $thread): array => $this->serializer->thread($thread), $this->threads->findByBoard($board))]);
    }

    #[Route('/boards/{id}/comments', name: 'create', methods: ['POST'])]
    public function create(string $id, Request $request): JsonResponse
    {
        $board = $this->boards->find($id);
        if (!$board instanceof Board) return $this->json(['error' => 'Board not found.'], 404);
        try {
            $this->denyAccessUnlessGranted('BOARD_COMMENT', $board);
            $actor = $this->getUser();
            if (!$actor instanceof User) return $this->json(['error' => 'Authentication required.'], 401);
            $payload = $this->decodePayload($request);
            if (!is_numeric($payload['x'] ?? null) || !is_numeric($payload['y'] ?? null)) throw new \InvalidArgumentException('Comment coordinates must be numeric.');
            $thread = new CommentThread($board, $actor, (float) $payload['x'], (float) $payload['y']);
            $thread->addMessage($actor, (string) ($payload['content'] ?? ''));
            $this->entityManager->persist($thread);
            $this->entityManager->flush();
        } catch (\JsonException) { return $this->json(['error' => 'Malformed JSON payload.'], 400); }
        catch (\InvalidArgumentException $exception) { return $this->json(['error' => $exception->getMessage()], 422); }
        catch (AccessDeniedException) { return $this->json(['error' => 'Forbidden.'], 403); }
        return $this->json($this->serializer->thread($thread), 201);
    }

    #[Route('/comments/threads/{id}/messages', name: 'reply', methods: ['POST'])]
    public function reply(string $id, Request $request): JsonResponse
    {
        $thread = $this->threads->find($id);
        if (!$thread instanceof CommentThread) return $this->json(['error' => 'Comment thread not found.'], 404);
        try {
            $this->denyAccessUnlessGranted('BOARD_COMMENT', $thread->getBoard());
            $actor = $this->getUser();
            if (!$actor instanceof User) return $this->json(['error' => 'Authentication required.'], 401);
            $payload = $this->decodePayload($request);
            $message = $thread->addMessage($actor, (string) ($payload['content'] ?? ''));
            $this->entityManager->flush();
        } catch (\JsonException) { return $this->json(['error' => 'Malformed JSON payload.'], 400); }
        catch (\InvalidArgumentException $exception) { return $this->json(['error' => $exception->getMessage()], 422); }
        catch (AccessDeniedException) { return $this->json(['error' => 'Forbidden.'], 403); }
        return $this->json($this->serializer->message($message), 201);
    }

    #[Route('/comments/threads/{id}/resolve', name: 'resolve', methods: ['PATCH'])]
    public function resolve(string $id, Request $request): JsonResponse
    {
        $thread = $this->threads->find($id);
        if (!$thread instanceof CommentThread) return $this->json(['error' => 'Comment thread not found.'], 404);
        try {
            $this->denyAccessUnlessGranted('BOARD_COMMENT', $thread->getBoard());
            $payload = $this->decodePayload($request);
            if (!is_bool($payload['resolved'] ?? null)) throw new \InvalidArgumentException('resolved must be boolean.');
            if ($payload['resolved']) $thread->resolve(); else $thread->reopen();
            $this->entityManager->flush();
        } catch (\JsonException) { return $this->json(['error' => 'Malformed JSON payload.'], 400); }
        catch (\InvalidArgumentException $exception) { return $this->json(['error' => $exception->getMessage()], 422); }
        catch (AccessDeniedException) { return $this->json(['error' => 'Forbidden.'], 403); }
        return $this->json($this->serializer->thread($thread));
    }
}
