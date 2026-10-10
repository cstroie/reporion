<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

/**
 * A prompt ready to send. Only Context::build() makes one (D15): by the
 * time it exists it has been de-identified and checked. `contextSet` says
 * what went in — for the rail's "Context sent" and the audit line — never
 * the text itself. A prompt with a `reply` is answered without a model:
 * there was nothing worth sending (Context::NO_HISTORY).
 */
final class Prompt
{
    /** @param list<string> $contextSet */
    public function __construct(
        public readonly string $system,
        public readonly string $user,
        public readonly array $contextSet,
        /** The model alias the action asked for (AiConfig::TIERS); the provider maps it to a model */
        public readonly string $tier = AiConfig::DEFAULT_TIER,
        /** The answer already known, sent instead of asking the provider */
        public readonly ?string $reply = null,
        /** The action's token cap (Action::$maxTokens); 0: the server's */
        public readonly int $maxTokens = 0,
        /** The action's own timeout in seconds (Action::$timeout); 0: the server's */
        public readonly int $timeout = 0,
    ) {
    }
}
