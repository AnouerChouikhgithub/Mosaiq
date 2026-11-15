<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Intentionally modest policy: a long passphrase is preferred over character
 * gymnastics. The 72-byte maximum avoids bcrypt's silent truncation.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 72;

    /** @return string|null A user-facing message, or null when the password is acceptable. */
    public function validate(string $password): ?string
    {
        $length = strlen($password);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return sprintf('Password must be between %d and %d characters.', self::MIN_LENGTH, self::MAX_LENGTH);
        }
        if (1 !== preg_match('/\p{L}/u', $password) || 1 !== preg_match('/\d/', $password)) {
            return 'Password must include at least one letter and one digit.';
        }

        return null;
    }
}
