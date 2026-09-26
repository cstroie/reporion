<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Exception\PageNotFoundException;
use Reporion\Exception\RevisionConflictException;
use Reporion\Http\Request;
use Reporion\Index\IndexInterface;
use Reporion\Storage\StorageInterface;

/**
 * Admin → Tags (decided 2026-09-26): renaming a tag or merging several
 * into one rewrites the `tags:` frontmatter of every page carrying it, each
 * as an ordinary new revision attributed to whoever did it, audited as
 * page.save. Tags live in the frontmatter only (invariant 1); the index's
 * page_tags follows from the saves.
 *
 * A signed report is never rewritten for this — that would turn it back
 * into a draft (D3) — the same rule as link fixups after a move. It keeps
 * its old tag and is counted in the result.
 */
final class Tags
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * @return array{changed: int, skippedSigned: int}
     *
     * @throws InvalidArgumentException for an empty or malformed new name
     */
    public function rename(string $from, string $to, string $actor, ?Request $request = null): array
    {
        return $this->merge([$from], $to, $actor, $request);
    }

    /**
     * @param list<string> $from
     *
     * @return array{changed: int, skippedSigned: int}
     *
     * @throws InvalidArgumentException for an empty or malformed target name
     */
    public function merge(array $from, string $into, string $actor, ?Request $request = null): array
    {
        $into = self::validName($into);
        $from = array_values(array_diff(array_unique($from), [$into]));
        $paths = [];
        foreach ($from as $tag) {
            foreach ($this->index->pathsWithTag($tag) as $path) {
                $paths[$path] = true;
            }
        }

        $changed = 0;
        $skippedSigned = 0;
        foreach (array_keys($paths) as $path) {
            try {
                $page = $this->storage->read($path);
            } catch (PageNotFoundException) {
                continue;
            }
            $tags = array_values(array_filter((array) ($page->frontmatter['tags'] ?? []), 'is_string'));
            if (array_intersect($tags, $from) === []) {
                continue;
            }
            if ($page->status === 'signed') {
                ++$skippedSigned;
                continue;
            }
            $frontmatter = $page->frontmatter;
            $frontmatter['tags'] = array_values(array_unique(array_map(static fn (string $tag): string => \in_array($tag, $from, true) ? $into : $tag, $tags)));
            try {
                $saved = $this->storage->save($path, $frontmatter, $page->body, $page->rev, $actor, 'tags: ' . implode(', ', $from) . ' → ' . $into);
            } catch (RevisionConflictException) {
                // Saved by someone else in between: it keeps the old tag, rerun to catch it
                continue;
            }
            $this->audit->record('page.save', $actor, $request, $saved->pid, $saved->path, $saved->rev, extra: ['reason' => 'tag-rename']);
            ++$changed;
        }

        return ['changed' => $changed, 'skippedSigned' => $skippedSigned];
    }

    private static function validName(string $tag): string
    {
        $tag = trim($tag);
        if ($tag === '' || mb_strlen($tag) > 64 || preg_match('/[,\[\]{}\r\n]/', $tag) === 1) {
            throw new InvalidArgumentException(t('admin.tags.err_name'));
        }

        return $tag;
    }
}
