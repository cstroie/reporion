<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Auth\OAuth;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Storage\FileTree;

/**
 * OAuth 2.1 for the MCP server (D56, phase 3): discovery, registration, consent, code + PKCE,
 * and the token it ends in working on /mcp, limited to the namespaces approved.
 */
final class OAuthTest extends HttpTestCase
{
    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

    protected function setUp(): void
    {
        parent::setUp();
        $this->config['site']['base_url'] = 'https://wiki.example.org';
        $this->createOwner();
        (new FlatFileUserStore($this->dataRoot))->create('mihai', password_hash('pw-mihai-123', PASSWORD_ARGON2ID), false, [new Grant('docs', GrantRole::Viewer), new Grant('notes', GrantRole::Viewer)]);
        $tree = new FileTree($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
        $tree->create('docs:howto', ['title' => 'Howto', 'visibility' => 'private'], "Rsync explained.\n", 'owner');
        $tree->create('notes:todo', ['title' => 'Todo', 'visibility' => 'private'], "Rsync list.\n", 'owner');
    }

    public function testDiscoveryFromTheUnauthorisedMcp(): void
    {
        $r = $this->send(new Request('POST', '/mcp', body: '{}'));
        self::assertSame(401, $r->status);
        self::assertStringContainsString('resource_metadata="https://wiki.example.org/.well-known/oauth-protected-resource"', $r->headers['WWW-Authenticate']);
        $resource = json_decode($this->send(new Request('GET', '/.well-known/oauth-protected-resource'))->body, true);
        self::assertSame(['https://wiki.example.org'], $resource['authorization_servers']);
        $server = json_decode($this->send(new Request('GET', '/.well-known/oauth-authorization-server'))->body, true);
        self::assertSame('https://wiki.example.org/oauth/token', $server['token_endpoint']);
        self::assertSame(['S256'], $server['code_challenge_methods_supported']);
    }

    public function testTheWholeFlowEndsInANarrowedReadToken(): void
    {
        $client = $this->register();
        $verifier = str_repeat('v', 50);
        $query = ['response_type' => 'code', 'client_id' => $client, 'redirect_uri' => self::REDIRECT, 'code_challenge' => OAuth::challenge($verifier), 'code_challenge_method' => 'S256', 'state' => 'xyz'];

        $signedOut = $this->send(new Request('GET', '/oauth/authorize', query: $query));
        self::assertSame(302, $signedOut->status);
        self::assertStringStartsWith('/login?next=%2Foauth%2Fauthorize%3F', $signedOut->headers['Location']);

        $consent = $this->as('mihai', new Request('GET', '/oauth/authorize', query: $query));
        self::assertSame(200, $consent->status);
        self::assertSame('DENY', $consent->headers['X-Frame-Options']);

        $approved = $this->as('mihai', new Request('POST', '/oauth/authorize', body: http_build_query($query + ['decision' => 'approve', 'namespaces' => 'docs'])));
        self::assertSame(302, $approved->status);
        parse_str((string) parse_url($approved->headers['Location'], PHP_URL_QUERY), $back);
        self::assertStringStartsWith(self::REDIRECT . '?', $approved->headers['Location']);
        self::assertSame('xyz', $back['state']);

        $form = ['grant_type' => 'authorization_code', 'code' => $back['code'], 'client_id' => $client, 'redirect_uri' => self::REDIRECT];
        self::assertSame(400, $this->send(new Request('POST', '/oauth/token', body: http_build_query($form + ['code_verifier' => str_repeat('w', 50)])))->status, 'wrong verifier');
        $token = json_decode($this->send(new Request('POST', '/oauth/token', body: http_build_query($form + ['code_verifier' => $verifier])))->body, true);
        self::assertSame('Bearer', $token['token_type']);

        $hits = json_decode($this->send(new Request('POST', '/mcp', body: (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'search', 'arguments' => ['query' => 'rsync']]]), authorization: 'Bearer ' . $token['access_token']))->body, true);
        self::assertSame(['docs:howto'], array_column($hits['result']['structuredContent']['results'], 'path'));

        $record = (new FlatFileUserStore($this->dataRoot))->find('mihai')->tokens[0];
        self::assertSame(['read', ['docs']], [$record['scope'], $record['namespaces']]);
    }

    public function testDenyAndForeignRedirectsAndUnknownClients(): void
    {
        $client = $this->register();
        $query = ['response_type' => 'code', 'client_id' => $client, 'redirect_uri' => self::REDIRECT, 'code_challenge' => OAuth::challenge(str_repeat('v', 50)), 'code_challenge_method' => 'S256'];
        $denied = $this->as('mihai', new Request('POST', '/oauth/authorize', body: http_build_query($query + ['decision' => 'deny'])));
        self::assertStringContainsString('error=access_denied', $denied->headers['Location']);

        self::assertSame(400, $this->as('mihai', new Request('GET', '/oauth/authorize', query: ['redirect_uri' => 'https://evil.example/cb'] + $query))->status, 'never back to an address not registered');
        self::assertSame(400, $this->as('mihai', new Request('GET', '/oauth/authorize', query: ['client_id' => 'forged.sig'] + $query))->status);
        self::assertSame(400, $this->send(new Request('POST', '/oauth/register', body: (string) json_encode(['redirect_uris' => ['http://evil.example/cb']])))->status);
        self::assertSame([], (new FlatFileUserStore($this->dataRoot))->find('mihai')->tokens);
    }

    public function testNoBaseUrlNoOAuth(): void
    {
        $this->config['site']['base_url'] = '';
        self::assertSame(404, $this->send(new Request('GET', '/.well-known/oauth-authorization-server'))->status);
        self::assertStringNotContainsString('resource_metadata', $this->send(new Request('POST', '/mcp', body: '{}'))->headers['WWW-Authenticate']);
    }

    public function testSignInReturnsToTheConsentOnly(): void
    {
        $r = $this->send(new Request('POST', '/login', body: http_build_query(['username' => 'mihai', 'password' => 'pw-mihai-123', 'next' => '/oauth/authorize?x=1'])));
        self::assertSame('/oauth/authorize?x=1', $r->headers['Location']);
        $r = $this->send(new Request('POST', '/login', body: http_build_query(['username' => 'mihai', 'password' => 'pw-mihai-123', 'next' => '//evil.example'])));
        self::assertSame('/', $r->headers['Location']);
    }

    private function register(): string
    {
        $r = $this->send(new Request('POST', '/oauth/register', body: (string) json_encode(['client_name' => 'Claude', 'redirect_uris' => [self::REDIRECT]])));
        self::assertSame(201, $r->status);

        return json_decode($r->body, true)['client_id'];
    }

    private function as(string $username, Request $request): Response
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($username);

        return $this->send(new Request($request->method, $request->path, query: $request->query, cookies: ['reporion' => $cookie], body: $request->body));
    }

    private function send(Request $request): Response
    {
        return Kernel::boot($this->config)->handle($request);
    }
}
