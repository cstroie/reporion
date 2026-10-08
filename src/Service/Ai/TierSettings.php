<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

/**
 * What one model alias (lite, normal, expert) of one server sends (roadmap
 * phase 33a): its model and the request parameters the owner filled in —
 * null is "not sent", the server's own default applies (newer Claude models
 * refuse temperature/top_p/top_k outright). `extra` is merged into the
 * request body last, for what a server needs beyond these
 * (`{"reasoning_effort": "low"}`, OpenRouter's `{"reasoning": {...}}`).
 */
final class TierSettings
{
    /** Request keys `extra` may never set: the request itself is ours */
    public const RESERVED = ['model', 'messages', 'stream', 'stream_options'];

    /** @param array<string, mixed> $extra */
    public function __construct(
        public readonly string $model,
        public readonly ?float $temperature = null,
        public readonly ?float $topP = null,
        public readonly ?int $topK = null,
        public readonly ?float $minP = null,
        public readonly int $maxTokens = 0,
        public readonly array $extra = [],
    ) {
    }

    /**
     * The parameters as the request carries them, blank ones left out
     *
     * @return array<string, mixed>
     */
    public function params(): array
    {
        return array_filter([
            'temperature' => $this->temperature,
            'top_p' => $this->topP,
            'top_k' => $this->topK,
            'min_p' => $this->minP,
            'max_tokens' => $this->maxTokens > 0 ? $this->maxTokens : null,
        ], static fn (mixed $v): bool => $v !== null) + array_diff_key($this->extra, array_flip(self::RESERVED));
    }
}
