<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

/**
 * D13: a single owner, argon2id password, a signed session cookie — no
 * user table, no roles. isOwner() is the only authorization question this
 * app ever asks. Pure logic (no $_COOKIE reads, no header() calls), so it
 * is testable without a real request; the login HTTP route that calls
 * issue() is a later step, not built here.
 */
final class Session
{
    public function __construct(
        private readonly string $secret,
        private readonly string $cookieName,
        private readonly int $lifetimeSeconds,
    ) {
    }

    public function cookieName(): string
    {
        return $this->cookieName;
    }

    public function isOwner(Request $request): bool
    {
        $cookie = $request->cookie($this->cookieName);

        return $cookie !== null && $this->verify($cookie);
    }

    /**
     * The signed cookie value to set after a successful login.
     */
    public function issue(): string
    {
        $payload = (string) json_encode(['owner' => true, 'exp' => time() + $this->lifetimeSeconds], JSON_THROW_ON_ERROR);
        $encodedPayload = self::base64UrlEncode($payload);
        $signature = self::base64UrlEncode(hash_hmac('sha256', $encodedPayload, $this->secret, true));

        return $encodedPayload . '.' . $signature;
    }

    /**
     * Set-Cookie header value for a successful login: HttpOnly + SameSite=Lax
     * (D13), Max-Age matching the token's own expiry.
     *
     * TODO: append "; Secure" once boot()/config can tell it's behind TLS —
     * lighttpd terminates TLS upstream (D23) so plain HTTP never reaches
     * this app in production, but nothing here currently asserts that.
     */
    public function loginCookieHeader(): string
    {
        $value = rawurlencode($this->issue());

        return "{$this->cookieName}={$value}; Max-Age={$this->lifetimeSeconds}; Path=/; HttpOnly; SameSite=Lax";
    }

    /**
     * Set-Cookie header value that clears the session cookie (logout).
     */
    public function logoutCookieHeader(): string
    {
        return "{$this->cookieName}=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax";
    }

    private function verify(string $cookie): bool
    {
        $parts = explode('.', $cookie, 2);
        if (\count($parts) !== 2) {
            return false;
        }
        [$encodedPayload, $signature] = $parts;

        $expected = self::base64UrlEncode(hash_hmac('sha256', $encodedPayload, $this->secret, true));
        if (!hash_equals($expected, $signature)) {
            return false;
        }

        $decoded = self::base64UrlDecode($encodedPayload);
        $payload = $decoded !== null ? json_decode($decoded, true) : null;
        if (!\is_array($payload) || ($payload['owner'] ?? false) !== true) {
            return false;
        }

        $exp = $payload['exp'] ?? 0;

        return \is_int($exp) && $exp > time();
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
