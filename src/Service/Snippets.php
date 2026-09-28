<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Index\IndexInterface;
use Reporion\Storage\StorageInterface;
use Reporion\Support\MetaText;
use Reporion\Support\ReportPath;
use Reporion\Support\Templates;

/**
 * Snippets — D24's expansion macros, as pages (phase 11, decided
 * 2026-09-26): `templates:snippets:{name}` is `;name` in every editor,
 * `templates:snippets:{modality ns}:{name}` only in that modality's
 * reports, where it wins over a shared one of the same name. The listing
 * goes through the index, so grants and visibility apply (invariant 6);
 * the text comes from disk (invariant 1). Deeper pages are not snippets.
 */
final class Snippets
{
    public const NS = Templates::NS . ':snippets';

    public function __construct(
        private readonly IndexInterface $index,
        private readonly StorageInterface $storage,
    ) {
    }

    /**
     * The snippets offered in the editor of $path, by name.
     *
     * @return list<array{name: string, title: string, body: string, modality: bool}>
     */
    public function forPage(string $path, ?User $principal): array
    {
        $segments = explode(':', $path);
        $modalityNs = ReportPath::isReport($path) && isset($segments[1]) ? self::NS . ':' . $segments[1] : null;

        $byName = [];
        foreach ($this->index->listRecent($principal, ['ns' => self::NS], 500) as $row) {
            $rowPath = (string) $row['path'];
            $rowSegments = explode(':', $rowPath);
            $name = strtolower((string) end($rowSegments));
            if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $name) !== 1) {
                continue;
            }
            $shared = \count($rowSegments) === 3;
            $ofModality = $modalityNs !== null && str_starts_with($rowPath, $modalityNs . ':') && \count($rowSegments) === 4;
            if (!$shared && !$ofModality) {
                continue;
            }
            if ($shared && isset($byName[$name])) {
                continue; // the modality's already won
            }
            try {
                $body = rtrim($this->storage->read($rowPath)->body);
            } catch (PageNotFoundException) {
                continue;
            }
            $byName[$name] = [
                'name' => $name,
                'title' => MetaText::text($row['title'] ?? null) !== '' ? MetaText::text($row['title']) : $name,
                'body' => $body,
                'modality' => $ofModality,
            ];
        }
        ksort($byName);

        return array_values($byName);
    }

    /** Whether a page is a snippet or their namespace — kept out of template pickers. */
    public static function isSnippetPath(string $path): bool
    {
        return $path === self::NS || str_starts_with($path, self::NS . ':');
    }
}
