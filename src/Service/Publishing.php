<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Http\Request;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\ReportPath;

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
     * @param int $attachedMedia files attached to the page (Storage::mediaOf()) — they
     *                           become fetchable by everyone along with it
     *
     * @return array{path: string, title: string, pathLooksPersonal: bool, hiddenPatientFields: list<string>, attachedMedia: int}
     */
    public static function preview(PageRecord $page, int $attachedMedia = 0): array
    {
        return self::previewOf($page->path, $page->frontmatter, $attachedMedia);
    }

    /**
     * preview() for a page as it is being edited — its frontmatter not yet
     * saved, or a page not yet created.
     *
     * @param array<string, mixed> $frontmatter
     *
     * @return array{path: string, title: string, pathLooksPersonal: bool, hiddenPatientFields: list<string>, attachedMedia: int}
     */
    public static function previewOf(string $path, array $frontmatter, int $attachedMedia = 0): array
    {
        $hidden = [];
        if (\is_array($frontmatter['patient'] ?? null)) {
            foreach (array_keys($frontmatter['patient']) as $key) {
                $hidden[] = 'patient.' . $key;
            }
        }

        return [
            'path' => $path,
            'title' => \is_string($frontmatter['title'] ?? null) && $frontmatter['title'] !== '' ? $frontmatter['title'] : $path,
            // The D1 path shape {yymmdd}-{name}: the URL itself would name the patient
            'pathLooksPersonal' => ReportPath::looksLikeReportName(ReportPath::leaf($path)),
            'hiddenPatientFields' => $hidden,
            'attachedMedia' => $attachedMedia,
        ];
    }

    /** Whether $changes would make $page public — the D16 acknowledgement step */
    public static function needsAcknowledgement(PageRecord $page, array $changes): bool
    {
        return ($changes['visibility'] ?? null) === 'public' && $page->visibility !== 'public';
    }

    /**
     * $current with $changes laid over it: a key set to null is removed,
     * every other key is set, `status` is never touched this way (signing
     * is its own action). The one merge rule shared by every frontmatter
     * writer that is not a raw-document round trip (`PATCH /pages/{path}/meta`
     * here, the editor's Details panel — phase 14 — elsewhere): a key
     * $changes does not mention is never touched, so nothing not shown to
     * whoever is editing is ever silently dropped.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    public static function merge(array $current, array $changes): array
    {
        foreach ($changes as $key => $value) {
            if ($key === 'status') {
                continue;
            }
            if ($value === null) {
                unset($current[$key]);
            } else {
                $current[$key] = $value;
            }
        }

        return $current;
    }

    /**
     * Apply frontmatter $changes as one new revision: a key set to null is
     * removed, `status` is never set this way (signing is its own action).
     * Audited as page.save, plus page.publish when the page becomes public.
     *
     * A signed report is refused here (see the class docblock) — this is
     * this endpoint's own, stricter rule, not merge()'s: the editor's own
     * save (a full document, D3) allows correcting a signed report, since
     * that has always created a new draft revision needing re-signing.
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

        $frontmatter = self::merge($page->frontmatter, $changes);
        $note = isset($changes['visibility']) ? 'visibility: ' . $changes['visibility'] : 'metadata';
        $saved = $this->storage->save($page->path, $frontmatter, $page->body, $baseRev, $actor, $note);

        $this->audit->record('page.save', $actor, $request, $saved->pid, $saved->path, $saved->rev, extra: ['meta_only' => true]);
        if (self::needsAcknowledgement($page, $changes)) {
            $this->audit->record('page.publish', $actor, $request, $saved->pid, $saved->path, $saved->rev, extra: ['acknowledged' => true]);
        }

        return $saved;
    }
}
