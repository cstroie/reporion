<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Auth;

use DateTimeImmutable;
use InvalidArgumentException;
use Reporion\Exception\InvalidTokenException;

/**
 * API tokens (roadmap phase 13): a secret a user creates on their own
 * profile page, sent as `Authorization: Bearer rpn_…` to /api/v1 and
 * /export (and /mcp, D56). A token is its account: the same grants, read from the account
 * on every request: narrowed by its scope: `read` (GET only) or `write`.
 *
 * The token is `rpn_{base64url(username)}.{id}.{secret}`: the username
 * finds the account without scanning every file, the id the token in it,
 * and only sha256(secret) is ever stored (docs/FORMATS.md §10). Shown once,
 * at creation.
 */
final class ApiTokens
{
    public const SCOPES = ['read', 'write'];
    private const PREFIX = 'rpn_';

    public function __construct(private readonly UserStoreInterface $users)
    {
    }

    /**
     * A new token for $user: the account as saved, and the token: the only
     * time it exists in full.
     *
     * @return array{0: User, 1: string, 2: string} the account, the token, its id
     */
    /** How long a token may be made to last on /profile, in days; null: until revoked */
    public const LIFETIMES = [30, 90, 365, null];

    /**
     * @param list<string> $namespaces what the token is limited to (D56); empty: the whole account
     * @param ?int         $days       how long it works (hardening, 2026-10-27); null: until revoked
     */
    public function create(User $user, string $name, string $scope, array $namespaces = [], ?int $days = null): array
    {
        if ($days !== null && ($days < 1 || $days > 3650)) {
            throw new InvalidArgumentException('A token lasts 1 to 3650 days');
        }
        $namespaces = array_values(array_unique(array_filter(array_map('trim', $namespaces), static fn (string $ns): bool => $ns !== '')));
        foreach ($namespaces as $ns) {
            new Grant($ns, GrantRole::Viewer); // throws for a malformed namespace
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw new InvalidArgumentException('A token needs a name of up to 80 characters');
        }
        if (!\in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException('Unknown token scope');
        }
        $id = self::id();
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $record = [
            'id' => $id,
            'name' => $name,
            'scope' => $scope,
            'created' => (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:sP'),
            'last_used' => null,
            'hash' => hash('sha256', $secret),
        ];
        if ($namespaces !== []) {
            $record['namespaces'] = $namespaces;
        }
        if ($days !== null) {
            $record['expires'] = (new DateTimeImmutable('now'))->modify('+' . $days . ' days')->format('Y-m-d\TH:i:sP');
        }
        $saved = $this->users->save($user->with(tokens: [...$user->tokens, $record]));

        return [$saved, self::PREFIX . rtrim(strtr(base64_encode($user->username), '+/', '-_'), '=') . '.' . $id . '.' . $secret, $id];
    }

    /** $user without token $id; null when there is no such token */
    public function revoke(User $user, string $id): ?User
    {
        $kept = array_values(array_filter($user->tokens, static fn (array $t): bool => $t['id'] !== $id));
        if (\count($kept) === \count($user->tokens)) {
            return null;
        }

        return $this->users->save($user->with(tokens: $kept));
    }

    /**
     * The account a bearer token stands for, when it may make a $method
     * request. Throws for anything else: a malformed, unknown or revoked
     * token, an inactive account (401), a read token writing (403), so a
     * bad token is never quietly an anonymous caller.
     *
     * @throws InvalidTokenException
     */
    public function authenticate(string $token, string $method, string $address = ''): User
    {
        if (preg_match('/^rpn_([A-Za-z0-9_-]+)\.([a-z2-7]{8})\.([A-Za-z0-9_-]{43})$/', $token, $m) !== 1) {
            throw new InvalidTokenException();
        }
        $username = base64_decode(strtr($m[1], '-_', '+/'), true);
        try {
            $user = \is_string($username) ? $this->users->find($username) : null;
        } catch (InvalidArgumentException) {
            $user = null;
        }
        if ($user === null || !$user->active) {
            throw new InvalidTokenException();
        }
        $hash = hash('sha256', $m[3]);
        foreach ($user->tokens as $t) {
            if ($t['id'] === $m[2] && hash_equals($t['hash'], $hash)) {
                // Past its expiry it is as good as revoked (hardening)
                if (isset($t['expires']) && strtotime($t['expires']) < time()) {
                    throw new InvalidTokenException();
                }
                if ($t['scope'] !== 'write' && !\in_array($method, ['GET', 'HEAD'], true)) {
                    throw new InvalidTokenException(scope: true);
                }

                $user = $this->touch($user, $t['id'], $address);

                // A token limited to namespaces (D56) is the account narrowed to them, after the touch is saved
                return isset($t['namespaces']) ? $user->narrowedTo($t['namespaces']) : $user;
            }
        }
        throw new InvalidTokenException();
    }

    /**
     * When a token was last used, to the day, and from which address (hardening, shown on
     * /profile): at most one write a day per token, or when the address changes
     */
    private function touch(User $user, string $id, string $address): User
    {
        $today = (new DateTimeImmutable('now'))->format('Y-m-d');
        $tokens = $user->tokens;
        foreach ($tokens as $i => $t) {
            if ($t['id'] === $id && ($t['last_used'] !== $today || ($address !== '' && ($t['last_addr'] ?? '') !== $address))) {
                $tokens[$i]['last_used'] = $today;
                if ($address !== '') {
                    $tokens[$i]['last_addr'] = mb_substr($address, 0, 45);
                }

                return $this->users->save($user->with(tokens: $tokens));
            }
        }

        return $user;
    }

    private static function id(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
        $id = '';
        foreach (str_split(random_bytes(8)) as $byte) {
            $id .= $alphabet[\ord($byte) & 31];
        }

        return $id;
    }
}
