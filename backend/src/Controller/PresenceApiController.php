<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Board;
use App\Entity\BoardPresence;
use App\Entity\User;
use App\Repository\BoardPresenceRepository;
use App\Repository\BoardRepository;
use App\Service\CanvasSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[Route('/api/boards/{id}', name: 'api_presence_')]
final class PresenceApiController extends AbstractController
{
    use JsonPayloadTrait;

    public function __construct(
        private readonly BoardRepository $boards,
        private readonly BoardPresenceRepository $presence,
        private readonly EntityManagerInterface $entityManager,
        private readonly CanvasSerializer $serializer,
    ) {
    }

    #[Route('/presence', name: 'index', methods: ['GET'])]
    public function index(string $id): JsonResponse
    {
        $board = $this->boards->find($id);
        if (!$board instanceof Board) {
            return $this->json(['error' => 'Board not found.'], 404);
        }
        try {
            $this->denyAccessUnlessGranted('BOARD_VIEW', $board);
        } catch (AccessDeniedException) {
            return $this->json(['error' => 'Forbidden.'], 403);
        }

        $active = $this->presence->findRecentlyActive($board, new \DateTimeImmutable('-15 seconds'));

        return $this->json(['presence' => array_map(fn (BoardPresence $presence): array => [
            'user' => $this->serializer->user($presence->getUser()),
            'x' => $presence->getX(),
            'y' => $presence->getY(),
            'tool' => $presence->getTool(),
            'lastActive' => $presence->getUpdatedAt()->format(DATE_ATOM),
        ], $active)]);
    }

    #[Route('/presence', name: 'update', methods: ['POST'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $board = $this->boards->find($id);
        if (!$board instanceof Board) {
            return $this->json(['error' => 'Board not found.'], 404);
        }
        try {
            $this->denyAccessUnlessGranted('BOARD_VIEW', $board);
            $user = $this->getUser();
            if (!$user instanceof User) {
                return $this->json(['error' => 'Authentication required.'], 401);
            }
            $payload = $this->decodePayload($request);
            if (!is_numeric($payload['x'] ?? null) || !is_numeric($payload['y'] ?? null)) {
                throw new \InvalidArgumentException('Cursor x and y must be numeric.');
            }
            $tool = (string) ($payload['tool'] ?? 'select');
            $presence = $this->presence->findForBoardAndUser($board, $user);
            if (!$presence instanceof BoardPresence) {
                $presence = new BoardPresence($board, $user, (float) $payload['x'], (float) $payload['y'], $tool);
                $this->entityManager->persist($presence);
            } else {
                $presence->update((float) $payload['x'], (float) $payload['y'], $tool);
            }
            $this->entityManager->flush();
        } catch (\JsonException) {
            return $this->json(['error' => 'Malformed JSON payload.'], 400);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        } catch (AccessDeniedException) {
            return $this->json(['error' => 'Forbidden.'], 403);
        }

        return $this->json(['updatedAt' => $presence->getUpdatedAt()->format(DATE_ATOM)]);
    }

    /**
     * Server-Sent Events stream used by the editor connection badge.
     * Each request emits one 500 ms frame and closes so the single-process PHP dev server
     * stays responsive; the browser reconnects to keep the 500 ms update cadence.
     */
    #[Route('/stream', name: 'stream', methods: ['GET'])]
    public function streamEvents(string $id, Request $request): Response
    {
        $board = $this->boards->find($id);
        if (!$board instanceof Board) {
            return $this->json(['error' => 'Board not found.'], 404);
        }
        try {
            $this->denyAccessUnlessGranted('BOARD_VIEW', $board);
        } catch (AccessDeniedException) {
            return $this->json(['error' => 'Forbidden.'], 403);
        }

        $ticks = max(1, min(20, $request->query->getInt('ticks', 1)));

        $response = new StreamedResponse(function () use ($id, $ticks): void {
            @set_time_limit(0);
            echo "retry: 1000\n\n";
            echo "event: ready\ndata: ".json_encode(['boardId' => $id], JSON_THROW_ON_ERROR)."\n\n";
            $this->flushStream();

            for ($tick = 0; $tick < $ticks && !connection_aborted(); ++$tick) {
                if ($tick > 0) {
                    usleep(500_000);
                }

                // Detach cached entities so each tick sees other users' committed presence and board changes.
                $this->entityManager->clear();
                $board = $this->boards->find($id);
                if (!$board instanceof Board) {
                    break;
                }

                $active = $this->presence->findRecentlyActive($board, new \DateTimeImmutable('-15 seconds'));
                $presence = array_map(fn (BoardPresence $entry): array => [
                    'user' => $this->serializer->user($entry->getUser()),
                    'x' => $entry->getX(),
                    'y' => $entry->getY(),
                    'tool' => $entry->getTool(),
                    'lastActive' => $entry->getUpdatedAt()->format(DATE_ATOM),
                ], $active);

                echo "event: presence\ndata: ".json_encode(['presence' => $presence], JSON_THROW_ON_ERROR)."\n\n";
                echo "event: canvas\ndata: ".json_encode([
                    'revision' => $board->getUpdatedAt()->format('U.u').':'.$board->getElements()->count(),
                    'elementCount' => $board->getElements()->count(),
                    'updatedAt' => $board->getUpdatedAt()->format(DATE_ATOM),
                ], JSON_THROW_ON_ERROR)."\n\n";
                $this->flushStream();
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream; charset=UTF-8');
        $response->headers->set('Cache-Control', 'no-cache, no-store');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    private function flushStream(): void
    {
        flush();
    }
}
