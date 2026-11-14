<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The Vite dev server runs on a different origin than the PHP API, so the
 * browser needs CORS headers. Only loopback development origins are allowed;
 * production origins simply receive no CORS grant.
 */
final class DevelopmentCorsSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 250],
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ('OPTIONS' !== $request->getMethod() || !str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $response = new Response(null, Response::HTTP_NO_CONTENT);
        $this->applyHeaders($request->headers->get('Origin'), $response);
        $event->setResponse($response);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $this->applyHeaders($request->headers->get('Origin'), $event->getResponse());
    }

    private function applyHeaders(?string $origin, Response $response): void
    {
        if (null === $origin || 1 !== preg_match('~^http://(?:localhost|127\\.0\\.0\\.1):(?:517[3-9]|51[89][0-9])$~D', $origin)) {
            return;
        }

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization');
        $response->headers->set('Access-Control-Max-Age', '600');
        $response->headers->set('Vary', 'Origin');
    }
}
