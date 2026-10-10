<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Auth\User;
use Reporion\Index\IndexInterface;
use Reporion\Support\Visibility;

/**
 * The visibility a new page starts with (phase 16, F): the one its nearest
 * ancestor namespace's description page carries — the page named like the
 * namespace, else `{ns}:_index` — or `private` when none does.
 *
 * Only a hint for the editor's picker (D16): the acknowledgement that
 * publishing needs is asked as ever, and every creator that shows no picker
 * (API, CLI, the bare-document form, copies, joins) keeps `private`.
 * Descriptions are read through the index with the caller's principal, so an
 * ancestor the caller cannot see is skipped as if absent (invariant 6) — the
 * walk goes on to the next one and nothing says that it exists.
 */
final class NamespaceDefaults
{
    public function __construct(private readonly IndexInterface $index)
    {
    }

    /**
     * @return array{visibility: string, from: ?string} the level and the
     *         description page it came from (null: the `private` fallback)
     */
    public function forNewPage(string $path, ?User $principal): array
    {
        $levels = [];
        $parts = explode(':', trim($path, ':'));
        array_pop($parts);
        while ($parts !== []) {
            $ns = implode(':', $parts);
            $levels[] = [$ns, $ns . ':_index'];
            array_pop($parts);
        }
        $levels[] = ['_index'];
        $rows = $this->index->findByPaths(array_merge(...$levels), $principal);
        foreach ($levels as $candidates) {
            foreach ($candidates as $candidate) {
                $level = isset($rows[$candidate]) ? (string) ($rows[$candidate]['visibility'] ?? '') : '';
                if (\in_array($level, Visibility::LEVELS, true)) {
                    return ['visibility' => $level, 'from' => $candidate];
                }
            }
        }

        return ['visibility' => 'private', 'from' => null];
    }
}
