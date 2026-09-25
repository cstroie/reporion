<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Storage\StorageInterface;
use Reporion\Support\Diff;

/**
 * GET /{path}/compare?from=N&to=M (docs/architecture-api.md
 * Table 1: "compare two revisions server-side").
 *
 * Same read entitlement as viewing the page itself. Reuses
 * Diff::lines() — the existing LCS-based unified diff. When
 * no from/to is given, defaults to previous→current so the
 * page always renders a meaningful diff (a fresh page with one
 * revision compares that revision against itself — empty diff,
 * not an error).
 */
final class CompareController
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
    ) {
    }

    public function compare(Request $request, string $path, ?User $principal): Response
    {
        if ($this->index->findByPath($path, $principal) === null) {
            throw new PageNotFoundException();
        }

        $revlog = $this->storage->revisions($path);
        $currentRev = $revlog === [] ? 0 : (int) $revlog[array_key_last($revlog)]['n'];

        $from = self::queryInt($request, 'from');
        $to = self::queryInt($request, 'to');

        /* Defaults: previous → current */
        if ($from === null && $to === null) {
            if ($currentRev === 0) {
                $from = 0;
                $to = 0;
            } else {
                $to = $currentRev;
                $from = count($revlog) >= 2 ? (int) $revlog[count($revlog) - 2]['n'] : $currentRev;
            }
        }

        $diffLines = null;
        if ($from !== null && $to !== null && $from !== $to) {
            try {
                $diffLines = Diff::lines(
                    $this->storage->readRevision($path, $from),
                    $this->storage->readRevision($path, $to),
                );
            } catch (PageNotFoundException) {
                $diffLines = null;
            }
        }

        $revOptions = [];
        foreach ($revlog as $entry) {
            $revOptions[] = ['n' => (int) $entry['n'], 'ts' => (string) $entry['ts']];
        }

        return Response::html(View::render(
            \dirname(__DIR__, 2) . '/templates/compare.php',
            [
                'path' => $path,
                'from' => $from,
                'to' => $to,
                'diffLines' => $diffLines,
                'revOptions' => $revOptions,
                'currentRev' => $currentRev,
                'basePath' => $request->basePath,
                'railActive' => 'hist',
                'tabActive' => 'compare',
            ] + ChromeVars::forPath($principal, $path)
              + ChromeVars::worklist($this->index, $principal, $path)
              + ChromeVars::theme($request)
        ));
    }

    private static function queryInt(Request $request, string $key): ?int
    {
        $value = $request->query[$key] ?? null;

        return \is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
