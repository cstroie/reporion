<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Auth\ApiTokens;
use Reporion\Auth\OAuth;
use Reporion\Auth\User;
use Reporion\Auth\UserStoreInterface;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Throttle;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;

/**
 * The OAuth 2.1 endpoints of the MCP server (D56, phase 3; Auth\OAuth): the two metadata
 * documents (RFC 9728, RFC 8414), dynamic client registration (RFC 7591), the consent screen,
 * and the token endpoint. The consent is a signed-in screen: the account ticks nothing it does
 * not already reach, it only narrows (namespaces), and the token is read only unless the user
 * ticks Allow changes (phase 4: ordinary pages only, never a report or a signed page). Audited
 * `oauth.authorize` and `token.create`, with the client's name and the token's id.
 */
final class OAuthController
{
    public function __construct(
        private readonly OAuth $oauth,
        private readonly ApiTokens $tokens,
        private readonly UserStoreInterface $users,
        private readonly AuditLog $audit,
        private readonly IndexInterface $index,
        // site.base_url: the issuer; empty, no OAuth is offered
        private readonly string $baseUrl,
        // Registrations and failed token requests per address, and codes already redeemed (hardening)
        private readonly ?Throttle $throttle = null,
    ) {
    }

    /** How long a token made here works (hardening): Claude asks again after it */
    public const TOKEN_DAYS = 30;
    private const REGISTRATIONS_PER_HOUR = 20;
    private const TOKEN_FAILURES = 10;
    private const WINDOW = 900;

    private function tooMany(string $what, Request $request, int $limit, int $window): bool
    {
        return $this->throttle !== null && $this->throttle->count($what . ':' . $request->remoteAddr, $window) >= $limit;
    }

    public function enabled(): bool
    {
        return $this->baseUrl !== '';
    }

    /** GET /.well-known/oauth-protected-resource */
    public function resourceMetadata(): Response
    {
        if (!$this->enabled()) {
            return self::json(['error' => 'not_found'], 404);
        }

        return self::json([
            'resource' => $this->baseUrl . '/mcp',
            'authorization_servers' => [$this->baseUrl],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => ['read', 'write'],
        ]);
    }

    /** GET /.well-known/oauth-authorization-server */
    public function serverMetadata(): Response
    {
        if (!$this->enabled()) {
            return self::json(['error' => 'not_found'], 404);
        }

        return self::json([
            'issuer' => $this->baseUrl,
            'authorization_endpoint' => $this->baseUrl . '/oauth/authorize',
            'token_endpoint' => $this->baseUrl . '/oauth/token',
            'registration_endpoint' => $this->baseUrl . '/oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => ['read', 'write'],
        ]);
    }

