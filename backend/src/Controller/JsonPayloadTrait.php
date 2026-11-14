<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;

trait JsonPayloadTrait
{
    /**
     * @return array<string, mixed>
     */
    private function decodePayload(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Request body must be a JSON object.');
        }

        return $decoded;
    }
}
