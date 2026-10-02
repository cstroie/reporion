<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Dicom\Sr;

use Reporion\Storage\PageRecord;
use Reporion\Support\Cnp;
use Reporion\Support\Exams;
use Reporion\Support\MetaText;
use Reporion\Support\ReportName;

/**
 * A signed report as a DICOM Basic Text SR document laid out after TID 2000
 * (Basic Diagnostic Imaging Report): a container titled "Diagnostic Imaging
 * Report" (LOINC 18748-4, CID 7000), the language of the content, then per
 * exam a "Current Procedure Descriptions" container holding the exam title
 * and technique as Procedure Description items, and one heading container
 * (History, Findings, Impressions, Recommendations — CID 7001, LOINC codes)
 * per `###` section, its text as TEXT items (CID 7002), one per paragraph.
 *
 * Pure: a PageRecord and the signer in, the dataset's elements out.
 */
final class ReportContent
{
    public const SOP_CLASS = '1.2.840.10008.5.1.4.1.1.88.11';

    private const DCM = 'DCM';
    private const LN = 'LN';

    /**
     * Section kind → [heading container (CID 7001), narrative TEXT item (CID 7002)].
     * Tehnică has no container of its own: its text is the exam container's
     * Procedure Description.
     */
    private const SECTIONS = [
        'history' => [['11329-0', self::LN, 'History'], ['11329-0', self::LN, 'History']],
        'procedure' => [null, ['121065', self::DCM, 'Procedure Description']],
        'findings' => [['59776-5', self::LN, 'Findings'], ['121071', self::DCM, 'Finding']],
        'impression' => [['19005-8', self::LN, 'Impressions'], ['121073', self::DCM, 'Impression']],
        'recommendation' => [['18783-1', self::LN, 'Recommendations'], ['121075', self::DCM, 'Recommendation']],
    ];

    private const PROCEDURES = ['55111-9', self::LN, 'Current Procedure Descriptions'];
    private const COMMENT = ['121106', self::DCM, 'Comment'];

    /**
     * @param array{name: string, at: string} $signer the signing account's display name and the signature's timestamp (ISO 8601)
     * @param ?array{uid: string, accession: string} $study one exam's own study (a multi-exam report sent to the PACS,
     *        phase 22): its UID and accession, and series and instance UIDs of its own. Null: the report's
     *
     * @return list<array{0: int, 1: string, 2: mixed}>
     */
    public static function dataset(PageRecord $record, array $signer, ?array $study = null): array
    {
        $fm = $record->frontmatter;
        $patient = \is_array($fm['patient'] ?? null) ? $fm['patient'] : [];
        $cnp = preg_replace('/\s+/', '', MetaText::text($patient['cnp'] ?? null)) ?? '';
        [$studyDate, $studyTime] = self::dayAndTime($fm['study_date'] ?? null);
        [$signDate, $signTime] = self::dayAndTime($signer['at']);
        $studyUid = $study['uid'] ?? MetaText::text(Exams::of($fm)[0]['study_uid'] ?? null);
        $studyUid = Uid::isValid($studyUid) ? $studyUid : Uid::derive($record->pid, 'study');
        $exam = ReportName::examTitle($fm);
        $born = $cnp !== '' && ($date = Cnp::birthDate($cnp)) !== null ? $date->format('Ymd') : '';
        $sex = strtoupper(substr(MetaText::text($patient['sex'] ?? null), 0, 1));
        // The plugin sees no site letterhead (D9): the site code names the institution
        $institution = strtoupper(MetaText::text($fm['site'] ?? null));

        return [
            [0x00080005, 'CS', 'ISO_IR 192'],
            [0x00080016, 'UI', self::SOP_CLASS],
            [0x00080018, 'UI', self::instanceUid($record, $study['uid'] ?? null)],
            [0x00080020, 'DA', $studyDate],
            [0x00080023, 'DA', $signDate],
            [0x00080030, 'TM', $studyTime],
            [0x00080033, 'TM', $signTime],
            [0x00080050, 'SH', $study['accession'] ?? MetaText::text($fm['accession'] ?? null)],
            [0x00080060, 'CS', 'SR'],
            [0x00080070, 'LO', 'Reporion'],
            [0x00080080, 'LO', $institution],
            [0x00080090, 'PN', self::personName(MetaText::text($fm['referrer'] ?? null))],
            [0x00081030, 'LO', $exam],
            [0x00100010, 'PN', self::personName(MetaText::text($patient['name'] ?? null))],
            // The PACS plugin looks patients up by CNP, so it is the id when known; else the pid — never the path
            [0x00100020, 'LO', $cnp !== '' ? $cnp : $record->pid],
            [0x00100030, 'DA', $born],
            [0x00100040, 'CS', \in_array($sex, ['M', 'F'], true) ? $sex : ''],
            [0x0020000D, 'UI', $studyUid],
            [0x0020000E, 'UI', $study !== null ? Uid::derive($record->pid, 'series', $study['uid']) : Uid::derive($record->pid, 'series')],
            [0x00200010, 'SH', ''],
            [0x00200011, 'IS', '1'],
            [0x00200013, 'IS', (string) $record->rev],
            [0x00081111, 'SQ', []],
            [0x0040A372, 'SQ', []],
            // The template the tree follows: TID 2000 of the DICOM Content Mapping Resource
            [0x0040A504, 'SQ', [[[0x00080105, 'CS', 'DCMR'], [0x0040DB00, 'CS', '2000']]]],
            [0x0040A073, 'SQ', [[
                [0x0040A027, 'LO', $institution],
                [0x0040A030, 'DT', self::verificationTime($signer['at'])],
                [0x0040A075, 'PN', self::personName($signer['name'], false)],
                [0x0040A088, 'SQ', []],
            ]]],
            [0x0040A491, 'CS', 'COMPLETE'],
            [0x0040A493, 'CS', 'VERIFIED'],
            ...self::container(['18748-4', 'LN', 'Diagnostic Imaging Report'], self::children($record, $exam)),
        ];
    }