    /** POST /oauth/register {client_name, redirect_uris} -> 201 {client_id, …} */
    public function register(Request $request): Response
    {
        if (!$this->enabled()) {
            return self::json(['error' => 'not_found'], 404);
        }
        if ($this->tooMany('register', $request, self::REGISTRATIONS_PER_HOUR, 3600)) {
            return self::json(['error' => 'too_many_requests'], 429);
        }
        $this->throttle?->hit('register:' . $request->remoteAddr, 3600);
        $fields = json_decode($request->body, true);
        $uris = \is_array($fields['redirect_uris'] ?? null) ? array_values(array_filter($fields['redirect_uris'], 'is_string')) : [];
        $name = \is_string($fields['client_name'] ?? null) ? $fields['client_name'] : 'MCP client';
        try {
            $id = $this->oauth->register($name, $uris);
        } catch (InvalidArgumentException) {
            return self::json(['error' => 'invalid_redirect_uri', 'error_description' => 'Claude\'s callback, http to localhost, or an address the owner allows; at most 5'], 400);
        }

        return self::json([
            'client_id' => $id,
            'client_name' => mb_substr(trim($name), 0, 60),
            'redirect_uris' => $uris,
            'grant_types' => ['authorization_code'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], 201);
    }

    /** GET /oauth/authorize: the consent screen; signed out, the sign-in first */
    public function authorize(Request $request, ?User $principal): Response
    {
        $params = $this->validated($request->query);
        if (\is_string($params)) {
            return $this->failure($request, $principal, $params);
        }
        if ($principal === null) {
            return Response::redirect($request->basePath . '/login?next=' . rawurlencode('/oauth/authorize?' . http_build_query($request->query)));
        }

        return $this->consent($request, $principal, $params, '');
    }

    /** POST /oauth/authorize: approve (with the namespaces) or deny, back to the client either way */
    public function decide(Request $request, ?User $principal): Response
    {
        parse_str($request->body, $fields);
        $fields = array_filter($fields, 'is_string');
        $params = $this->validated($fields);
        if (\is_string($params)) {
            return $this->failure($request, $principal, $params);
        }
        if ($principal === null) {
            return Response::redirect($request->basePath . '/login');
        }
        if (($fields['decision'] ?? '') !== 'approve') {
            return $this->back($params, ['error' => 'access_denied']);
        }
        $namespaces = array_values(array_filter(preg_split('/[\s,]+/', $fields['namespaces'] ?? '') ?: [], static fn (string $ns): bool => $ns !== ''));
        try {
            // A namespace the account cannot write is still fine: the token only narrows; a malformed one is not
            foreach ($namespaces as $ns) {
                new \Reporion\Auth\Grant(trim($ns, ':'), \Reporion\Auth\GrantRole::Viewer);
            }
        } catch (InvalidArgumentException) {
            return $this->consent($request, $principal, $params, t('oauth.err_namespaces'));
        }
        $this->audit->record('oauth.authorize', $principal->username, $request, extra: ['client' => $params['client']['name'], 'namespaces' => \count($namespaces), 'write' => ($fields['write'] ?? '') === '1']);

        return $this->back($params, ['code' => $this->oauth->code($principal->username, $params['client_id'], $params['redirect_uri'], $params['code_challenge'], array_map(static fn (string $ns): string => trim($ns, ':'), $namespaces), ($fields['write'] ?? '') === '1')]);
    }

    /** POST /oauth/token: an authorization code and its verifier for a token */
    public function token(Request $request): Response
    {
        if (!$this->enabled()) {
            return self::json(['error' => 'not_found'], 404);
        }
        if ($this->tooMany('token', $request, self::TOKEN_FAILURES, self::WINDOW)) {
            return self::json(['error' => 'too_many_requests'], 429);
        }
        parse_str($request->body, $f);
        $str = static fn (string $k): string => \is_string($f[$k] ?? null) ? $f[$k] : '';
        if ($str('grant_type') !== 'authorization_code') {
            return self::json(['error' => 'unsupported_grant_type'], 400);
        }
        $client = $this->oauth->client($str('client_id'));
        $grant = $client === null ? null : $this->oauth->redeem($str('code'), $str('client_id'), $str('redirect_uri'), $str('code_verifier'));
        $user = $grant === null ? null : $this->users->find($grant['username']);
        // A code is good once (RFC 6749 §4.1.2): a second use within its life is refused
        // Fail closed: with no throttle, or its note unwritable, the code is not claimed and is refused
        $reused = $grant !== null && !($this->throttle?->claim('code:' . $str('code'), 300) ?? false);
        if ($client === null || $grant === null || $reused || $user === null || !$user->active) {
            $this->throttle?->hit('token:' . $request->remoteAddr, self::WINDOW);

            return self::json(['error' => 'invalid_grant'], 400);
        }
        [, $token, $id] = $this->tokens->create($user, mb_substr('Claude: ' . ($client['name'] !== '' ? $client['name'] : 'MCP client'), 0, 80), $grant['write'] ? 'write' : 'read', $grant['namespaces'], self::TOKEN_DAYS);
        $this->audit->record('token.create', $user->username, $request, extra: ['token' => $id, 'via' => 'oauth']);

        return self::json(['access_token' => $token, 'token_type' => 'Bearer', 'scope' => $grant['write'] ? 'write' : 'read', 'expires_in' => self::TOKEN_DAYS * 86400]);
    }

    /**
     * The authorization request checked: a client of ours, one of its redirect URIs, a code
     * response, an S256 challenge. A string is the reason it is not one, shown here and never
     * sent to a redirect URI not proven the client's
     *
     * @param array<mixed> $q
     *
     * @return array{client_id: string, client: array{name: string, redirect_uris: list<string>}, redirect_uri: string, state: string, code_challenge: string}|string
     */
    private function validated(array $q): array|string
    {
        if (!$this->enabled()) {
            return 'oauth.err_disabled';
        }
        $s = static fn (string $k): string => \is_string($q[$k] ?? null) ? $q[$k] : '';
        $client = $this->oauth->client($s('client_id'));
        if ($client === null || !\in_array($s('redirect_uri'), $client['redirect_uris'], true)) {
            return 'oauth.err_client';
        }
        if ($s('response_type') !== 'code' || $s('code_challenge_method') !== 'S256' || preg_match('/^[A-Za-z0-9_-]{43}$/', $s('code_challenge')) !== 1) {
            return 'oauth.err_request';
        }

        return ['client_id' => $s('client_id'), 'client' => $client, 'redirect_uri' => $s('redirect_uri'), 'state' => $s('state'), 'code_challenge' => $s('code_challenge')];
    }

    /** @param array{client_id: string, client: array{name: string, redirect_uris: list<string>}, redirect_uri: string, state: string, code_challenge: string} $params */
    private function consent(Request $request, User $principal, array $params, string $error): Response
    {
        $html = View::page(\dirname(__DIR__, 2) . '/templates/oauth-consent.php', [
            'params' => $params,
            'error' => $error,
            'reach' => array_values(array_unique(array_map(static fn (\Reporion\Auth\Grant $g): string => $g->namespace, $principal->effectiveGrants()))),
            'isOwner' => $principal->isOwner,
            'basePath' => $request->basePath,
        ] + ChromeVars::shell($request, $principal, $this->index, ''), t('oauth.title'));

        // Never in a frame: a consent clicked through someone else's page is no consent
        return Response::html($html)->withHeader('X-Frame-Options', 'DENY')->withHeader('Content-Security-Policy', "frame-ancestors 'none'");
    }

    private function failure(Request $request, ?User $principal, string $key): Response
    {
        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/oauth-consent.php', [
            'params' => null, 'error' => t($key), 'reach' => [], 'isOwner' => false, 'basePath' => $request->basePath,
        ] + ChromeVars::shell($request, $principal, $this->index, ''), t('oauth.title')), 400);
    }

    /**
     * @param array{redirect_uri: string, state: string} $params
     * @param array<string, string>                      $answer
     */
    private function back(array $params, array $answer): Response
    {
        if ($params['state'] !== '') {
            $answer['state'] = $params['state'];
        }
        $answer['iss'] = $this->baseUrl;

        return Response::redirect($params['redirect_uri'] . (str_contains($params['redirect_uri'], '?') ? '&' : '?') . http_build_query($answer));
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data, int $status = 200): Response
    {
        return new Response($status, (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }
}
