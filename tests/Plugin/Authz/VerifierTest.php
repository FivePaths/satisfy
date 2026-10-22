<?php

namespace Playbloom\Tests\Plugin\Authz;

use PHPUnit\Framework\TestCase;
use Playbloom\Plugin\Authz\Audit;
use Playbloom\Plugin\Authz\AuthzConfig;
use Playbloom\Plugin\Authz\Database;
use Playbloom\Plugin\Authz\Deps;
use Playbloom\Plugin\Authz\Entitlement;
use Playbloom\Plugin\Authz\Sites;
use Playbloom\Plugin\Authz\Token;
use Playbloom\Plugin\Authz\Verifier;
use Symfony\Component\Filesystem\Filesystem;

/**
 * What nginx's auth_request gets back for a catalogue request.
 */
class VerifierTest extends TestCase
{
    private string $dir;

    private AuthzConfig $config;

    private string $header;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/satisfy-authz-' . bin2hex(random_bytes(4));
        $_SERVER['TOKEN_PEPPER'] = 'test-pepper';
        $_SERVER['ENFORCE'] = 'true';
        $this->config = new AuthzConfig($this->dir . '/data', $this->dir . '/catalogue');

        // A catalogue with acme/app requiring acme/lib, and acme/other.
        $this->writeP2('acme/app', ['acme/lib' => '^1.0', 'php' => '>=8.1']);
        $this->writeP2('acme/lib', []);
        $this->writeP2('acme/other', []);
        file_put_contents($this->dir . '/catalogue/packages.json', '{}');

        $sites = new Sites($this->database(), $this->token());
        $this->header = 'Basic ' . base64_encode('site-a:' . $sites->issue('site-a'));
        $db = $this->database()->writable();
        $this->entitlement()->grant($db, 'site-a', 'acme/app', 'test');
        $this->entitlement()->setEnforce($db, 'site-a', true);
        $db->close();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['TOKEN_PEPPER'], $_SERVER['ENFORCE']);
        (new Filesystem())->remove($this->dir);
        parent::tearDown();
    }

    public function testAGrantedPackageAndItsDependenciesAreAllowed(): void
    {
        $this->assertSame(200, $this->decide($this->header, '/p2/acme/app.json'));
        $this->assertSame(200, $this->decide($this->header, '/dist/acme/lib/acme-lib-1.0.0-abc123.tar'));
        $this->assertSame(200, $this->decide($this->header, '/packages.json'));
    }

    public function testAPackageNotGrantedIsRefusedAndQueued(): void
    {
        $this->assertSame(403, $this->decide($this->header, '/p2/acme/other.json'));

        $db = $this->database()->writable();
        $requests = $this->entitlement()->openRequests($db, 'site-a');
        $db->close();
        $this->assertSame(['acme/other'], array_column($requests, 'package'));
    }

    public function testAWrongOrMissingTokenIsRefused(): void
    {
        $this->assertSame(401, $this->decide('Basic ' . base64_encode('site-a:wrong'), '/packages.json'));
        $this->assertSame(401, $this->decide('', '/packages.json'));
    }

    public function testPermissiveModeRecordsAndAllows(): void
    {
        $this->config->setEnforcing(false);

        $this->assertSame(200, $this->decide('', '/packages.json'));
        $this->assertSame(1, (new Audit($this->config))->summary()['unauth']);
    }

    public function testTheCanaryAlwaysEnforces(): void
    {
        $this->config->setEnforcing(false);

        $this->assertSame(401, $this->decide('', '/_authzcheck'));
        $this->assertSame(200, $this->decide($this->header, '/_authzcheck'));
    }

    public function testARevokedSiteIsRefused(): void
    {
        (new Sites($this->database(), $this->token()))->setActive('site-a', false);

        $this->assertSame(401, $this->decide($this->header, '/packages.json'));
    }

    private function decide(string $header, string $uri): int
    {
        return Verifier::create($this->config)->decide($header, $uri, '127.0.0.1')[0];
    }

    private function database(): Database
    {
        return new Database($this->config);
    }

    private function token(): Token
    {
        return new Token($this->config, $this->database(), new Audit($this->config));
    }

    private function entitlement(): Entitlement
    {
        return new Entitlement($this->database(), new Deps($this->config), new Audit($this->config));
    }

    private function writeP2(string $package, array $require): void
    {
        $file = $this->dir . '/catalogue/p2/' . $package . '.json';
        (new Filesystem())->mkdir(dirname($file));
        file_put_contents($file, (string) json_encode(['packages' => [$package => [['version' => '1.0.0', 'require' => $require]]]]));
    }
}
