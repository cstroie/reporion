<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

use Reporion\Exception\PageNotFoundException;
use Reporion\Service\Markdown\CitationSource;
use Reporion\Storage\StorageInterface;
use Reporion\Support\CitationKey;
use Reporion\Support\LibrarySource;

/**
 * The library as Render's citation source: `[@key]` is the page `{library}:{key}` (docs/library.md).
 * Only the record (`csl`) is returned, to any reader of the citing page (owner, 2026-10-30:
 * bibliographic facts are public, the text and the original are not); a page that is no source
 * is no key.
 */
final class Citations implements CitationSource
{
    /** @var array<string, ?array<string, mixed>> */
    private array $seen = [];

    public function __construct(private readonly StorageInterface $storage, private readonly string $namespace)
    {
    }

    public function record(string $key): ?array
    {
        if (!CitationKey::isValid($key)) {
            return null;
        }
        if (\array_key_exists($key, $this->seen)) {
            return $this->seen[$key];
        }
        try {
            $frontmatter = $this->storage->read($this->path($key))->frontmatter;
        } catch (PageNotFoundException) {
            return $this->seen[$key] = null;
        }

        return $this->seen[$key] = LibrarySource::isSource($frontmatter) && \is_array($frontmatter['csl'] ?? null) ? $frontmatter['csl'] : null;
    }

    public function path(string $key): string
    {
        return $this->namespace . ':' . $key;
    }
}
