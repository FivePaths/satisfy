<?php

namespace Playbloom\Event;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched for every webhook delivery that passed its secret check, before
 * Satisfy looks up the repository it names.
 *
 * A listener can answer the delivery itself: skip() it, or accept() it and
 * finish the work after the response has gone, on kernel.terminate.
 */
class WebhookReceivedEvent extends Event
{
    private ?Response $response = null;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private readonly string $provider,
        private readonly Request $request,
        private readonly array $payload,
    ) {
    }

    /**
     * gitlab, github, gitea, bitbucket or devops.
     */
    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * The tag this delivery reports as pushed, or null for anything else,
     * including a deleted tag, whose "after" commit is all zeros.
     */
    public function getCreatedTag(): ?string
    {
        $ref = (string) ($this->payload['ref'] ?? '');
        if (!str_starts_with($ref, 'refs/tags/')) {
            return null;
        }
        if (!empty($this->payload['deleted']) || preg_match('/^0+$/', (string) ($this->payload['after'] ?? ''))) {
            return null;
        }
        $tag = substr($ref, strlen('refs/tags/'));

        return '' === $tag ? null : $tag;
    }

    /**
     * Answers 200 and does nothing more with the delivery.
     */
    public function skip(string $reason): void
    {
        $this->response = new Response('skipped: ' . $reason, Response::HTTP_OK);
        $this->stopPropagation();
    }

    /**
     * Answers 202; the listener does the work itself.
     */
    public function accept(string $message): void
    {
        $this->response = new Response($message, Response::HTTP_ACCEPTED);
        $this->stopPropagation();
    }

    public function getResponse(): ?Response
    {
        return $this->response;
    }
}
