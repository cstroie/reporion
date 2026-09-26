<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Audit\AuditLog;
use Reporion\Service\FrontmatterRepair;
use Reporion\Storage\FlatFile;
use Reporion\Support\DocumentFormat;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Throwable;

/**
 * bin/reporion pages:check-frontmatter [--repair --actor=<username>]
 *
 * Lists pages whose current frontmatter the editor autosave flattened
 * before 2026-09-26 (Service\FrontmatterRepair), with the last intact
 * revision. --repair writes one new revision per page: the intact
 * frontmatter plus the edits made since, and the current body. A signed
 * page is listed, never repaired here — a new revision would need signing
 * again (D3); correct it in the editor and re-sign. Pages are named by pid,
 * never by path (invariant 8).
 */
final class PagesCheckFrontmatterCommand implements CommandInterface
{
    public function __construct(
        private readonly FlatFile $storage,
        private readonly AuditLog $audit,
    ) {
    }

    public function run(array $args, Output $output): int
    {
        $repair = \in_array('--repair', $args, true);
        $actor = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--actor=') && \strlen($arg) > 8) {
                $actor = substr($arg, 8);
            }
        }
        if ($repair && $actor === null) {
            $output->error('--repair needs --actor=<username>: the repair revisions are attributed to them');

            return 1;
        }

        $damaged = $repaired = $signed = $unrecoverable = 0;
        foreach ($this->storage->allPaths() as $path) {
            try {
                $page = $this->storage->read($path);
            } catch (Throwable) {
                continue;
            }
            $damage = FrontmatterRepair::damage($page->frontmatter);
            if ($damage === []) {
                continue;
            }
            ++$damaged;
            $good = $this->lastIntact($path, $page->rev);
            $output->line(\sprintf(
                'pid %s rev %d%s: %s; last intact rev %s',
                $page->pid,
                $page->rev,
                $page->status === 'signed' ? ' (signed)' : '',
                implode(', ', $damage),
                $good === null ? 'none' : (string) $good[0],
            ));

            if ($good === null) {
                ++$unrecoverable;
                continue;
            }
            if ($page->status === 'signed') {
                ++$signed;
                continue;
            }
            if ($repair && $actor !== null) {
                $saved = $this->storage->save(
                    $path,
                    FrontmatterRepair::repair($good[1], $page->frontmatter),
                    $page->body,
                    $page->rev,
                    $actor,
                    'repair frontmatter flattened by the editor autosave (from rev ' . $good[0] . ')'
                );
                $this->audit->record('page.save', $actor, null, $saved->pid, $saved->path, $saved->rev, extra: ['reason' => 'frontmatter-repair', 'from_rev' => $good[0]]);
                $output->line('  repaired as rev ' . $saved->rev);
                ++$repaired;
            }
        }

        $output->line(\sprintf(
            '%d damaged page(s); %s; %d signed (correct and re-sign by hand); %d with no intact revision',
            $damaged,
            $repair ? $repaired . ' repaired' : 'run with --repair --actor=<username> to repair the unsigned ones',
            $signed,
            $unrecoverable,
        ));

        return 0;
    }

    /**
     * The newest earlier revision whose frontmatter is intact.
     *
     * @return ?array{0: int, 1: array<string, mixed>}
     */
    private function lastIntact(string $path, int $rev): ?array
    {
        for ($n = $rev - 1; $n >= 1; --$n) {
            try {
                [$frontmatter] = DocumentFormat::parse($this->storage->readRevision($path, $n));
            } catch (RuntimeException | ParseException) {
                continue;
            }
            if (FrontmatterRepair::damage($frontmatter) === []) {
                return [$n, $frontmatter];
            }
        }

        return null;
    }
}
