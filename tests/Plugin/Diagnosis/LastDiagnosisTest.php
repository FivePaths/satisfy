<?php

namespace Playbloom\Tests\Plugin\Diagnosis;

use Playbloom\Model\BuildRequest;
use Playbloom\Plugin\Diagnosis\DiagnosisConfig;
use Playbloom\Plugin\Diagnosis\DiagnosisStore;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * With the mcp plugin enabled too, the newest diagnosis is a resource of its
 * server, beside the mcp plugin's own tools.
 */
class LastDiagnosisTest extends WebTestCase
{
    private const TOKEN = 'test-token';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/satisfy-diagnosis-mcp-' . bin2hex(random_bytes(4));
        $_SERVER['SATISFY_PLUGINS'] = 'mcp,diagnosis';
        $_SERVER['SATISFY_MCP_TOKEN'] = self::TOKEN;
        $_SERVER['SATISFY_DIAGNOSIS_DIR'] = $this->dir;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_SERVER['SATISFY_PLUGINS'], $_SERVER['SATISFY_MCP_TOKEN'], $_SERVER['SATISFY_DIAGNOSIS_DIR']);
        (new Filesystem())->remove($this->dir);
    }

    public function testTheNewestDiagnosisIsAResource(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $session = $this->initialize($client);

        $list = $this->post($client, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $session);
        $this->assertSame(['build_status', 'repositories'], array_column($list['result']['tools'], 'name'));
        $list = $this->post($client, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'resources/list'], $session);
        $this->assertSame(['satisfy://build/diagnosis'], array_column($list['result']['resources'], 'uri'));

        $this->assertSame(['status' => 'none'], $this->read($client, $session));

        $store = new DiagnosisStore(new DiagnosisConfig($this->dir));
        $id = $store->snapshot(BuildRequest::full(), 1, 'Exit code 1');
        $store->save($id, ['status' => 'done', 'diagnosis' => 'Cause: x']);

        $diagnosis = $this->read($client, $session);
        $this->assertSame('done', $diagnosis['status']);
        $this->assertSame($id, $diagnosis['build']['id']);
    }

    /**
     * @return array<string, mixed>
     *
     * @phpstan-impure
     */
    private function read(KernelBrowser $client, string $session): array
    {
        $read = $this->post($client, ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'resources/read', 'params' => ['uri' => 'satisfy://build/diagnosis']], $session);
        $this->assertSame('application/json', $read['result']['contents'][0]['mimeType']);

        return json_decode($read['result']['contents'][0]['text'], true);
    }

    private function initialize(KernelBrowser $client): string
    {
        $this->post($client, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
        ]);
        $session = (string) $client->getResponse()->headers->get('Mcp-Session-Id');
        $this->post($client, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $session);

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    private function post(KernelBrowser $client, array $message, ?string $session = null): array
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN,
        ];
        if (null !== $session) {
            $server['HTTP_MCP_SESSION_ID'] = $session;
            $server['HTTP_MCP_PROTOCOL_VERSION'] = '2025-06-18';
        }
        $client->request('POST', '/admin/mcp', [], [], $server, (string) json_encode($message));

        return json_decode((string) $client->getResponse()->getContent(), true) ?? [];
    }
}
