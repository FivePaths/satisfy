<?php

namespace Playbloom\Tests\Plugin\Mcp;

use org\bovigo\vfs\vfsStream;
use Playbloom\Plugin\Mcp\Tool\Repositories;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The plugin enabled through SATISFY_PLUGINS: the endpoint at /admin/mcp, its
 * token check, and its two tools.
 */
class McpServerTest extends WebTestCase
{
    private const TOKEN = 'test-token';

    protected function setUp(): void
    {
        parent::setUp();
        $root = vfsStream::setup();
        vfsStream::newFile('satis.json')->at($root)->setContent((string) json_encode([
            'name' => 'acme/packages',
            'homepage' => 'https://packages.acme.test',
            'repositories' => [
                ['type' => 'vcs', 'url' => 'git@github.com:acme/app.git'],
                ['type' => 'git', 'url' => 'https://deploy:secret@git.acme.test/acme/private.git'],
            ],
        ]));
        $_SERVER['SATISFY_PLUGINS'] = 'mcp';
        $_SERVER['SATISFY_MCP_TOKEN'] = self::TOKEN;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_SERVER['SATISFY_PLUGINS'], $_SERVER['SATISFY_MCP_TOKEN']);
    }

    public function testARequestWithoutTheTokenIsRefused(): void
    {
        $client = self::createClient();

        $this->post($client, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->assertResponseHeaderSame('WWW-Authenticate', 'Bearer realm="Satisfy MCP"');

        $this->post($client, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], 'wrong');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testWithNoTokenSetNothingIsAdmitted(): void
    {
        $_SERVER['SATISFY_MCP_TOKEN'] = '';
        $client = self::createClient();

        $this->post($client, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], '');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testTheToolsAreListedAndAnswer(): void
    {
        $client = self::createClient();
        $client->disableReboot();

        $this->post($client, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
        ], self::TOKEN);
        $this->assertResponseIsSuccessful();
        $session = (string) $client->getResponse()->headers->get('Mcp-Session-Id');
        $this->assertNotSame('', $session);

        $this->post($client, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], self::TOKEN, $session);

        $tools = $this->post($client, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], self::TOKEN, $session);
        $this->assertSame(['build_status', 'repositories'], array_column($tools['result']['tools'], 'name'));

        $result = $this->post($client, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'repositories', 'arguments' => []]], self::TOKEN, $session);
        $repositories = json_decode($result['result']['content'][0]['text'], true)['repositories'];
        $this->assertSame([
            'git@github.com:acme/app.git',
            'https://***@git.acme.test/acme/private.git',
        ], array_column($repositories, 'url'));

        $result = $this->post($client, ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'build_status', 'arguments' => ['logBytes' => 100]]], self::TOKEN, $session);
        $status = json_decode($result['result']['content'][0]['text'], true);
        $this->assertSame(0, $status['pending']);
        $this->assertFalse($status['building']);
    }

    /**
     * @dataProvider urls
     */
    public function testCredentialsAreRemovedFromURLs(string $url, string $expected): void
    {
        $this->assertSame($expected, Repositories::withoutCredentials($url));
    }

    public static function urls(): iterable
    {
        yield 'a token as the user' => ['https://ghp_abc@github.com/acme/app.git', 'https://***@github.com/acme/app.git'];
        yield 'a user and password' => ['https://deploy:secret@git.acme.test/acme/app.git', 'https://***@git.acme.test/acme/app.git'];
        yield 'no user information' => ['https://github.com/acme/app.git', 'https://github.com/acme/app.git'];
        yield 'scp style' => ['git@github.com:acme/app.git', 'git@github.com:acme/app.git'];
    }

    /**
     * @return array<string, mixed>
     */
    private function post(KernelBrowser $client, array $message, ?string $token = null, ?string $session = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        if (null !== $session) {
            $server['HTTP_MCP_SESSION_ID'] = $session;
            $server['HTTP_MCP_PROTOCOL_VERSION'] = '2025-06-18';
        }
        $client->request('POST', '/admin/mcp', [], [], $server, (string) json_encode($message));

        return json_decode((string) $client->getResponse()->getContent(), true) ?? [];
    }
}
