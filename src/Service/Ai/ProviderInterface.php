<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use Generator;
use Reporion\Exception\AiException;

/**
 * An AI model the assistant can ask (D15). Only a Prompt from
 * Context::build() is ever given to it — that is the chokepoint.
 */
interface ProviderInterface
{
    /**
     * The answer as it streams: each yielded string is the next piece of text.
     *
     * @return Generator<int, string>
     *
     * @throws AiException
     */
    public function stream(Prompt $prompt): Generator;

    /** @return array{prompt_tokens?: int, completion_tokens?: int} what the last stream() used, when the server said */
    public function usage(): array;

    /** @return list<string> the models the server offers */
    public function models(): array;

    /** A short description for the rail and audit: host and model, never the key */
    public function describe(): string;
}
