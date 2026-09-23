<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

/**
 * Wire shape for POST /render (docs/architecture-api.md §"Render"):
 * { html, toc, warnings }.
 */
final class RenderResult
{
    /**
     * @param list<array{level: int, text: string, slug: string}> $toc
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly string $html,
        public readonly array $toc,
        public readonly array $warnings,
    ) {
    }
}
