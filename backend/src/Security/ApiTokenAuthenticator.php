<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class ApiTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function supports(Request $request): bool
    {
        return null !== $request->headers->get('Authorization');
    }

    public function authenticate(Request $request): Passport
    {
        $header = trim((string) $request->headers->get('Authorization'));
        if (1 !== preg_match('/^Bearer ([A-Za-z0-9_-]{32,128})$/D', $header, $matches)) {
            throw new AuthenticationException('Invalid bearer token.');
        }
        $rawToken = $matches[1];

        return new SelfValidatingPassport(new UserBadge($rawToken, function (string $token): \Symfony\Component\Security\Core\User\UserInterface {
            $user = $this->users->findByApiTokenHash(hash('sha256', $token));
            if (null === $user) {
                throw new AuthenticationException('Invalid bearer token.');
            }

            return $user;
        }));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
    }
}
