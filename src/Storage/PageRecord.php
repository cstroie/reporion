<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Storage;

/**
 * A page as read from disk: current.md split into frontmatter/body, plus the
 * system state from meta.json. Never the only copy of anything — always
 * reconstructible from data/pages/ (CLAUDE.md invariant 1).
 */
final class PageRecord
{
    /**
     * @param array<string, mixed> $frontmatter
     * @param array<int, array<string, mixed>> $revlog
     * @param array<string, mixed> $meta full decoded meta.json, for fields not otherwise exposed
     */
    public function __construct(
        public readonly string $pid,
        public readonly string $path,
        public readonly int $rev,
        public readonly string $status,
        public readonly string $visibility,
        public readonly array $frontmatter,
        public readonly string $body,
        public readonly array $revlog,
        public readonly array $meta,
    ) {
    }
}
