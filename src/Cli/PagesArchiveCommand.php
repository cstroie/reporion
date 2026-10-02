<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Exception\PageNotFoundException;
use Reporion\Storage\StorageInterface;
use Throwable;

/**
 * bin/reporion pages:archive [--apply --actor=<username>] [--namespace=<ns>] [--batch=<id>]
 *
 * Marks imported pages that are still drafts as `archived` — what the
 * import should have given them (D38: another radiologist's text never
 * shows a Sign button). Imported means the frontmatter carries
 * `imported_from` or `import_batch`; a page ever signed here is left alone.
 * Without --apply it only lists what it would change. Through
 * Storage::archive() (invariant 5): meta.json only, no new revision, one
 * `page.archive` audit line per page.
 */
final class PagesArchiveCommand implements CommandInterface
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly AuditLog $audit,
    ) {
    }

    public function run(array $args, Output $output): int
    {
        $options = [];
        foreach ($args as $arg) {
            if (preg_match('/^--(actor|namespace|batch)=(.+)$/', $arg, $m) === 1) {
                $options[$m[1]] = $m[2];
            } elseif ($arg !== '--apply') {
                $output->error('Usage: bin/reporion pages:archive [--apply --actor=<username>] [--namespace=<ns>] [--batch=<id>]');

                return 1;
            }
        }
        $apply = \in_array('--apply', $args, true);
        if ($apply && !isset($options['actor'])) {
            $output->error('--apply needs --actor=<username>: the change is recorded under their name');

            return 1;
        }
        $prefix = isset($options['namespace']) ? trim($options['namespace'], ':') . ':' : '';

        $found = [];
        $signed = 0;
        foreach ($this->storage->allPaths() as $path) {
            if ($prefix !== '' && !str_starts_with($path, $prefix)) {
                continue;
            }
            try {
                $page = $this->storage->read($path);
            } catch (PageNotFoundException) {
                continue;
            }
            $fm = $page->frontmatter;
            $imported = trim((string) (\is_scalar($fm['imported_from'] ?? null) ? $fm['imported_from'] : '')) !== ''
                || trim((string) (\is_scalar($fm['import_batch'] ?? null) ? $fm['import_batch'] : '')) !== '';
            if (!$imported || $page->status === 'archived') {
                continue;
            }
            if (isset($options['batch']) && (string) ($fm['import_batch'] ?? '') !== $options['batch']) {
                continue;
            }
            if ($page->status === 'signed' || ($page->meta['signatures'] ?? []) !== []) {
                ++$signed;
                continue;
            }
            $found[] = $path;
        }

        sort($found);
        foreach ($found as $path) {
            $output->line(($apply ? 'archive ' : 'would archive ') . $path);
        }
        $skipped = $signed > 0 ? \sprintf(' — %d imported page(s) signed here left as they are', $signed) : '';
        if (!$apply) {
            $output->line(\sprintf('%d imported draft(s) would be archived%s. Run again with --apply --actor=<username>.', \count($found), $skipped));

            return 0;
        }

        $done = 0;
        $failed = 0;
        foreach ($found as $path) {
            try {
                $page = $this->storage->archive($path, $options['actor']);
                $this->audit->record('page.archive', $options['actor'], null, $page->pid, $page->path, $page->rev);
                ++$done;
            } catch (InvalidArgumentException | PageNotFoundException $e) {
                ++$failed;
                $output->error('skipped ' . $path . ': ' . ($e->getMessage() !== '' ? $e->getMessage() : 'gone'));
            } catch (Throwable $e) {
                ++$failed;
                $output->error('failed ' . $path . ': ' . $e->getMessage());
            }
        }
        $output->line(\sprintf('%d page(s) archived%s%s.', $done, $failed > 0 ? \sprintf(', %d not', $failed) : '', $skipped));

        return $failed > 0 ? 1 : 0;
    }
}
