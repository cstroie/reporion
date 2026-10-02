<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Dicom;

use DateTimeImmutable;
use Reporion\Plugin\Dicom\Sr\ReportContent;
use Reporion\Plugin\Dicom\Sr\Uid;
use Reporion\Plugin\Dicom\Sr\Writer;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\Exams;
use Reporion\Support\MetaText;

/**
 * A signed report's DICOM SR stored to its site's PACS (roadmap phase 22b,
 * D39 as amended 2026-10-02): by a button, never automatically; only the
 * signed current revision; only into the study the report is linked to
 * (`study_uid`; each exam's own in a multi-exam report — one SR per study,
 * each the whole report); only at a site whose PACS row the owner ticked
 * "send SR" (Admin → Plugins → DICOM). The SR is the same document the download gives; a second
 * send of the same revision is the same instance (the PACS keeps one), a
 * corrected revision a new instance in the same series.
 *
 * Every attempt is recorded in the page's meta.json (`deliveries`, FORMATS
 * §4b) — not in the frontmatter, so no revision is made and the signature
 * stands (D3).
 */
final class SrSender
{
    /**
     * @param list<string> $srSites the site codes whose PACS takes our SRs (`servers.{site}.send_sr`)
     */
    public function __construct(
        private readonly Scu $scu,
        private readonly Pacs $pacs,
        private readonly StorageInterface $storage,
        private readonly array $srSites,
    ) {
    }

    /**
     * Whether the report can be sent now, and why not; its studies; what was
     * sent before (to the PACS, newest first).
     *
     * @return array{why: ?string, site: string, targets: list<array{uid: string, accession: string, exam: string}>, deliveries: list<array<string, mixed>>, sentRev: ?int, signed: bool}
     */
    public function state(PageRecord $page): array
    {
        $site = MetaText::text($page->frontmatter['site'] ?? null);
        $targets = self::targets($page);
        $signed = ReportContent::signature($page) !== null;
        $why = match (true) {
            !\in_array($site, $this->srSites, true) => 'sr-off',
            !isset($this->pacs->servers()[$site]) => 'not-configured',
            !$signed => 'unsigned',
            $targets === [] => 'unlinked',
            default => null,
        };
        $deliveries = array_values(array_filter(
            \is_array($page->meta['deliveries'] ?? null) ? $page->meta['deliveries'] : [],
            static fn (mixed $d): bool => \is_array($d) && ($d['to'] ?? '') === 'pacs',
        ));
        $sentRev = null;
        foreach ($deliveries as $d) {
            if (($d['outcome'] ?? '') === 'ok') {
                $sentRev = max($sentRev ?? 0, (int) ($d['rev'] ?? 0));
            }
        }

        return ['why' => $why, 'site' => $site, 'targets' => $targets, 'deliveries' => array_reverse($deliveries), 'sentRev' => $sentRev, 'signed' => $signed];
    }

    /**
     * Sends the signed current revision to each of its studies, stopping at
     * the first refusal (the next would fail the same way). Records each
     * attempt.
     *
     * @return array{outcome: string, log: string, sent: int, of: int}
     */
    public function send(PageRecord $page, string $actor): array
    {
        $state = $this->state($page);
        if ($state['why'] !== null) {
            return ['outcome' => $state['why'], 'log' => '', 'sent' => 0, 'of' => \count($state['targets'])];
        }
        $server = $this->pacs->servers()[$state['site']];
        $signature = ReportContent::signature($page);
        \assert($signature !== null);
        $signer = ['name' => display_name($signature['by']), 'at' => $signature['at']];
        // A single-exam report is sent as it downloads (same UIDs); a multi-exam one per study
        $own = !Exams::isMulti($page->frontmatter);
        $sent = 0;
        foreach ($state['targets'] as $target) {
            $study = $own ? null : ['uid' => $target['uid'], 'accession' => $target['accession']];
            $instance = ReportContent::instanceUid($page, $study['uid'] ?? null);
            $outcome = 'ok';
            $log = '';
            try {
                $this->scu->store($server, Writer::file(ReportContent::SOP_CLASS, $instance, ReportContent::dataset($page, $signer, $study)));
            } catch (DicomException $e) {
                $outcome = $e->getMessage();
                $log = $e->log;
            }
            $this->storage->recordDelivery($page->path, [
                'to' => 'pacs',
                'rev' => $page->rev,
                'at' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
                'by' => $actor,
                'outcome' => $outcome,
                'site' => $state['site'],
                'study_uid' => $target['uid'],
                'sop_instance' => $instance,
            ]);
            if ($outcome !== 'ok') {
                return ['outcome' => $outcome, 'log' => $log, 'sent' => $sent, 'of' => \count($state['targets'])];
            }
            ++$sent;
        }

        return ['outcome' => 'ok', 'log' => '', 'sent' => $sent, 'of' => $sent];
    }

    /**
     * The studies the report is linked to: the report's own `study_uid`, or
     * each exam's in a multi-exam report — valid UIDs only, never one made up.
     *
     * @return list<array{uid: string, accession: string, exam: string}>
     */
    public static function targets(PageRecord $page): array
    {
        $fm = $page->frontmatter;
        if (!Exams::isMulti($fm)) {
            $uid = MetaText::text($fm['study_uid'] ?? null);

            return Uid::isValid($uid) ? [['uid' => $uid, 'accession' => MetaText::text($fm['accession'] ?? null), 'exam' => MetaText::text($fm['exam_title'] ?? null)]] : [];
        }
        $out = [];
        foreach ((array) $fm['exams'] as $exam) {
            $uid = \is_array($exam) ? MetaText::text($exam['study_uid'] ?? null) : '';
            if (Uid::isValid($uid) && !\in_array($uid, array_column($out, 'uid'), true)) {
                $out[] = ['uid' => $uid, 'accession' => MetaText::text($exam['accession'] ?? null), 'exam' => MetaText::text($exam['title'] ?? null)];
            }
        }

        return $out;
    }
}
