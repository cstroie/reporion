<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Auth;

use InvalidArgumentException;

/**
 * OAuth 2.1 for the MCP server (D56, phase 3): what claude.ai and Claude Desktop need to connect
 * on their own, authorization code + PKCE (S256 only) with dynamic client registration. Nothing is
 * stored for it: a client id is its registration (name and redirect URIs) signed with the session
 * secret, an authorization code the consent signed the same way and good for 60 seconds. The
 * token it ends in is an ordinary API token (ApiTokens, read, limited to the namespaces ticked),
 * listed and revoked on /profile like any other. Without a configured `site.base_url` there is no
 * issuer, so none of it is offered (a Host header is the caller's to choose).
 */
final class OAuth
{
    private const CODE_TTL = 60;

    /** $secret is never empty here: without one, Kernel offers no OAuth (the controller's base URL is empty) */
    public function __construct(private readonly string $secret)
    {
    }

    /**
     * A client id for a registration: its name and redirect URIs, signed
     *
     * @param list<string> $redirectUris
     */
    public function register(string $name, array $redirectUris): string
    {
        $name = mb_substr(trim($name), 0, 60);
        if ($redirectUris === [] || \count($redirectUris) > 5) {
            throw new InvalidArgumentException('invalid_redirect_uri');
        }
        foreach ($redirectUris as $uri) {
            if (!self::acceptableRedirect($uri)) {
                throw new InvalidArgumentException('invalid_redirect_uri');
            }
        }

        return $this->sign('client', ['n' => $name, 'r' => array_values($redirectUris)]);
    }

    /**
     * The registration a client id stands for, or null when it is not one of ours
     *
     * @return ?array{name: string, redirect_uris: list<string>}
     */
    public function client(string $clientId): ?array
    {
        $c = $this->verify('client', $clientId);
        if ($c === null || !\is_string($c['n'] ?? null) || !\is_array($c['r'] ?? null)) {
            return null;
        }

        return ['name' => $c['n'], 'redirect_uris' => array_values(array_filter($c['r'], 'is_string'))];
    }

    /**
     * An authorization code for what the user approved
     *
     * @param list<string> $namespaces
     */
    public function code(string $username, string $clientId, string $redirectUri, string $challenge, array $namespaces): string
    {
        return $this->sign('code', ['u' => $username, 'c' => $clientId, 'r' => $redirectUri, 'p' => $challenge, 'ns' => $namespaces, 'exp' => time() + self::CODE_TTL]);
    }

    /**
     * What an authorization code grants, when it is ours, unexpired, for this client and redirect,
     * and the verifier matches its challenge; else null
     *
     * @return ?array{username: string, namespaces: list<string>}
     */
    public function redeem(string $code, string $clientId, string $redirectUri, string $verifier): ?array
    {
        $c = $this->verify('code', $code);
        if ($c === null || !\is_int($c['exp'] ?? null) || $c['exp'] < time()
            || ($c['c'] ?? null) !== $clientId || ($c['r'] ?? null) !== $redirectUri || !\is_string($c['u'] ?? null)
            || preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) !== 1
            || !hash_equals((string) ($c['p'] ?? ''), self::challenge($verifier))) {
            return null;
        }

        return ['username' => $c['u'], 'namespaces' => array_values(array_filter((array) ($c['ns'] ?? []), 'is_string'))];
    }

    /** The S256 challenge of a verifier (RFC 7636) */
    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** https anywhere, http only to this machine (a desktop client's loopback listener) */
    public static function acceptableRedirect(string $uri): bool
    {
        $parts = parse_url($uri);
        if (!\is_array($parts) || isset($parts['fragment']) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        return $parts['scheme'] === 'https'
            || ($parts['scheme'] === 'http' && \in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true));
    }

    /** @param array<string, mixed> $data */
    private function sign(string $purpose, array $data): string
    {
        $payload = self::b64((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $payload . '.' . self::b64(hash_hmac('sha256', 'oauth-' . $purpose . '.' . $payload, $this->secret, true));
    }

    /** @return ?array<string, mixed> */
    private function verify(string $purpose, string $signed): ?array
    {
        $parts = explode('.', $signed);
        if (\count($parts) !== 2 || !hash_equals(self::b64(hash_hmac('sha256', 'oauth-' . $purpose . '.' . $parts[0], $this->secret, true)), $parts[1])) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);

        return \is_array($data) ? $data : null;
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
