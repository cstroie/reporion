<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

use InvalidArgumentException;
use Reporion\Auth\User;
use Reporion\Auth\UserStoreInterface;

/**
 * D35: multiple accounts, a signed session cookie carrying a username (not
 * a bare owner flag), no 2FA. The cookie is only ever a *claim* — the
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
    ) {
    }

    public function cookieName(): string
    {
        return $this->cookieName;
    }

    /**
     * Resolves the signed-in account, or null for anonymous — including
     * for a validly-signed cookie whose account was since deleted or
     * deactivated (User::$active), so disabling an account takes effect
     * immediately rather than only once its cookie expires.
     */
    public function principal(Request $request): ?User
    {
        $cookie = $request->cookie($this->cookieName);
        if ($cookie === null) {
            return null;
        }

        // Signature and expiry are checked first, and only a payload that
        // passes both is ever handed to the user store — the username in
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
            // time — reaching this catch means the record's format
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
        $payload = (string) json_encode(['username' => $username, 'exp' => time() + $this->lifetimeSeconds], JSON_THROW_ON_ERROR);
        $encodedPayload = self::base64UrlEncode($payload);
        $signature = self::base64UrlEncode(hash_hmac('sha256', $encodedPayload, $this->secret, true));

        return $encodedPayload . '.' . $signature;
    }

    /**
     * Set-Cookie header value for a successful login: HttpOnly + SameSite=Lax
     * (D35), Max-Age matching the token's own expiry.
     *
     * TODO: append "; Secure" once boot()/config can tell it's behind TLS —
     * lighttpd terminates TLS upstream (D23) so plain HTTP never reaches
     * this app in production, but nothing here currently asserts that.
     */
    public function loginCookieHeader(string $username): string
    {
        $value = rawurlencode($this->issue($username));

        return "{$this->cookieName}={$value}; Max-Age={$this->lifetimeSeconds}; Path=/; HttpOnly; SameSite=Lax";
    }

    /**
     * Set-Cookie header value that clears the session cookie (logout).
     */
    public function logoutCookieHeader(): string
    {
        return "{$this->cookieName}=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax";
    }

    private function verifiedUsername(string $cookie): ?string
    {
        $parts = explode('.', $cookie, 2);
        if (\count($parts) !== 2) {
            return null;
        }
        [$encodedPayload, $signature] = $parts;

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
