<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use Reporion\Exception\PageNotFoundException;
use Reporion\Storage\StorageInterface;
use Throwable;

/**
 * DokuLLM's prompt profile, imported as assistant pages (roadmap phase 15b):
 * `dokullm:profiles:{p}` (its action table: ID, Label, Tooltip, Icon,
 * Result) and its prompt pages become `ai:profiles:{q}:{action}` with the
 * table's columns in each page's frontmatter; `system` (or the import's
 * `system-2`, where a page and its namespace collided) and `system:{action}`
 * come along. Actions under a "Disabled" heading arrive disabled; those
 * under "not implemented" are left out.
 *
 * The prompts were written for DokuWiki: a few rewrites are safe to make
 * mechanically (a `===== X =====` heading instruction becomes `### X`, an
 * example's `## Concluzii` section becomes `### Concluzii`, "format DokuWiki"
 * becomes "format Markdown", an instruction to title the report with the
 * patient's name goes — Reporion writes that heading itself, D30). Every
 * other line that still speaks of DokuWiki markup or of the patient's name
 * is listed for the owner to review. Nothing is overwritten: a page that
 * already exists is skipped.
 */
final class PromptImport
{
    private const SECTIONS = 'Concluzii|Concluzie|Descriere|Indica[țţ]ie|Tehnic[ăa]|Recomand[ăa]ri|Analiz[ăa] comparativ[ăa]';

    public function __construct(private readonly StorageInterface $storage)
    {
    }

    /**
     * @return array{created: list<string>, skipped: list<string>, review: list<array{page: string, line: int, text: string}>}
     */
    public function run(string $from, string $to, string $actor, bool $dryRun): array
    {
        $index = $this->read($from) ?? $this->read($from . '-2') ?? throw new PageNotFoundException();
        $report = ['created' => [], 'skipped' => [], 'review' => []];

        $pages = [];
        foreach (self::table($index->body) as $i => $row) {
            $source = $this->read($from . ':' . $row['id']);
            if ($source === null) {
                continue;
            }
            $pages[$to . ':' . $row['id']] = [[
                'title' => $row['label'],
                'label' => $row['label'],
                'tooltip' => $row['tooltip'],
                'icon' => $row['icon'],
                'result' => \in_array($row['result'], Action::RESULTS, true) ? $row['result'] : 'show',
                'order' => ($i + 1) * 10,
                'enabled' => $row['enabled'],
                'visibility' => 'private',
            ], $source->body];
        }
        $system = $this->read($from . ':system') ?? $this->read($from . ':system-2');
        if ($system !== null) {
            $pages[$to . ':system'] = [['title' => 'System prompt', 'visibility' => 'private'], $system->body];
        }
        foreach (array_keys($pages) as $path) {
            $id = substr($path, \strlen($to) + 1);
            $own = $id !== 'system' ? $this->read($from . ':system:' . $id) : null;
            if ($own !== null) {
                $pages[$to . ':system:' . $id] = [['title' => 'System prompt · ' . $id, 'visibility' => 'private'], $own->body];
            }
        }

        foreach ($pages as $path => [$frontmatter, $body]) {
            $body = self::rewrite($body);
            foreach (explode("\n", $body) as $n => $line) {
                if (preg_match('/dokuwiki|={4,}|~~|\bnumel[ea]\b.*\bpacient|\btitlu\b.*\bpacient/iu', $line) === 1) {
                    $report['review'][] = ['page' => $path, 'line' => $n + 1, 'text' => mb_substr(trim($line), 0, 160)];
                }
            }
            if ($this->read($path) !== null) {
                $report['skipped'][] = $path;
                continue;
            }
            if (!$dryRun) {
                $this->storage->create($path, $frontmatter, $body, $actor, 'imported from ' . $from, auto: true);
            }
            $report['created'][] = $path;
        }

        return $report;
    }

    /**
     * The action rows of a DokuLLM profile page: every table's rows, those
     * under a "Disabled" heading disabled, those under "not implemented" left out.
     *
     * @return list<array{id: string, label: string, tooltip: string, icon: string, result: string, enabled: bool}>
     */
    public static function table(string $body): array
    {
        $rows = [];
        $heading = '';
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (preg_match('/^#+\s+(.*)$/', $line, $m) === 1) {
                $heading = strtolower($m[1]);
                continue;
            }
            if (str_contains($heading, 'not implemented') || !str_starts_with(trim($line), '|')) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim(trim($line), '|')));
            if (\count($cells) < 5 || preg_match('/\[\[[^\]]*?([a-z0-9_-]+)\]\]/i', $cells[0], $id) !== 1) {
                continue;
            }
            $rows[] = [
                'id' => strtolower($id[1]),
                'label' => $cells[1],
                'tooltip' => $cells[2],
                'icon' => $cells[3],
                'result' => strtolower($cells[4]),
                'enabled' => !str_contains($heading, 'disabled'),
            ];
        }

        return $rows;
    }

    /** The safe mechanical rewrites from DokuWiki prompt wording to Reporion's */
    public static function rewrite(string $body): string
    {
        $lines = [];
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            // Reporion writes the patient's name heading itself (D30): never ask the model for it
            if (preg_match('/numele complet al pacientului/iu', $line) === 1) {
                continue;
            }
            // A heading marker pair of the same length, whole: `===== Concluzii =====`, not `====== și =====`
            $line = (string) preg_replace('/(?<!=)(={5,6})[ \t]*([^=\n]+?)[ \t]*\1(?!=)/u', '### $2', $line);
            $line = (string) preg_replace('/^##[ \t]+(' . self::SECTIONS . ')\b/u', '### $1', $line);
            $line = (string) preg_replace('/\bformat(ul)? DokuWiki\b/iu', 'format$1 Markdown', $line);
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    private function read(string $path): ?\Reporion\Storage\PageRecord
    {
        try {
            return $this->storage->read($path);
        } catch (Throwable) {
            return null;
        }
    }
}
