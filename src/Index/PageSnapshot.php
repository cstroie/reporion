<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Index;

/**
 * Everything an IndexInterface needs to derive its own row shape from one
 * page write. Deliberately raw (full frontmatter array, not typed columns):
 * mapping frontmatter to indexed columns is the index driver's job
 * (CLAUDE.md D9 — storage and index are drivers, not each other's business),
 * and migrations/001_init.sql is the only place that column shape is defined.
 */
final class PageSnapshot
{
    /**
     * @param array<string, mixed> $frontmatter
     * @param list<string>         $media
     */
    public function __construct(
        public readonly string $pid,
        public readonly string $path,
        public readonly string $ns,
        public readonly int $rev,
        public readonly string $status,
        public readonly string $visibility,
        public readonly array $frontmatter,
        public readonly string $body,
        public readonly int $bytes,
        public readonly int $mtime,
        public readonly string $bodySha,
        public readonly string $updated,
        public readonly string $updatedBy,
        public readonly ?string $note,
        public readonly string $kind,
        // Files attached to the page (its media.json), as "{sha256}.{ext}"
        public readonly array $media = [],
    ) {
    }
}
