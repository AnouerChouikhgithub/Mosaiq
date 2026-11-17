<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;

trait JsonPayloadTrait
{
    /**
     * Decodes the request body, which must be a JSON object.
     *
     * Associative decoding alone cannot tell an object from an array: `[]` and
     * `["value"]` both decode to PHP arrays. The document is therefore decoded
     * without associative mode first so the top-level value can be checked, then
     * normalised back to the associative structure the controllers expect.
     *
     * @return array<string, mixed>
     */
    private function decodePayload(Request $request): array
    {
        $decoded = json_decode($request->getContent(), false, 512, \JSON_THROW_ON_ERROR);
        if (!$decoded instanceof \stdClass) {
            throw new \InvalidArgumentException('Request body must be a JSON object.');
        }

        /** @var array<string, mixed> $payload */
        $payload = $this->normalizeJsonValue($decoded);

        return $payload;
    }

    private function normalizeJsonValue(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $normalized = [];
            foreach (get_object_vars($value) as $key => $item) {
                $normalized[$key] = $this->normalizeJsonValue($item);
            }

            return $normalized;
        }

        if (is_array($value)) {
            return array_map($this->normalizeJsonValue(...), $value);
        }

        return $value;
    }
}
