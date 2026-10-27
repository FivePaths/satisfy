<?php

namespace Playbloom\Plugin\Mcp\Security;

use Playbloom\Plugin\Mcp\McpServerBundle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers 401 to a request for the MCP endpoint unless its Authorization
 * header carries SATISFY_MCP_TOKEN as a bearer token. While no token is set,
 * every request is refused.
 *
 * A listener rather than a firewall: Symfony takes firewalls from one
 * configuration file only, which is Satisfy's, and its firewall signs in by
 * form. It runs after routing and before that firewall.
 */
final class BearerTokenListener
{
    public function __construct(
        #[Autowire(env: 'default::SATISFY_MCP_TOKEN')]
        private readonly ?string $token,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
    public function onKernelRequest(RequestEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if (!$event->isMainRequest() || (McpServerBundle::PATH !== $path && !str_starts_with($path, McpServerBundle::PATH . '/'))) {
            return;
        }

        $header = (string) $event->getRequest()->headers->get('Authorization');
        $given = preg_match('/^Bearer\s+(\S+)$/i', $header, $match) ? $match[1] : '';
        if (null !== $this->token && '' !== $this->token && hash_equals($this->token, $given)) {
            return;
        }

        $event->setResponse(new JsonResponse(
            ['error' => 'A bearer token is required.'],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => 'Bearer realm="Satisfy MCP"'],
        ));
    }
}
