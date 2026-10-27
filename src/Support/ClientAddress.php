<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * The address a request really came from, behind a reverse proxy (D56 hardening). Without a
 * proxy the connection's address is the client's. With one, `REMOTE_ADDR` is the proxy's, and the
 * client is in `X-Forwarded-For`: but that header is the caller's to write, so it is believed only
 * when the connection itself comes from a proxy the owner listed (`site.trusted_proxies` in
 * settings.yaml: addresses or CIDR ranges), and then read from the right, skipping the listed
 * proxies, so what a client put in front of the chain is never taken for its address.
 */
final class ClientAddress
{
    /**
     * @param list<string> $trusted addresses or CIDR ranges (clean())
     */
    public static function resolve(string $remote, string $forwardedFor, array $trusted): string
    {
        if ($trusted === [] || !self::listed($remote, $trusted) || trim($forwardedFor) === '') {
            return $remote;
        }
        $hops = array_map('trim', explode(',', $forwardedFor));
        for ($i = \count($hops) - 1; $i >= 0; --$i) {
            if (filter_var($hops[$i], FILTER_VALIDATE_IP) === false) {
                return $remote; // garbage in the chain: believe none of it
            }
            if (!self::listed($hops[$i], $trusted)) {
                return $hops[$i];
            }
        }

        return $remote; // every hop is one of ours
    }

    /**
     * The entries that are an address or a CIDR range, as written; anything else dropped
     *
     * @param array<mixed> $entries
     *
     * @return list<string>
     */
    public static function clean(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            if (!\is_string($entry)) {
                continue;
            }
            $entry = trim($entry);
            [$ip, $bits] = array_pad(explode('/', $entry, 2), 2, null);
            $packed = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : inet_pton($ip);
            if ($packed === false) {
                continue;
            }
            if ($bits !== null && (!ctype_digit($bits) || (int) $bits > \strlen($packed) * 8)) {
                continue;
            }
            $out[] = $entry;
        }

        return $out;
    }

    /** @param list<string> $trusted */
    private static function listed(string $ip, array $trusted): bool
    {
        $packed = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        foreach ($trusted as $entry) {
            [$net, $bits] = array_pad(explode('/', $entry, 2), 2, null);
            $netPacked = inet_pton((string) $net);
            if ($netPacked === false || \strlen($netPacked) !== \strlen($packed)) {
                continue; // an IPv4 entry never matches an IPv6 address
            }
            $bits = $bits === null ? \strlen($packed) * 8 : (int) $bits;
            $whole = intdiv($bits, 8);
            if (substr($packed, 0, $whole) !== substr($netPacked, 0, $whole)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0 || ((\ord($packed[$whole]) ^ \ord($netPacked[$whole])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
                return true;
            }
        }

        return false;
    }
}
