<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

use Reporion\Exception\PageNotFoundException;
use Reporion\Service\Markdown\CitationSource;
use Reporion\Storage\StorageInterface;
use Reporion\Support\CitationKey;
use Reporion\Support\LibrarySource;
use Reporion\Support\SourceNotice;

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

    /** @var array<string, ?string> */
    private array $notices = [];

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
            // A source merged into another (Library::merge): its old key still names the one it was merged into
            $target = $this->storage->redirectTarget($this->path($key));
            try {
                $frontmatter = $target !== null && str_starts_with($target, $this->namespace . ':') ? $this->storage->read($target)->frontmatter : null;
            } catch (PageNotFoundException) {
                $frontmatter = null;
            }
            if ($frontmatter === null) {
                return $this->seen[$key] = null;
            }
        }

        $this->notices[$key] = LibrarySource::isSource($frontmatter) ? SourceNotice::clean($frontmatter['notice'] ?? null)['status'] ?? null : null;

        return $this->seen[$key] = LibrarySource::isSource($frontmatter) && \is_array($frontmatter['csl'] ?? null) ? $frontmatter['csl'] : null;
    }

    public function notice(string $key): ?string
    {
        $this->record($key);

        return $this->notices[$key] ?? null;
    }

    public function path(string $key): string
    {
        return $this->namespace . ':' . $key;
    }
}
