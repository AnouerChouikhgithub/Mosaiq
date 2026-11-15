<?php

declare(strict_types=1);

namespace App\Tests\Controller;

final class AuthSecurityTest extends ApiFunctionalTestCase
{
    public function testLoginIssuesAnOpaqueHashedTokenWithExpiryAndNoSecretFields(): void
    {
        $this->requestAs(null, 'POST', '/api/auth/login', [
            'email' => $this->owner->getEmail(), 'password' => 'Correct-Horse-2026!',
        ]);
        self::assertResponseIsSuccessful();

        $login = $this->jsonResponse();
        self::assertIsString($login['token']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{64}$/D', $login['token']);
        self::assertArrayNotHasKey('password', $login);
        self::assertArrayNotHasKey('password_hash', $login);
        self::assertArrayNotHasKey('api_token_hash', $login);
        self::assertArrayHasKey('expiresAt', $login);

        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT api_token_hash, api_token_expires_at FROM users WHERE id = :id',
            ['id' => $this->owner->getId()],
        );
        self::assertIsArray($row);
        self::assertSame(hash('sha256', $login['token']), $row['api_token_hash']);
        self::assertNotSame($login['token'], $row['api_token_hash']);
        self::assertGreaterThan(new \DateTimeImmutable(), new \DateTimeImmutable((string) $row['api_token_expires_at']));
    }

    public function testExpiredTokenIsRejectedAcrossProtectedEndpoints(): void
    {
        $token = 'expired-token-123456789012345678901234';
        $this->owner->issueApiToken(hash('sha256', $token), new \DateTimeImmutable('-1 minute'));
        $this->entityManager->flush();

        foreach (['/api/auth/me', '/api/boards', '/api/workspaces'] as $path) {
            $this->requestAs($token, 'GET', $path);
            self::assertResponseStatusCodeSame(401);
        }
    }

    public function testLogoutRevokesOnlyTheCurrentUsersToken(): void
    {
        $this->requestAs($this->ownerToken, 'POST', '/api/auth/logout');
        self::assertResponseIsSuccessful();

        $this->requestAs($this->ownerToken, 'GET', '/api/boards');
        self::assertResponseStatusCodeSame(401);

        $this->requestAs($this->editorToken, 'GET', '/api/boards');
        self::assertResponseIsSuccessful();
    }

    public function testSecondLoginReplacesThePreviousToken(): void
    {
        $this->requestAs(null, 'POST', '/api/auth/login', [
            'email' => $this->owner->getEmail(), 'password' => 'Correct-Horse-2026!',
        ]);
        $first = $this->jsonResponse()['token'];

        $this->requestAs(null, 'POST', '/api/auth/login', [
            'email' => $this->owner->getEmail(), 'password' => 'Correct-Horse-2026!',
        ]);
        $second = $this->jsonResponse()['token'];

        $this->requestAs($first, 'GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401);

        $this->requestAs($second, 'GET', '/api/auth/me');
        self::assertResponseIsSuccessful();
    }

    public function testMalformedAndShortBearerTokensAreRejected(): void
    {
        $this->requestAs('short', 'GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401);

        $this->client->request('GET', '/api/auth/me', server: ['HTTP_AUTHORIZATION' => 'Bearer']);
        self::assertResponseStatusCodeSame(401);

        $this->client->request('GET', '/api/auth/me', server: ['HTTP_AUTHORIZATION' => 'Basic abc']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testRegistrationEnforcesPasswordPolicyAndHashesThePassword(): void
    {
        foreach ([
            'short-pass1',
            'onlyletterspassword',
            '123456789012',
            str_repeat('a1', 40),
        ] as $weakPassword) {
            $this->requestAs(null, 'POST', '/api/auth/register', [
                'name' => 'Policy Person', 'email' => 'policy-'.md5($weakPassword).'@example.test',
                'password' => $weakPassword,
            ]);
            self::assertResponseStatusCodeSame(422);
        }

        $this->requestAs(null, 'POST', '/api/auth/register', [
            'name' => 'Policy Person', 'email' => 'policy-valid@example.test',
            'password' => 'Valid-Password-2026!',
        ]);
        self::assertResponseStatusCodeSame(201);

        $response = $this->jsonResponse();
        self::assertArrayNotHasKey('password', $response);
        self::assertArrayNotHasKey('password_hash', $response);

        $hash = $this->entityManager->getConnection()->fetchOne(
            'SELECT password_hash FROM users WHERE email = :email',
            ['email' => 'policy-valid@example.test'],
        );
        self::assertIsString($hash);
        self::assertNotSame('Valid-Password-2026!', $hash);
        self::assertTrue(password_verify('Valid-Password-2026!', $hash));
    }

    public function testRepeatedFailedLoginsAreThrottled(): void
    {
        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $this->requestAs(null, 'POST', '/api/auth/login', [
                'email' => 'unknown-attacker@example.test', 'password' => 'wrong-password',
            ]);
            self::assertResponseStatusCodeSame(401);
        }

        $this->requestAs(null, 'POST', '/api/auth/login', [
            'email' => 'unknown-attacker@example.test', 'password' => 'wrong-password',
        ]);
        self::assertResponseStatusCodeSame(429);
        self::assertSame('900', $this->client->getResponse()->headers->get('Retry-After'));
    }

    public function testSuccessfulLoginClearsPreviousFailures(): void
    {
        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            $this->requestAs(null, 'POST', '/api/auth/login', [
                'email' => $this->owner->getEmail(), 'password' => 'wrong-password',
            ]);
            self::assertResponseStatusCodeSame(401);
        }

        $this->requestAs(null, 'POST', '/api/auth/login', [
            'email' => $this->owner->getEmail(), 'password' => 'Correct-Horse-2026!',
        ]);
        self::assertResponseIsSuccessful();

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $this->requestAs(null, 'POST', '/api/auth/login', [
                'email' => $this->owner->getEmail(), 'password' => 'wrong-password',
            ]);
            self::assertResponseStatusCodeSame(401);
        }

        $this->requestAs(null, 'POST', '/api/auth/login', [
            'email' => $this->owner->getEmail(), 'password' => 'wrong-password',
        ]);
        self::assertResponseStatusCodeSame(429);
    }

    public function testRegistrationAttemptsAreThrottled(): void
    {
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->requestAs(null, 'POST', '/api/auth/register', [
                'name' => 'Spam', 'email' => 'not-an-email', 'password' => 'Valid-Password-2026!',
            ]);
            self::assertResponseStatusCodeSame(422);
        }

        $this->requestAs(null, 'POST', '/api/auth/register', [
            'name' => 'Spam', 'email' => 'not-an-email', 'password' => 'Valid-Password-2026!',
        ]);
        self::assertResponseStatusCodeSame(429);
        self::assertSame('3600', $this->client->getResponse()->headers->get('Retry-After'));
    }

    public function testMeUsesTheTokenIdentityAndAuthResponsesAreNotCacheable(): void
    {
        $this->requestAs($this->ownerToken, 'GET', '/api/auth/me?userId='.$this->editor->getId());
        self::assertResponseIsSuccessful();
        self::assertSame($this->owner->getId(), $this->jsonResponse()['user']['id']);

        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertSame('no-referrer', $this->client->getResponse()->headers->get('Referrer-Policy'));
        self::assertSame('DENY', $this->client->getResponse()->headers->get('X-Frame-Options'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }
}
