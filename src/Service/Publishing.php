<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Http\Request;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;

/**
 * Changing a page's visibility (PATCH /pages/{path}/meta, the page's
 * Visibility… action) — D16: making a page public is a deliberate, noisy
 * act, so it first shows exactly what becomes visible and applies only with
 * an explicit acknowledgement; it is audited as page.publish.
 *
 * A signed report is never changed here (decided 2026-09-26): visibility is
 * inside the signed digest, so a change would un-sign it. Publish a
 * duplicate instead (Service\Duplicates never copies patient fields), or
 * correct and re-sign.
 */
final class Publishing
{
    public const VISIBILITIES = ['private', 'unlisted', 'public'];

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * What a public page shows, and what stays hidden, for the confirmation
     * step.
     *
     * @return array{path: string, title: string, pathLooksPersonal: bool, hiddenPatientFields: list<string>}
     */
    public static function preview(PageRecord $page): array
    {
        $hidden = [];
        if (\is_array($page->frontmatter['patient'] ?? null)) {
            foreach (array_keys($page->frontmatter['patient']) as $key) {
                $hidden[] = 'patient.' . $key;
            }
        }
        $leaf = substr($page->path, (int) strrpos($page->path, ':') + (str_contains($page->path, ':') ? 1 : 0));

        return [
            'path' => $page->path,
            'title' => \is_string($page->frontmatter['title'] ?? null) ? $page->frontmatter['title'] : $page->path,
            // The D1 path shape {yymmdd}-{name}: the URL itself would name the patient
            'pathLooksPersonal' => preg_match('/^\d{6}-[a-z]/i', $leaf) === 1,
            'hiddenPatientFields' => $hidden,
        ];
    }

    /** Whether $changes would make $page public — the D16 acknowledgement step */
    public static function needsAcknowledgement(PageRecord $page, array $changes): bool
    {
        return ($changes['visibility'] ?? null) === 'public' && $page->visibility !== 'public';
    }

    /**
     * Apply frontmatter $changes as one new revision: a key set to null is
     * removed, `status` is never set this way (signing is its own action).
     * Audited as page.save, plus page.publish when the page becomes public.
     *
     * @param array<string, mixed> $changes
     *
     * @throws InvalidArgumentException for an unknown visibility or a signed page
     * @throws \Reporion\Exception\RevisionConflictException when $baseRev is stale
     */
    public function apply(PageRecord $page, array $changes, int $baseRev, string $actor, ?Request $request = null): PageRecord
    {
        if (isset($changes['visibility']) && !\in_array($changes['visibility'], self::VISIBILITIES, true)) {
            throw new InvalidArgumentException('visibility must be private, unlisted or public');
        }
        if ($page->status === 'signed') {
            throw new InvalidArgumentException(t('vis.err_signed'));
        }

        $frontmatter = $page->frontmatter;
        foreach ($changes as $key => $value) {
            if ($key === 'status') {
                continue;
            }
            if ($value === null) {
                unset($frontmatter[$key]);
            } else {
                $frontmatter[$key] = $value;
            }
        }
        $note = isset($changes['visibility']) ? 'visibility: ' . $changes['visibility'] : 'metadata';
        $saved = $this->storage->save($page->path, $frontmatter, $page->body, $baseRev, $actor, $note);

        $this->audit->record('page.save', $actor, $request, $saved->pid, $saved->path, $saved->rev, extra: ['meta_only' => true]);
        if (self::needsAcknowledgement($page, $changes)) {
            $this->audit->record('page.publish', $actor, $request, $saved->pid, $saved->path, $saved->rev, extra: ['acknowledged' => true]);
        }

        return $saved;
    }
}