    /**
     * The signature on the record's current revision (the last one, should
     * there be several), or null when that revision is not signed.
     *
     * @return ?array{by: string, at: string}
     */
    public static function signature(PageRecord $record): ?array
    {
        $found = null;
        foreach ((array) ($record->meta['signatures'] ?? []) as $candidate) {
            if (\is_array($candidate) && (int) ($candidate['rev'] ?? 0) === $record->rev) {
                $found = ['by' => MetaText::text($candidate['by'] ?? null), 'at' => MetaText::text($candidate['ts'] ?? null)];
            }
        }

        return $found;
    }

    /**
     * The same revision always the same instance (a second send is the same
     * object to the PACS); a corrected revision a new one in the same series.
     */
    public static function instanceUid(PageRecord $record, ?string $studyUid = null): string
    {
        return $studyUid !== null ? Uid::derive($record->pid, (string) $record->rev, 'instance', $studyUid) : Uid::derive($record->pid, (string) $record->rev, 'instance');
    }

    /** @return list<array{0: int, 1: string, 2: mixed}> */
    private static function children(PageRecord $record, string $exam): array
    {
        $items = [
            self::code(['121049', self::DCM, 'Language of Content Item and Descendants'], ['ro', 'RFC5646', 'Romanian']),
        ];
        foreach (self::exams(ReportName::withoutNameHeading($record->body, $record->frontmatter), $exam) as $part) {
            if ($part['head']) {
                // Text above the first exam belongs to no procedure
                foreach ($part['sections'] as $section) {
                    array_push($items, ...self::section($section));
                }
                continue;
            }
            $inner = $part['title'] !== '' ? [self::text(self::SECTIONS['procedure'][1], $part['title'])] : [];
            foreach ($part['sections'] as $section) {
                array_push($inner, ...self::section($section));
            }
            if ($inner !== []) {
                $items[] = self::child(self::container(self::PROCEDURES, $inner));
            }
        }
        $items[] = self::text(self::COMMENT, 'rev ' . $record->rev . ' · /r/' . $record->pid . '/' . $record->rev);

        return $items;
    }

    /**
     * A section as content items: its heading container of TEXT items, or —
     * for the technique — the TEXT items alone, to sit in the exam container.
     *
     * @param array{kind: string, paragraphs: list<string>} $section
     *
     * @return list<list<array{0: int, 1: string, 2: mixed}>>
     */
    private static function section(array $section): array
    {
        [$heading, $narrative] = self::SECTIONS[$section['kind']];
        $texts = array_map(static fn (string $p): array => self::text($narrative, $p), $section['paragraphs']);
        if ($heading === null) {
            return $texts;
        }

        return $texts === [] ? [] : [self::child(self::container($heading, $texts))];
    }

