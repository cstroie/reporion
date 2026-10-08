<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use Generator;
use Reporion\Exception\AiException;

/**
 * A server with a fallback (roadmap phase 34f): the request goes to the
 * first; when it cannot be reached, times out or fails with a 5xx *before
 * any text came back*, the same prompt goes to the second, once. A refusal,
 * a 4xx, an identifier leak or a failure mid-answer is never retried
 * elsewhere — the person sees what happened. The second server keeps its
 * own egress rule (its provider checks it), so a local → external fallback
 * still needs that server's acknowledgement.
 */
final class FailoverProvider implements ProviderInterface
{
    private ProviderInterface $used;

    /** @var ?array{from: string, to: string, reason: string} */
    private ?array $failover = null;

    public function __construct(
        private readonly ProviderInterface $primary,
        private readonly ProviderInterface $secondary,
        private readonly string $primaryName,
        private readonly string $secondaryName,
    ) {
        $this->used = $primary;
    }

    public function stream(Prompt $prompt): Generator
    {
        $this->used = $this->primary;
        $this->failover = null;
        $started = false;
        try {
            foreach ($this->primary->stream($prompt) as $piece) {
                $started = true;
                yield $piece;
            }

            return;
        } catch (AiException $e) {
            if ($started || !self::movesOn($e)) {
                throw $e;
            }
            $this->failover = ['from' => $this->primaryName, 'to' => $this->secondaryName, 'reason' => $e->reason . ($e->status !== null ? ' ' . $e->status : '')];
        }
        $this->used = $this->secondary;
        yield from $this->secondary->stream($prompt);
    }

    /** Only a server that is not there, or broken on its side */
    public static function movesOn(AiException $e): bool
    {
        return \in_array($e->reason, ['unreachable', 'timeout'], true) || ($e->status !== null && $e->status >= 500);
    }

    /** @return ?array{from: string, to: string, reason: string} what the last stream() did, when it moved on */
    public function failover(): ?array
    {
        return $this->failover;
    }

    public function usage(): array
    {
        return $this->used->usage();
    }

    public function models(): array
    {
        return $this->primary->models();
    }

    public function describe(string $tier = AiConfig::DEFAULT_TIER): string
    {
        return $this->used->describe($tier);
    }
}
