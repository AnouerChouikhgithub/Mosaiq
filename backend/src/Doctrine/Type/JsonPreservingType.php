<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

/**
 * JSON storage that keeps `{}` and `[]` apart after hydration.
 *
 * The built-in JSON type decodes a document with associative arrays, so a nested
 * empty object was hydrated as `[]` and could never be serialized back as `{}`:
 * the value written to the database was correct, the value read back was not.
 * This type registers under the existing `json` name (see `config/packages/doctrine.yaml`),
 * so the column type, the SQL declaration and the API contract are unchanged.
 *
 * Objects are still decoded to associative arrays so services keep reading the
 * document the way they always have. Only the empty object stays a `stdClass`
 * marker — exactly what `JsonPayloadTrait` produces when it decodes a request
 * body — because `[]` cannot represent "empty object" without ambiguity. Real
 * JSON arrays, including empty ones, stay PHP arrays.
 */
final class JsonPreservingType extends JsonType
{
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
    {
        return $this->normalize(parent::convertToPHPValue($value, $platform));
    }

    /**
     * Objects have to be decoded as objects first: associative decoding turns
     * `{}` into `[]` before any normalisation could see the difference.
     */
    protected function isAssociative(): bool
    {
        return false;
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $attributes = get_object_vars($value);

            return [] === $attributes ? $value : array_map($this->normalize(...), $attributes);
        }

        if (is_array($value)) {
            return array_map($this->normalize(...), $value);
        }

        return $value;
    }
}
