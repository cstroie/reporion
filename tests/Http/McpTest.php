<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Storage\FileTree;

/**
 * The MCP server (D56, phase 1) and tokens limited to namespaces: /mcp answers a bearer token
 * only, sees what the token's account sees narrowed to its namespaces, and serves no report.
 */
final class McpTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        (new FlatFileUserStore($this->dataRoot))->create('mihai', password_hash('pw-mihai-123', PASSWORD_ARGON2ID), false, [
            new Grant('reports:mri', GrantRole::Editor), new Grant('docs', GrantRole::Viewer), new Grant('notes', GrantRole::Editor),
        ]);
        $this->makeRadiologist('mihai');
        $tree = new FileTree($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
        $tree->create('reports:mri:mioveni:a', ['title' => 'Report', 'visibility' => 'private'], "Gonartroza dreapta.\n", 'owner');
        $tree->create('docs:howto', ['title' => 'Howto', 'visibility' => 'private'], "Gonartroza explained.\n", 'owner');
        $tree->create('docs:guide:backup', ['title' => 'Backup', 'visibility' => 'private'], "Rsync.\n", 'owner');
        $tree->create('notes:todo', ['title' => 'Todo', 'visibility' => 'private'], "Gonartroza list.\n", 'owner');
    }

    public function testInitializeAndListTools(): void
    {
        $token = $this->newToken('mihai', '');
        $init = $this->rpc($token, 'initialize', ['protocolVersion' => '2025-06-18']);
        self::assertSame('reporion', $init['result']['serverInfo']['name']);
        self::assertSame(['search', 'get_page', 'list_namespace'], array_column($this->rpc($token, 'tools/list')['result']['tools'], 'name'));
        self::assertSame(202, $this->post($token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])->status);
    }

    public function testNoTokenNoCookie(): void
    {
        $none = Kernel::boot($this->config)->handle(new Request('POST', '/mcp', body: '{}'));
        self::assertSame(401, $none->status);
        self::assertStringContainsString('Bearer', $none->headers['WWW-Authenticate']);

        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('mihai');
        $withCookie = Kernel::boot($this->config)->handle(new Request('POST', '/mcp', cookies: ['reporion' => $cookie], body: '{"jsonrpc":"2.0","id":1,"method":"ping"}'));
        self::assertSame(401, $withCookie->status, 'a cookie never drives /mcp');
    }

    public function testReportsAreNeverServed(): void
    {
        $token = $this->newToken('mihai', '');
        $hits = $this->tool($token, 'search', ['query' => 'gonartroza'])['result']['structuredContent']['results'];
        self::assertEqualsCanonicalizing(['docs:howto', 'notes:todo'], array_column($hits, 'path'));
        self::assertTrue($this->tool($token, 'get_page', ['path' => 'reports:mri:mioveni:a'])['result']['isError']);
        self::assertSame(1, $this->tool($token, 'get_page', ['path' => 'docs:howto'])['result']['structuredContent']['rev']);
    }

    public function testATokenLimitedToANamespaceSeesNothingElse(): void
    {
        $token = $this->newToken('mihai', 'docs');
        $hits = $this->tool($token, 'search', ['query' => 'gonartroza'])['result']['structuredContent']['results'];
        self::assertSame(['docs:howto'], array_column($hits, 'path'));
        self::assertTrue($this->tool($token, 'get_page', ['path' => 'notes:todo'])['result']['isError']);
        self::assertSame(404, $this->bearer($token, 'GET', '/api/v1/pages/notes:todo')->status, 'the API too');
        self::assertSame(404, $this->bearer($token, 'GET', '/api/v1/pages/reports:mri:mioveni:a')->status);

        $list = $this->tool($token, 'list_namespace', ['namespace' => 'docs'])['result']['structuredContent'];
        self::assertSame(['docs:howto'], array_column($list['pages'], 'path'));
        self::assertSame(['docs:guide'], array_column($list['namespaces'], 'namespace'));
    }

    public function testANarrowedTokenNeverWidensAndAnOwnerLosesTheOwnerRole(): void
    {
        $mihai = $this->newToken('mihai', 'secret');
        self::assertSame([], $this->tool($mihai, 'search', ['query' => 'gonartroza'])['result']['structuredContent']['results'], 'no grant there, none given');

        $owner = $this->newToken('owner', 'notes');
        self::assertSame(['notes:todo'], array_column($this->tool($owner, 'search', ['query' => 'gonartroza'])['result']['structuredContent']['results'], 'path'));
        self::assertSame(404, $this->bearer($owner, 'GET', '/api/v1/pages/docs:howto')->status);
        self::assertSame(['notes'], (new FlatFileUserStore($this->dataRoot))->find('owner')->tokens[0]['namespaces'], 'kept on the record');
        self::assertTrue((new FlatFileUserStore($this->dataRoot))->find('owner')->isOwner, 'the account itself unchanged');
    }

    public function testWithReportsOnAReportIsServedDeidentifiedByPidOnly(): void
    {
        $this->config['mcp']['reports'] = true;
        $tree = new FileTree($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
        $tree->create('reports:mri:mioveni:261008-testescu-ana', [
            'visibility' => 'private', 'patient' => ['name' => 'Testescu Ana', 'cnp' => '2900101123456'],
            'exam_title' => 'IRM genunchi drept', 'modality' => ['mri'],
        ], "# Testescu Ana\n\n## IRM genunchi drept\n\n### Concluzii\n\nGonartroza la pacienta Testescu, CNP 2900101123456.\n", 'owner');
        $token = $this->newToken('mihai', '');

        $hits = $this->tool($token, 'search', ['query' => 'gonartroza'])['result']['structuredContent']['results'];
        $reports = array_values(array_filter($hits, static fn (array $h): bool => isset($h['pid'])));
        self::assertCount(2, $reports, 'both reports, by pid');
        $report = array_values(array_filter($reports, static fn (array $h): bool => $h['title'] === 'IRM genunchi drept'));
        self::assertCount(1, $report);
        self::assertArrayNotHasKey('path', $report[0]);
        self::assertSame('IRM genunchi drept', $report[0]['title']);

        $all = json_encode($hits, JSON_UNESCAPED_UNICODE) . json_encode($this->tool($token, 'get_page', ['pid' => $report[0]['pid']]), JSON_UNESCAPED_UNICODE);
        foreach (['Testescu', 'Ana', '2900101123456', '261008', 'mioveni:'] as $identifier) {
            self::assertStringNotContainsString($identifier, $all, $identifier);
        }
        $page = $this->tool($token, 'get_page', ['pid' => $report[0]['pid']])['result']['structuredContent'];
        self::assertStringContainsString('Gonartroza', $page['body']);
        self::assertStringContainsString('[pacient]', $page['body']);
        self::assertTrue($this->tool($token, 'get_page', ['path' => 'reports:mri:mioveni:261008-testescu-ana'])['result']['isError'], 'never by path');

        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringNotContainsString('testescu', strtolower($audit));
    }

    public function testWithReportsOffAPidFindsNothing(): void
    {
        $token = $this->newToken('mihai', '');
        $pid = $this->storagePid('reports:mri:mioveni:a');
        self::assertTrue($this->tool($token, 'get_page', ['pid' => $pid])['result']['isError']);
    }

    private function storagePid(string $path): string
    {
        return (new FileTree($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations')))->read($path)->pid;
    }

    public function testAWriteTokenCreatesAndUpdatesOrdinaryPagesOnly(): void
    {
        $read = $this->newToken('mihai', '');
        self::assertNotContains('create_page', array_column($this->rpc($read, 'tools/list')['result']['tools'], 'name'), 'a read token has no write tools');
        self::assertTrue($this->tool($read, 'create_page', ['path' => 'notes:new', 'body' => 'x'])['result']['isError']);

        $write = $this->newToken('mihai', '', 'write');
        self::assertContains('update_page', array_column($this->rpc($write, 'tools/list')['result']['tools'], 'name'));
        $made = $this->tool($write, 'create_page', ['path' => 'notes:new', 'body' => "# New\n\nText.\n", 'note' => 'first'])['result'];
        self::assertSame(1, $made['structuredContent']['rev']);
        $page = $this->storage()->read('notes:new');
        self::assertSame('mihai', $page->meta['revlog'][0]['by']);
        self::assertStringContainsString('(via MCP)', (string) $page->meta['revlog'][0]['note']);

        self::assertSame(2, $this->tool($write, 'update_page', ['path' => 'notes:new', 'body' => "# New\n\nMore.\n", 'base_rev' => 1])['result']['structuredContent']['rev']);
        $stale = $this->tool($write, 'update_page', ['path' => 'notes:new', 'body' => 'Old.', 'base_rev' => 1])['result'];
        self::assertTrue($stale['isError']);
        self::assertStringContainsString('More.', $stale['content'][0]['text'], 'the current text comes back');
        self::assertSame(2, $this->storage()->read('notes:new')->rev);

        self::assertTrue($this->tool($write, 'update_page', ['path' => 'reports:mri:mioveni:a', 'body' => 'x', 'base_rev' => 1])['result']['isError'], 'never a report');
        self::assertTrue($this->tool($write, 'create_page', ['path' => 'reports:mri:mioveni:b', 'body' => 'x'])['result']['isError']);
        self::assertTrue($this->tool($write, 'update_page', ['path' => 'docs:howto', 'body' => 'x', 'base_rev' => 1])['result']['isError'], 'a viewer grant writes nothing');
        self::assertSame(1, $this->storage()->read('docs:howto')->rev);
    }

    public function testAccessOffOrLocalOnly(): void
    {
        $token = $this->newToken('mihai', '');
        $ping = (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        $call = fn (string $addr, string $xff = ''): int => Kernel::boot($this->config)->handle(new Request('POST', '/mcp', body: $ping, remoteAddr: $addr, authorization: 'Bearer ' . $token, forwardedFor: $xff))->status;

        $this->config['mcp']['access'] = 'local';
        self::assertSame(200, $call('127.0.0.1'));
        self::assertSame(200, $call('10.8.0.6'));
        self::assertSame(404, $call('203.0.113.9'));
        self::assertSame(404, $call('127.0.0.1', '203.0.113.9'), 'a proxy forwarding for the internet');
        $this->config['site']['base_url'] = 'https://wiki.example.org';
        self::assertSame(404, Kernel::boot($this->config)->handle(new Request('GET', '/.well-known/oauth-authorization-server'))->status, 'no OAuth when local');

        $this->config['mcp']['access'] = 'off';
        self::assertSame(404, $call('127.0.0.1'));
    }

    public function testTheServerShowsReporionsIcon(): void
    {
        $this->config['site']['base_url'] = 'https://wiki.example.org/reporion';
        $info = $this->rpc($this->newToken('mihai', ''), 'initialize')['result']['serverInfo'];
        self::assertSame('Reporion', $info['title']);
        self::assertSame('https://wiki.example.org/reporion/assets/icon.svg', $info['icons'][0]['src']);
    }

    private function storage(): FileTree
    {
        return new FileTree($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }

    public function testAMalformedNamespaceIsRefused(): void
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('mihai');
        $r = Kernel::boot($this->config)->handle(new Request('POST', '/profile/tokens', cookies: ['reporion' => $cookie], body: http_build_query(['name' => 'x', 'scope' => 'read', 'namespaces' => 'docs::x'])));
        self::assertSame(422, $r->status);
    }

    private function newToken(string $username, string $namespaces, string $scope = 'read'): string
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($username);
        $response = Kernel::boot($this->config)->handle(new Request('POST', '/profile/tokens', cookies: ['reporion' => $cookie], body: http_build_query(['name' => 'claude', 'scope' => $scope, 'namespaces' => $namespaces])));
        self::assertSame(200, $response->status);
        self::assertSame(1, preg_match('/id="new-token">([^<]+)</', $response->body, $m));

        return html_entity_decode($m[1], ENT_QUOTES);
    }

    /** @return array<string, mixed> */
    private function tool(string $token, string $name, array $arguments): array
    {
        return $this->rpc($token, 'tools/call', ['name' => $name, 'arguments' => $arguments]);
    }

    /** @return array<string, mixed> */
    private function rpc(string $token, string $method, array $params = []): array
    {
        $response = $this->post($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
        self::assertSame(200, $response->status);

        return json_decode($response->body, true);
    }

    /** @param array<string, mixed> $message */
    private function post(string $token, array $message): Response
    {
        return $this->bearer($token, 'POST', '/mcp', $message);
    }

    /** @param array<string, mixed> $json */
    private function bearer(string $token, string $method, string $path, array $json = []): Response
    {
        return Kernel::boot($this->config)->handle(new Request($method, $path, body: $json === [] ? '' : (string) json_encode($json), authorization: 'Bearer ' . $token));
    }
}
