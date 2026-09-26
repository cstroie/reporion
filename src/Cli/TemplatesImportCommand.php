<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Audit\AuditLog;
use Reporion\Exception\PageNotFoundException;
use Reporion\Import\TemplateConverter;
use Reporion\Storage\FlatFile;
use Reporion\Support\Slug;
use Throwable;

/**
 * bin/reporion templates:import --from <dir> [--dry-run] [--actor=<username>]
 *
 * Imports a DokuWiki templates directory — one sub-directory per modality
 * namespace (`mri/`, `ct/`), one `.txt` per template — as the pages the
 * new-report form offers: `templates:{namespace}:{file}` (D19), converted
 * by Import\TemplateConverter. The namespace must be one the modality map
 * knows (Admin → Settings → Reports; MR = mri, CT = ct by default), which
 * also gives the page its modality. Private drafts, written through Storage
 * and audited page.create; a page that already exists is left alone, so the
 * command can be run again after adding templates. `sidebar.txt` (DokuWiki
 * navigation) is skipped.
 */
final class TemplatesImportCommand implements CommandInterface
{
    /**
     * @param array<string, string> $modalityNamespaces modality code → namespace segment
     * @param array<string, mixed>  $categoryRegions    conf/import-map.json template_category_region
     */
    public function __construct(
        private readonly FlatFile $storage,
        private readonly AuditLog $audit,
        private readonly array $modalityNamespaces,
        private readonly array $categoryRegions,
    ) {
    }

    public function run(array $args, Output $output): int
    {
        $from = null;
        $actor = 'cli';
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--from=')) {
                $from = substr($arg, 7);
            } elseif (str_starts_with($arg, '--actor=') && \strlen($arg) > 8) {
                $actor = substr($arg, 8);
            }
        }
        $index = array_search('--from', $args, true);
        if ($from === null && $index !== false) {
            $from = $args[$index + 1] ?? null;
        }
        $dryRun = \in_array('--dry-run', $args, true);
        if ($from === null || !is_dir($from)) {
            $output->error('Usage: bin/reporion templates:import --from <dir> [--dry-run] [--actor=<username>]');

            return 1;
        }

        $byNamespace = array_flip($this->modalityNamespaces);
        $counts = ['created' => 0, 'exists' => 0, 'skipped' => 0];
        foreach (glob(rtrim($from, '/') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $ns = basename($dir);
            $modality = $byNamespace[$ns] ?? null;
            if ($modality === null) {
                $output->line("skipped {$ns}/ — no modality maps to namespace '{$ns}' (Admin → Settings → Reports)");
                ++$counts['skipped'];
                continue;
            }
            $files = glob($dir . '/*.txt') ?: [];
            sort($files);
            foreach ($files as $file) {
                $name = basename($file, '.txt');
                if ($name === 'sidebar') {
                    $output->line("skipped {$ns}/{$name}.txt — DokuWiki navigation, not a template");
                    ++$counts['skipped'];
                    continue;
                }
                $path = 'templates:' . $ns . ':' . Slug::normalize($name);
                $text = (string) file_get_contents($file);
                $converted = TemplateConverter::convert($text, $this->categoryRegions);
                $line = \sprintf('%s — %s%s', $path, $converted['title'], $converted['regions'] !== [] ? ' [' . implode(', ', $converted['regions']) . ']' : '');

                if ($this->exists($path)) {
                    $output->line('exists  ' . $line);
                    ++$counts['exists'];
                    continue;
                }
                if (!$dryRun) {
                    $record = $this->storage->create($path, array_filter([
                        'title' => $converted['title'],
                        'visibility' => 'private',
                        'modality' => [$modality],
                        'region' => $converted['regions'] !== [] ? $converted['regions'] : null,
                        'template_label' => $converted['label'] !== '' ? $converted['label'] : null,
                        'tags' => ['templates'],
                        'imported_from' => 'templates/' . $ns . '/' . basename($file) . ' sha256:' . hash('sha256', $text),
                    ], static fn (mixed $value): bool => $value !== null), $converted['body'], $actor, 'imported template');
                    $this->audit->record('page.create', $actor, null, $record->pid, $record->path, $record->rev, extra: ['reason' => 'template-import']);
                }
                $output->line(($dryRun ? 'would create ' : 'created ') . $line);
                foreach ($converted['notes'] as $note) {
                    $output->line('    ' . $note);
                }
                ++$counts['created'];
            }
        }

        $output->line(\sprintf(
            '%d template(s) %s, %d already there, %d skipped',
            $counts['created'],
            $dryRun ? 'would be created' : 'created',
            $counts['exists'],
            $counts['skipped'],
        ));

        return 0;
    }

    private function exists(string $path): bool
    {
        try {
            $this->storage->read($path);

            return true;
        } catch (PageNotFoundException) {
            return false;
        } catch (Throwable) {
            return true;
        }
    }
}
