<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

use InvalidArgumentException;
use Reporion\Auth\ApiTokens;
use Reporion\Auth\User;
use Reporion\Auth\UserStoreInterface;
use Reporion\Exception\InvalidTokenException;
use Reporion\Exception\SessionSecretMissingException;

/**
 * D35: multiple accounts, a signed session cookie carrying a username (not
 * a bare owner flag), no 2FA. The cookie is only ever a *claim*: the
 * account record in UserStoreInterface is what's actually trusted, so
 * every resolution re-reads it, which is also what makes a role change or
 * a disabled account (`User::$active`) take effect on the very next
 * request rather than only after the cookie itself expires.
 *
 * principal() is the one place that turns a request into a User; every
 * other authorization question (isOwner(), and later canRead()/canWrite()
 * once ACL enforcement lands) is a pure function of what principal()
 * returns. Pure logic otherwise (no header() calls), so it is testable
 * without a real request.
 */
final class Session
{
    public function __construct(
        private readonly string $secret,
        private readonly string $cookieName,
        private readonly int $lifetimeSeconds,
        private readonly UserStoreInterface $users,
        private readonly ?ApiTokens $tokens = null,
    ) {
    }

    public function cookieName(): string
    {
        return $this->cookieName;
    }

    /**
     * Resolves the signed-in account, or null for anonymous: including
     * for a validly-signed cookie whose account was since deleted or
     * deactivated (User::$active), so disabling an account takes effect
     * immediately rather than only once its cookie expires.
     */
    /**
     * The bearer token a request carries: `Authorization: Bearer …`, or on /mcp also
     * `X-Access-Token: …` (D56), so lighttpd can refuse an /mcp request without one before PHP.
     * '' when there is none; null when the Authorization header is there but not a bearer token.
     */
    private static function bearer(Request $request): ?string
    {
        if ($request->authorization !== '') {
            return preg_match('/^Bearer[ \t]+(\S+)$/i', trim($request->authorization), $m) === 1 ? $m[1] : null;
        }

        return $request->path === '/mcp' ? trim($request->accessToken) : '';
    }

    public function principal(Request $request): ?User
    {
        // /mcp (D56) is a bearer token's only: a cookie never drives it, so no page can post to it as you
        $user = $request->path === '/mcp' ? null : $this->cookiePrincipal($request);
        if ($user !== null) {
            return $user;
        }

        // An API bearer token (roadmap phase 13), on the API and exports only
        // never on a screen, so a token cannot drive a form. A token that
        // cannot be used throws: it is never quietly an anonymous caller.
        $bearer = self::bearer($request);
        if ($this->tokens !== null && $bearer !== '' && self::tokenRoute($request->path)) {
            if ($bearer === null) {
                throw new InvalidTokenException();
            }

            // /mcp is read only (D56, phase 1): its POST carries a question, so a read token may use it
            return $this->tokens->authenticate($bearer, $request->path === '/mcp' ? 'GET' : $request->method, $request->remoteAddr);
        }

        return null;
    }

    /**
     * Whether the bearer token of $request is a write token (D56, phase 4): /mcp lets a read token
     * in, and offers its write tools only to a write one
     */
    public function tokenWrites(Request $request): bool
    {
        $bearer = self::bearer($request);
        if ($this->tokens === null || $bearer === null || $bearer === '') {
            return false;
        }
        try {
            $this->tokens->authenticate($bearer, 'POST', $request->remoteAddr);

            return true;
        } catch (InvalidTokenException) {
            return false;
        }
    }

    /** The routes a bearer token is good for: the JSON API, the exports and the MCP server (D56) */
    public static function tokenRoute(string $path): bool
    {
        return str_starts_with($path, '/api/v1/') || str_starts_with($path, '/export/') || $path === '/mcp';
    }

    private function cookiePrincipal(Request $request): ?User
    {
        $cookie = $request->cookie($this->cookieName);
        if ($cookie === null) {
            return null;
        }

        // Signature and expiry are checked first, and only a payload that
        // passes both is ever handed to the user store: the username in
        // an unsigned or expired cookie is attacker-controlled and must
        // never reach find().
        $username = $this->verifiedUsername($cookie);
        if ($username === null) {
            return null;
        }

        try {
            $user = $this->users->find($username);
        } catch (InvalidArgumentException) {
            // A cookie this app issued always carries a username that
            // passed FlatFileUserStore's validation at account-creation
            // time: reaching this catch means the record's format
            // changed underneath an existing cookie, not an attack.
            return null;
        }

        return $user !== null && $user->active ? $user : null;
    }

    public function isOwner(Request $request): bool
    {
        return $this->principal($request)?->isOwner ?? false;
    }

    /**
     * The signed cookie value to set after a successful login.
     */
    public function issue(string $username): string
    {
        // An empty key is a key everyone knows: refuse to sign rather than
        // hand out a cookie anyone could have minted.
        if (!$this->hasSecret()) {
            throw new SessionSecretMissingException();
        }

        $payload = (string) json_encode(['username' => $username, 'exp' => time() + $this->lifetimeSeconds], JSON_THROW_ON_ERROR);
        $encodedPayload = self::base64UrlEncode($payload);
        $signature = self::base64UrlEncode(hash_hmac('sha256', $encodedPayload, $this->secret, true));

        return $encodedPayload . '.' . $signature;
    }

    /**
     * Set-Cookie header value for a successful login: HttpOnly + SameSite=Lax
     * (D35). No Max-Age or Expires: a browser session cookie, dropped when the
     * browser closes; the token's signed `exp` still ends it after the lifetime.
     *
     * Secure when the request arrived over HTTPS (Request::$secure. HTTPS
     * or X-Forwarded-Proto from the TLS proxy), never from config: a wrong
     * config value must not lock plain-http users out of signing in.
     */
    public function loginCookieHeader(string $username, bool $secure = false): string
    {
        $value = rawurlencode($this->issue($username));

        return "{$this->cookieName}={$value}; Path=/; HttpOnly; SameSite=Lax" . ($secure ? '; Secure' : '');
    }

    /**
     * Set-Cookie header value that clears the session cookie (logout).
     */
    public function logoutCookieHeader(bool $secure = false): string
    {
        return "{$this->cookieName}=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax" . ($secure ? '; Secure' : '');
    }

    private function verifiedUsername(string $cookie): ?string
    {
        $parts = explode('.', $cookie, 2);
        if (\count($parts) !== 2) {
            return null;
        }
        [$encodedPayload, $signature] = $parts;
        if (!$this->hasSecret()) {
            return null;
        }

        $expected = self::base64UrlEncode(hash_hmac('sha256', $encodedPayload, $this->secret, true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $decoded = self::base64UrlDecode($encodedPayload);
        $payload = $decoded !== null ? json_decode($decoded, true) : null;
        if (!\is_array($payload) || !\is_string($payload['username'] ?? null) || $payload['username'] === '') {
            return null;
        }

        $exp = $payload['exp'] ?? 0;
        if (!\is_int($exp) || $exp <= time()) {
            return null;
        }

        return $payload['username'];
    }

    private function hasSecret(): bool
    {
        return trim($this->secret) !== '';
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): ?string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded !== false ? $decoded : null;
    }
}
