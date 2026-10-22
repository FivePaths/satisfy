<?php

namespace Playbloom\Webhook;

use Symfony\Component\HttpFoundation\Request;

class GiteaWebhook extends GithubWebhook
{
    protected function validate(Request $request): void
    {
        if (empty($this->secret)) {
            return;
        }

        if ($request->headers->has('X-Gitea-Signature')) {
            $this->verifySignature($request, 'X-Gitea-Signature', '');

            return;
        }

        // Gogs and older Gitea releases sent the secret in the payload instead.
        $secret = $this->getPayload($request)['secret'] ?? null;
        if (!is_string($secret) || !hash_equals($this->secret, $secret)) {
            throw new \InvalidArgumentException('Invalid Gitea Signature');
        }
    }
}
