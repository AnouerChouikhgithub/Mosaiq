<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Board;
use App\Entity\CanvasElement;
use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class BoardService
{
    /** Canonical UUID used as the idempotency key of one canvas create. */
    public const MUTATION_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param list<string> $noteColors
     */
    public function createBoard(Workspace $workspace, string $title, array $noteColors, ?User $owner = null): Board
    {
        $board = new Board($workspace, $title, $noteColors);
        if (null !== $owner) {
            $board->addMember($owner);
        }

        $this->entityManager->persist($board);
        $this->entityManager->flush();

        return $board;
    }

    /**
     * Validates the canvas payload separately from persistence so a rejected
     * request fails before the controller opens its transaction.
     *
     * @param array<string, mixed> $input
     */
    public function validateElementInput(array $input): void
    {
        foreach (['type', 'x', 'y', 'width', 'height'] as $required) {
            if (!array_key_exists($required, $input)) {
                throw new \InvalidArgumentException(sprintf('Missing "%s" for the canvas element.', $required));
            }
        }

        if (!is_string($input['type'])) {
            throw new \InvalidArgumentException('The canvas element type must be a string.');
        }

        foreach (['x', 'y', 'width', 'height', 'rotation'] as $numeric) {
            if (!array_key_exists($numeric, $input)) {
                continue;
            }
            if (!is_numeric($input[$numeric]) || !is_finite((float) $input[$numeric])) {
                throw new \InvalidArgumentException(sprintf('"%s" must be a finite number.', $numeric));
            }
        }

        $this->normalizeMetadata($input['metadata'] ?? null);
    }

    /**
     * Accepts a JSON object for `metadata`, including the empty object `{}`.
     * A JSON array — empty or not — is not an object and is rejected: the
     * decoder keeps `{}` as a `stdClass` marker precisely so the two can be
     * told apart. Nested empty objects are preserved as markers for the same
     * reason and serialize back to `{}`.
     *
     * @return array<string, mixed>|null
     */
    public function normalizeMetadata(mixed $metadata): ?array
    {
        if (null === $metadata) {
            return null;
        }

        if ($metadata instanceof \stdClass) {
            return [];
        }

        if (!is_array($metadata) || array_is_list($metadata)) {
            throw new \InvalidArgumentException('Element metadata must be an object.');
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function addElement(Board $board, User $author, array $input, ?string $mutationId = null, ?string $fingerprint = null): CanvasElement
    {
        $this->validateElementInput($input);

        $element = new CanvasElement(
            $board,
            $author,
            $input['type'],
            (float) $input['x'],
            (float) $input['y'],
            (float) $input['width'],
            (float) $input['height'],
            (float) ($input['rotation'] ?? 0.0),
            isset($input['content']) ? (string) $input['content'] : null,
            isset($input['color']) ? (string) $input['color'] : null,
            $this->normalizeMetadata($input['metadata'] ?? null),
        );

        if (null !== $mutationId) {
            // The fingerprint records which payload this key created, so a later
            // retry can be told apart from a reused key with other content.
            $element->assignMutation($mutationId, $fingerprint ?? $this->elementFingerprint($input));
        }

        $this->entityManager->persist($element);
        $board->touch();
        $this->entityManager->flush();

        return $element;
    }

    /**
     * Reads the optional client idempotency key. It is optional so existing
     * clients keep working, and every retry of one operation must reuse it.
     *
     * @param array<string, mixed> $input
     */
    public function mutationId(array $input): ?string
    {
        $value = $input['mutationId'] ?? null;
        if (null === $value) {
            return null;
        }

        if (!is_string($value) || 36 !== strlen($value) || 1 !== preg_match(self::MUTATION_ID_PATTERN, $value)) {
            throw new \InvalidArgumentException('"mutationId" must be a canonical UUID.');
        }

        return strtolower($value);
    }

    /**
     * Fingerprints the element a create payload would persist, ignoring the
     * mutation key and member order. Two retries of one operation therefore
     * match while a different note under the same key does not.
     *
     * @param array<string, mixed> $input
     */
    public function elementFingerprint(array $input): string
    {
        $canonical = [
            'type' => (string) $input['type'],
            'x' => (float) $input['x'],
            'y' => (float) $input['y'],
            'width' => (float) $input['width'],
            'height' => (float) $input['height'],
            'rotation' => (float) ($input['rotation'] ?? 0.0),
            'content' => isset($input['content']) ? trim((string) $input['content']) : null,
            'color' => isset($input['color']) ? (string) $input['color'] : null,
            'metadata' => $this->sortKeys($this->normalizeMetadata($input['metadata'] ?? null)),
        ];

        return hash('sha256', json_encode($canonical, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * Serialises concurrent canvas creates for one board: a second request with
     * the same mutation id waits here and then observes the element the first
     * request committed instead of colliding with the unique index.
     */
    public function lockForCanvasMutation(Board $board): void
    {
        $this->entityManager->lock($board, LockMode::PESSIMISTIC_WRITE);
    }

    public function renameBoard(Board $board, string $title): void
    {
        $board->rename($title);
        $this->entityManager->flush();
    }

    public function setStarred(Board $board, bool $starred): void
    {
        $board->setStarred($starred);
        $this->entityManager->flush();
    }

    /**
     * @param array<array-key, mixed>|null $value
     *
     * @return array<array-key, mixed>|null
     */
    private function sortKeys(?array $value): ?array
    {
        if (null === $value) {
            return null;
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortKeys($item);
            }
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
