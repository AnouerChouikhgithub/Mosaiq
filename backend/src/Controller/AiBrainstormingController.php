<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\AiGenerationRateLimiter;
use App\Ai\Brainstorming\BrainstormingGenerator;
use App\Ai\Brainstorming\BrainstormingLimits;
use App\Ai\Brainstorming\BrainstormingRequest;
use App\Ai\Exception\AiGenerationException;
use App\Ai\Exception\AiRequestValidationException;
use App\Ai\Exception\AiWorkspaceInactiveException;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /api/ai/brainstorming/generate` (contract §6).
 *
 * The endpoint only generates and validates a proposal. It never creates a
 * board, an element or a timeline entry, and it has no board or workspace
 * identifier in its request, so it cannot be pointed at existing content.
 */
#[Route('/api/ai/brainstorming', name: 'api_ai_brainstorming_')]
final class AiBrainstormingController extends AbstractController
{
    use JsonPayloadTrait;

    public function __construct(
        private readonly BrainstormingGenerator $generator,
        private readonly AiGenerationRateLimiter $rateLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/generate', name: 'generate', methods: ['POST'])]
    public function generate(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            if (!$user->hasActiveWorkspaceMembership()) {
                throw new AiWorkspaceInactiveException();
            }
            // The size guard runs before decoding so an oversized body is never
            // parsed, and before any provider call so it cannot cost tokens.
            if (strlen($request->getContent()) > BrainstormingLimits::MAX_REQUEST_BYTES) {
                throw new AiRequestValidationException([['field' => 'body', 'message' => sprintf('must be at most %d bytes.', BrainstormingLimits::MAX_REQUEST_BYTES)]]);
            }
            $payload = $this->decodePayload($request);
            $input = BrainstormingRequest::fromPayload($payload);
            // The quota is spent here, after validation and before the provider,
            // so an invalid request never consumes a slot.
            $this->rateLimiter->consume($user);
            $proposal = $this->generator->generate($input, $user->getId());
        } catch (\JsonException|\InvalidArgumentException) {
            // decodePayload() rejects both malformed JSON and a non-object body.
            return $this->json(['error' => 'Malformed JSON payload.', 'code' => 'ai.malformed_json'], Response::HTTP_BAD_REQUEST);
        } catch (AiGenerationException $exception) {
            return $this->errorResponse($exception);
        } catch (\Throwable $exception) {
            // Never expose internals: the log keeps the exception, the client
            // gets a stable code.
            $this->logger->error('ai.brainstorming.internal_error', ['exception' => $exception]);

            return $this->json(['error' => 'The AI generation request failed.', 'code' => 'ai.internal_error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $response = $this->json([
            'schemaVersion' => BrainstormingLimits::SCHEMA_VERSION,
            'language' => $input->language,
            'detailLevel' => $input->detailLevel,
            'truncated' => $proposal->truncated,
            'proposal' => $proposal->toPayload(),
        ]);
        // The proposal is derived from private user input.
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function errorResponse(AiGenerationException $exception): JsonResponse
    {
        $body = [
            'error' => $exception->getMessage(),
            'code' => $exception->getErrorCode(),
        ];
        if ([] !== $exception->getDetails()) {
            $body['details'] = $exception->getDetails();
        }
        if (null !== $exception->getRetryAfter()) {
            $body['retryAfter'] = $exception->getRetryAfter();
        }

        $response = $this->json($body, $exception->getStatusCode());
        if (null !== $exception->getRetryAfter()) {
            $response->headers->set('Retry-After', (string) $exception->getRetryAfter());
        }

        return $response;
    }
}
