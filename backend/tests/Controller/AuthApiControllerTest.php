<?php

declare(strict_types=1);

namespace App\Tests\Controller;

final class AuthApiControllerTest extends ApiFunctionalTestCase
{
    public function testLoginIssuesBearerTokenAndMeReturnsAuthenticatedProfile(): void
    {
        $this->requestAs(null, 'POST', '/api/auth/login', ['email' => $this->owner->getEmail(), 'password' => 'Correct-Horse-2026!']);
        self::assertResponseIsSuccessful();
        $login = $this->jsonResponse();
        self::assertArrayHasKey('token', $login);
        self::assertSame($this->owner->getEmail(), $login['user']['email']);

        $this->requestAs($login['token'], 'GET', '/api/auth/me');
        self::assertResponseIsSuccessful();
        self::assertSame($this->owner->getId(), $this->jsonResponse()['user']['id']);
    }

    public function testInvalidLoginAndProtectedRouteReturnUnauthorized(): void
    {
        $this->requestAs(null, 'GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401);

        $this->requestAs(null, 'POST', '/api/auth/login', ['email' => $this->owner->getEmail(), 'password' => 'wrong']);
        self::assertResponseStatusCodeSame(401);

        $this->requestAs('invalid-token-which-is-long-enough-to-check', 'GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401);
    }

    public function testLogoutRevokesPreviouslyIssuedToken(): void
    {
        $this->requestAs($this->ownerToken, 'POST', '/api/auth/logout');
        self::assertResponseIsSuccessful();
        $this->requestAs($this->ownerToken, 'GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401);
    }

    public function testRegistrationCreatesAnIsolatedWorkspace(): void
    {
        $this->requestAs(null, 'POST', '/api/auth/register', [
            'name' => 'New Person', 'email' => 'new-person@example.test', 'password' => 'A-Long-Password-2026!',
            'workspaceId' => $this->workspace->getId(),
        ]);
        self::assertResponseStatusCodeSame(201);
        $created = $this->jsonResponse();
        self::assertNotSame($this->workspace->getId(), $created['workspace']['id']);
    }
}