    /**
     * The body cut into exams (`##`) and their sections (`###`, and `####`
     * or anything deeper folded into the section as its own paragraph).
     * Text above the first `##` is the History; a body with no `##` at all
     * is one exam titled by the exam title.
     *
     * @return list<array{title: string, head: bool, sections: list<array{kind: string, paragraphs: list<string>}>}>
     */
    private static function exams(string $body, string $examTitle): array
    {
        $exams = [];
        $current = null;
        $head = ['kind' => 'history', 'paragraphs' => []];
        $block = [];
        $fence = false;
        $flush = static function () use (&$block, &$current, &$head): void {
            if ($block !== []) {
                $text = self::plain($block);
                if ($text !== '') {
                    if ($current === null) {
                        $head['paragraphs'][] = $text;
                    } else {
                        $last = \count($current['sections']) - 1;
                        if ($last < 0) {
                            $current['sections'][] = ['kind' => 'findings', 'paragraphs' => []];
                            ++$last;
                        }
                        $current['sections'][$last]['paragraphs'][] = $text;
                    }
                }
            }
            $block = [];
        };
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line) === 1) {
                $fence = !$fence;
            }
            if (!$fence && preg_match('/^ {0,3}(#{1,6})[ \t]+(.+?)[ \t#]*$/u', $line, $m) === 1) {
                $flush();
                $level = \strlen($m[1]);
                $text = self::inline($m[2]);
                if ($level <= 2) {
                    if ($current !== null) {
                        $exams[] = $current;
                    }
                    $current = ['title' => $text, 'head' => false, 'sections' => []];
                } elseif ($level === 3) {
                    $current ??= ['title' => '', 'head' => false, 'sections' => []];
                    $current['sections'][] = ['kind' => self::kind($text), 'paragraphs' => self::isKnown($text) ? [] : [$text]];
                } else {
                    $block[] = $text;
                }
                continue;
            }
            if (!$fence && trim($line) === '') {
                $flush();
                continue;
            }
            $block[] = $line;
        }
        $flush();
        if ($current !== null) {
            $exams[] = $current;
        }
        if ($exams === [] && $head['paragraphs'] !== []) {
            // No headings at all: the whole body is one exam's findings
            $exams[] = ['title' => '', 'head' => false, 'sections' => [['kind' => 'findings', 'paragraphs' => $head['paragraphs']]]];
        } elseif ($head['paragraphs'] !== []) {
            array_unshift($exams, ['title' => '', 'head' => true, 'sections' => [$head]]);
        }
        // A single exam with no heading of its own is the report's exam
        if (\count($exams) === 1 && !$exams[0]['head'] && $exams[0]['title'] === '') {
            $exams[0]['title'] = $examTitle;
        }

        return $exams;
    }

    private static function kind(string $heading): string
    {
        $folded = self::fold($heading);

        return match (true) {
            str_starts_with($folded, 'indicati') => 'history',
            str_starts_with($folded, 'tehnic') => 'procedure',
            str_starts_with($folded, 'concluzi') => 'impression',
            str_starts_with($folded, 'recomand') => 'recommendation',
            default => 'findings',
        };
    }

    /** A heading the map knows by name: it is the section's concept, not text to repeat */
    private static function isKnown(string $heading): bool
    {
        $folded = self::fold($heading);

        return $folded === 'descriere' || $folded === 'rezultat' || $folded === 'rezultate'
            || preg_match('/^(indicati|tehnic|concluzi|recomand)/', $folded) === 1;
    }

    private static function fold(string $text): string
    {
        $text = mb_strtolower(trim($text));

        return strtr($text, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
    }

    /**
     * Markdown lines to the text an SR reader shows: list markers and
     * blockquote marks kept as text, table separator rows dropped and cell
     * pipes spaced, emphasis, code marks, links and images reduced to their
     * text.
     *
     * @param list<string> $lines
     */
    private static function plain(array $lines): string
    {
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/', $line) === 1) {
                continue;
            }
            $line = preg_replace('/^\s{0,3}>\s?/', '', $line) ?? $line;
            if (str_contains($line, '|')) {
                $line = trim(preg_replace('/\s*\|\s*/', ' | ', trim($line, " \t|")) ?? $line);
            }
            $out[] = self::inline($line);
        }

        return trim(implode("\r\n", array_filter($out, static fn (string $l): bool => trim($l) !== '')));
    }

    private static function inline(string $text): string
    {
        $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $text) ?? $text;
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text) ?? $text;
        $text = preg_replace('/(\*\*|__)(.+?)\1/u', '$2', $text) ?? $text;
        $text = preg_replace('/(?<![\w*])([*_])(?=\S)(.+?)(?<=\S)\1(?![\w*])/u', '$2', $text) ?? $text;
        $text = preg_replace('/`([^`]*)`/', '$1', $text) ?? $text;

        return trim(strip_tags($text));
    }

    /** "Ionescu Maria Elena" → "IONESCU^Maria Elena" (family name first, as the archive writes names) */
    private static function personName(string $name, bool $familyFirst = true): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '') {
            return '';
        }
        $words = explode(' ', $name);
        $family = array_shift($words);

        // A signer's display name may carry a title ("Dr. Popescu Ion"): kept whole as the family component
        return $familyFirst && $words !== [] ? mb_strtoupper($family) . '^' . implode(' ', $words) : $name;
    }

    /** @return array{0: string, 1: string} DA and TM of a stored date or timestamp; '' when unknown */
    private static function dayAndTime(mixed $value): array
    {
        $both = MetaText::dateTime($value, 'Ymd', 'His');
        $date = preg_match('/^\d{8}/', $both) === 1 ? substr($both, 0, 8) : '';
        $time = preg_match('/^\d{8}(\d{6})$/', $both, $m) === 1 ? $m[1] : '';

        return [$date, $time];
    }

    private static function verificationTime(string $at): string
    {
        $stamp = MetaText::date($at, 'YmdHisO');

        return preg_match('/^\d{14}[+-]\d{4}$/', $stamp) === 1 ? $stamp : '';
    }

    /**
     * @param array{0: string, 1: string, 2: string} $name
     *
     * @return list<array{0: int, 1: string, 2: mixed}>
     */
    private static function concept(array $name): array
    {
        return [[0x0040A043, 'SQ', [self::coded($name)]]];
    }

    /**
     * @param array{0: string, 1: string, 2: string} $code
     *
     * @return list<array{0: int, 1: string, 2: mixed}>
     */
    private static function coded(array $code): array
    {
        return [[0x00080100, 'SH', $code[0]], [0x00080102, 'SH', $code[1]], [0x00080104, 'LO', $code[2]]];
    }

    /**
     * @param array{0: string, 1: string, 2: string}       $name
     * @param list<list<array{0: int, 1: string, 2: mixed}>> $children
     *
     * @return list<array{0: int, 1: string, 2: mixed}>
     */
    private static function container(array $name, array $children): array
    {
        return [
            [0x0040A040, 'CS', 'CONTAINER'],
            ...self::concept($name),
            [0x0040A050, 'CS', 'SEPARATE'],
            [0x0040A730, 'SQ', $children],
        ];
    }

    /**
     * @param array{0: string, 1: string, 2: string} $name
     *
     * @return list<array{0: int, 1: string, 2: mixed}>
     */
    private static function text(array $name, string $text): array
    {
        return self::child([[0x0040A040, 'CS', 'TEXT'], ...self::concept($name), [0x0040A160, 'UT', $text]]);
    }

    /**
     * @param array{0: string, 1: string, 2: string} $name
     * @param array{0: string, 1: string, 2: string} $value
     *
     * @return list<array{0: int, 1: string, 2: mixed}>
     */
    private static function code(array $name, array $value): array
    {
        return self::child([[0x0040A040, 'CS', 'CODE'], ...self::concept($name), [0x0040A168, 'SQ', [self::coded($value)]]]);
    }

    /**
     * A content item below the root: it relates to its parent as CONTAINS,
     * except the language item, a HAS CONCEPT MOD of the root (TID 1204).
     *
     * @param list<array{0: int, 1: string, 2: mixed}> $item
     *
     * @return list<array{0: int, 1: string, 2: mixed}>
     */
    private static function child(array $item): array
    {
        $relationship = 'CONTAINS';
        foreach ($item as [$tag, , $value]) {
            if ($tag === 0x0040A043 && ($value[0][0][2] ?? '') === '121049') {
                $relationship = 'HAS CONCEPT MOD';
            }
        }

        return [[0x0040A010, 'CS', $relationship], ...$item];
    }
}
