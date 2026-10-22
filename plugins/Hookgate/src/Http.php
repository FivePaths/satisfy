<?php

namespace Playbloom\Plugin\Hookgate;

/**
 * GitLab's API and drupal.org's metadata.
 *
 * Every call returns [status, body], with status 0 when the call itself
 * failed: callers read that as "cannot tell", never as "absent".
 */
class Http
{
    public function __construct(private readonly HookgateConfig $config)
    {
    }

    public function hasApiToken(): bool
    {
        return null !== $this->config->getApiToken();
    }

    /**
     * GET from the GitLab API of the instance a project URL is on.
     *
     * @return array{0: int, 1: string}
     */
    public function api(string $projectUrl, string $pathAndQuery): array
    {
        $token = $this->config->getApiToken();
        $base = $this->config->getApiBase($projectUrl);
        if (null === $token || null === $base) {
            return [0, ''];
        }

        return self::get($base . $pathAndQuery, ['PRIVATE-TOKEN: ' . $token]);
    }

    /**
     * Whether drupal.org serves a package: true, false, or null if it did not say.
     */
    public function drupalOrgServes(string $package): ?bool
    {
        [$status] = self::get($this->config->getDrupalOrg() . '/' . $package . '.json', []);

        return 200 === $status ? true : (404 === $status ? false : null);
    }

    /**
     * Redirects are followed, GitLab answering 301 for a moved project, but
     * only on the same host, since the request may carry a token.
     *
     * @param string[] $headers
     *
     * @return array{0: int, 1: string}
     */
    private static function get(string $url, array $headers): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);
        $body = curl_exec($handle);
        $status = false === $body ? 0 : (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $landed = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
        curl_close($handle);
        if (parse_url($landed, PHP_URL_HOST) !== parse_url($url, PHP_URL_HOST)) {
            return [0, ''];
        }

        return [$status, false === $body ? '' : (string) $body];
    }
}
