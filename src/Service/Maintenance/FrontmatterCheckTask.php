<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

use Reporion\Audit\AuditLog;
use Reporion\Service\FrontmatterRepair;
use Reporion\Storage\FlatFile;
use Reporion\Support\DocumentFormat;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Throwable;

/**
 * pages:check-frontmatter — pages whose current frontmatter the editor
 * autosave flattened before 2026-09-26 (Service\FrontmatterRepair). Apply
 * writes one new revision per unsigned page from its last intact revision;
 * a signed page is listed, never repaired here (D3). A first revision is
 * never listed: the autosave only ever saved over an existing page.
 */
final class FrontmatterCheckTask implements MaintenanceTask
{
    public function __construct(
        private readonly FlatFile $storage,
        private readonly AuditLog $audit,
    ) {
    }

    public function name(): string
    {
        return 'pages:check-frontmatter';
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
        foreach (['damaged', 'repaired', 'signed', 'unrecoverable'] as $key) {
            $report->count($key, 0);
        }

        foreach ($this->storage->allPaths() as $path) {
            try {
                $page = $this->storage->read($path);
            } catch (Throwable) {
                continue;
            }
            $damage = $page->rev > 1 ? FrontmatterRepair::damage($page->frontmatter) : [];
            if ($damage === []) {
                continue;
            }
            $report->count('damaged');
            $good = $this->lastIntact($path, $page->rev);
            $signed = $page->status === 'signed';
            $data = ['signed' => $signed, 'damage' => $damage, 'intact_rev' => $good[0] ?? null, 'repaired_rev' => null];
            $detail = implode(', ', $damage);

            if ($good === null) {
                $report->count('unrecoverable');
                $report->item($page->pid, $page->rev, 'unrecoverable', $detail, $data);
                continue;
            }
            if ($signed) {
                $report->count('signed');
                $report->item($page->pid, $page->rev, 'signed', $detail, $data);
                continue;
            }
            if ($mode !== self::APPLY) {
                $report->item($page->pid, $page->rev, 'damaged', $detail, $data);
                continue;
            }
            $saved = $this->storage->save(
                $path,
                FrontmatterRepair::repair($good[1], $page->frontmatter),
                $page->body,
                $page->rev,
                $actor,
                'repair frontmatter flattened by the editor autosave (from rev ' . $good[0] . ')'
            );
            $this->audit->record('page.save', $actor, null, $saved->pid, $saved->path, $saved->rev, extra: ['reason' => 'frontmatter-repair', 'from_rev' => $good[0]]);
            $data['repaired_rev'] = $saved->rev;
            $report->count('repaired');
            $report->item($page->pid, $page->rev, 'repaired', $detail, $data);
        }

        return $report;
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
