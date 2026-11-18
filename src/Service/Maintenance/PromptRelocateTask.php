<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Index\Sqlite;
use Reporion\Service\Ai\Actions;
use Reporion\Storage\FileTree;
use RuntimeException;

/**
 * ai:prompts-relocate (2026-11-18): every profile's reserved prompts moved from the old
 * `ai:profiles:{profile}:special:{id}` to their place (Actions::PLACES: `panels:`, `buttons:`,
 * `questions:`, `library:`, `studio:`, `auto:`). A move keeps the page's history and leaves a redirect;
 * audited `page.move`. A page whose place is already taken is left (the one at the place wins
 * anyway); an id with no place is left where it is. Check lists what would move.
 */
final class PromptRelocateTask implements MaintenanceTask
{
    public function __construct(
        private readonly FileTree $storage,
        private readonly Sqlite $index,
        private readonly AuditLog $audit,
    ) {
    }

    public function name(): string
    {
        return 'ai:prompts-relocate';
    }

    public function modes(): array
    {
        return [self::CHECK, self::APPLY];
    }

    public function options(array $raw): array
    {
        return [];
    }

    public function run(string $mode, string $actor, array $options): MaintenanceReport
    {
        $report = new MaintenanceReport($this->name(), $mode, $actor, $options, date('Y-m-d\TH:i:sP'));
        $report->count('pages', 0);
        $instance = new User('instance', '', true, [], true, '', '');
        foreach ($this->index->listSubnamespaces(Actions::NS, $instance) as $profile) {
            $old = Actions::NS . ':' . $profile['name'] . ':' . Actions::SPECIAL_NS;
            foreach ($this->index->listNamespace($old, $instance) as $row) {
                $from = (string) $row['path'];
                $id = substr($from, \strlen($old) + 1);
                $report->count('pages');
                if (!isset(Actions::PLACES[$id])) {
                    $report->item(null, null, 'unknown', $from . ': not a reserved prompt, left', []);
                    $report->count('unknown');
                    continue;
                }
                $to = Actions::NS . ':' . $profile['name'] . ':' . Actions::PLACES[$id];
                if ($this->index->findByPath($to, $instance) !== null) {
                    $report->item(null, null, 'taken', $from . ' → ' . $to . ': a page is there already, left', []);
                    $report->count('taken');
                    continue;
                }
                if ($mode === self::CHECK) {
                    $report->item(null, null, 'move', $from . ' → ' . $to, []);
                    $report->count('move');
                    continue;
                }
                try {
                    $moved = $this->storage->move($from, $to, $actor);
                } catch (RuntimeException | \InvalidArgumentException) {
                    $report->item(null, null, 'failed', $from, []);
                    $report->count('failed');
                    continue;
                }
                $this->audit->record('page.move', $actor, null, $moved->pid, $moved->path, $moved->rev, extra: ['from_hash' => AuditLog::pathHash($from), 'reason' => 'prompt-relocate']);
                $report->item($moved->pid, null, 'moved', $from . ' → ' . $to, []);
                $report->count('moved');
            }
        }
        if (($report->summary()['failed'] ?? 0) > 0) {
            $report->fail();
        }

        return $report;
    }
}
