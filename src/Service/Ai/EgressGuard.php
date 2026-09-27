<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use Reporion\Exception\AiException;

/**
 * Where the assistant may send anything (storage doc §8: the egress
 * allow-list is enforced in code, not documentation). This machine and the
 * private network always; any other host only when the owner has accepted
 * that report text, without identifiers, leaves the server
 * (`ai.external_ack`). A host name is judged by the addresses it resolves
 * to, so a public server under an internal-looking name is still outside.
 * (A host allow-list next to it was dropped, 2026-09-27: the owner edits
 * the address and the list on the same form, so the acknowledgement is
 * the decision.)
 */
final class EgressGuard
{
    /** @var \Closure(string): list<string> */
    private \Closure $resolve;

    /** @param ?\Closure(string): list<string> $resolve host → its addresses (tests pass their own) */
    public function __construct(?\Closure $resolve = null)
    {
        $this->resolve = $resolve ?? static fn (string $host): array => gethostbynamel($host) ?: [];
    }

    /**
     * @throws AiException egress_denied, or bad_endpoint for a URL that is not http(s)
     */
    public function assertAllowed(string $url, bool $externalAck): void
    {
        $host = self::host($url);
        if ($this->isLocal($host)) {
            return;
        }
        if (!$externalAck) {
            throw new AiException('egress_denied', 'The AI server is outside this network and not allowed');
        }
    }

    /** Whether $url stays on this machine or the private network */
    public function isExternal(string $url): bool
    {
        return !$this->isLocal(self::host($url));
    }

    private function isLocal(string $host): bool
    {
        if ($host === 'localhost') {
            return true;
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolve)($host);
        if ($addresses === []) {
            return false;
        }
        foreach ($addresses as $ip) {
            // Public means neither private (10/8, 172.16/12, 192.168/16, fc00::/7) nor reserved (127/8, ::1, …)
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                return false;
            }
        }

        return true;
    }

    private static function host(string $url): string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !\in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            throw new AiException('bad_endpoint', 'The AI server address is not an http(s) URL');
        }

        return strtolower(trim((string) $parts['host'], '[]'));
    }
}
