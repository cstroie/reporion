<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Dicom;

use DateTimeImmutable;
use InvalidArgumentException;
use Reporion\Auth\User;
use Reporion\Index\IndexInterface;
use Reporion\Service\NewReport;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\Cnp;
use Reporion\Support\Slug;

/**
 * What the plugin does with each site's PACS:
 *
 * 1. worklist() + prefill(): the studies of the configured modalities in a
 *    date range; picking one opens the guided new-report form filled from
 *    the study (hook report.prefill) — nothing is written until the user
 *    creates the report there.
 * 2. lookup() + link(): for an existing report, the studies of its site,
 *    day and modality, the likeliest first (same CNP, then same name); the
 *    one the user picks fills what the report is missing — patient, time,
 *    exam title, referrer, study UID, PACS accession — in one new revision,
 *    never overwriting a value already there.
 *
 * The worklist queries by date and modality. A report's PACS tab asks for
 * the report's patient — PatientID (the CNP) or PatientName — through a
 * query file: a name or CNP never reaches a command line, a URL or a log
 * (D39, amended 2026-09-30). Candidates are ranked against the report here,
 * after the answer.
 */
final class Pacs
{
    public const SOURCE = 'dicom';

    /** Reporion modality → the DICOM modalities to query for it */
    private const QUERY = ['CT' => ['CT'], 'MR' => ['MR'], 'US' => ['US'], 'XR' => ['CR', 'DX'], 'MG' => ['MG'], 'PET' => ['PT']];

    /** A worklist reference: site code, DICOM modality, study UID */
    private const REF = '/^([a-z0-9][a-z0-9_-]{0,31}):([A-Z]{2}):([0-9]+(?:\.[0-9]+)*)$/';

    /**
     * @param array<string, mixed> $settings the plugin's (plugin.json defaults applied)
     */
    public function __construct(
        private readonly Scu $scu,
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
        private readonly NewReport $newReport,
        private readonly array $settings,
        private readonly ?DateTimeImmutable $today = null,
    ) {
    }

    /**
     * The sites with a PACS configured — host, its AE title and ours for it
     * (each PACS identifies us by its own): code → {host, port, aet, calling}.
     *
     * @return array<string, array{host: string, port: int, aet: string, calling: string}>
     */
    public function servers(): array
    {
        $servers = [];
        foreach (\is_array($this->settings['servers'] ?? null) ? $this->settings['servers'] : [] as $site => $row) {
            if (\is_array($row) && ($row['host'] ?? '') !== '' && ($row['aet'] ?? '') !== '' && ($row['calling_aet'] ?? '') !== '') {
                $servers[(string) $site] = ['host' => (string) $row['host'], 'port' => (int) ($row['port'] ?: 104), 'aet' => (string) $row['aet'], 'calling' => (string) $row['calling_aet']];
            }
        }

        return $servers;
    }

    public function today(): DateTimeImmutable
    {
        return $this->today ?? new DateTimeImmutable('today');
    }

    /**
     * Studies from $from to $to (inclusive) at $site (or every site with a
     * PACS), newest first, each with the report already made for it, if any.
     * A site that does not answer is reported in `errors`, the others still
     * listed.
     *
     * @return array{rows: list<array<string, mixed>>, errors: array<string, string>}
     */
    public function worklist(User $principal, ?string $site, DateTimeImmutable $from, DateTimeImmutable $to, ?string $modality = null): array
    {
        $servers = $this->servers();
        if ($site !== null) {
            $servers = array_intersect_key($servers, [$site => true]);
        }
        $modalities = $modality !== null ? array_intersect($this->modalities(), [$modality]) : $this->modalities();
        $range = $from->format('Ymd') === $to->format('Ymd') ? $from->format('Ymd') : $from->format('Ymd') . '-' . $to->format('Ymd');
        $rows = [];
        $errors = [];
        foreach ($servers as $code => $server) {
            try {
                foreach ($modalities as $modality) {
                    foreach ($this->scu->findStudies($server, ['StudyDate' => $range, 'ModalitiesInStudy' => (string) $modality]) as $row) {
                        $uid = (string) ($row['StudyInstanceUID'] ?? '');
                        $listed = array_filter(explode('\\', (string) ($row['ModalitiesInStudy'] ?? '')));
                        // Some PACS ignore the modality key: filter here too when it came back
                        if ($uid === '' || ($listed !== [] && !\in_array($modality, $listed, true))) {
                            continue;
                        }
                        $rows[$code . ' ' . $uid] ??= $this->row($code, (string) $modality, $row);
                    }
                }
            } catch (DicomException $e) {
                $errors[$code] = $e->getMessage();
            }
        }
        $rows = array_values($rows);
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['sort'], (string) $a['sort']));
        $existing = $this->index->findByStudyUids(array_column($rows, 'uid'), $principal);
        foreach ($rows as $i => $row) {
            $rows[$i]['report'] = $existing[$row['uid']] ?? null;
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * The worklist's modalities, from the settings: the DICOM codes this
     * plugin knows, in the order the owner listed them.
     *
     * @return list<string>
     */
    public function modalities(): array
    {
        return array_values(array_unique(array_filter(array_map('strval', (array) $this->settings['modalities']), static fn (string $m): bool => isset(Study::MODALITIES[$m]))));
    }

    /**
     * The guided form's fields for worklist ref "{site}:{modality}:{uid}",
     * or null when the ref is not one of ours or the study is not found.
     *
     * @return ?array<string, mixed>
     *
     * @throws DicomException
     */
    public function prefill(string $ref): ?array
    {
        if (preg_match(self::REF, $ref, $m) !== 1 || \strlen($m[3]) > 64) {
            return null;
        }
        $row = $this->study($m[1], $m[3]);
        if ($row === null) {
            return null;
        }
        $when = Study::when($row);
        $cnp = Study::cnp($row);

        return array_filter([
            'name' => Study::name((string) ($row['PatientName'] ?? '')),
            'cnp' => $cnp,
            'sex' => $cnp === '' ? (string) Study::sex($row) : '',
            'born' => $cnp === '' ? (string) Study::born($row) : '',
            'date' => $when?->format('Y-m-d') ?? $this->today()->format('Y-m-d'),
            'time' => $when !== null && Study::hasTime($row) ? $when->format('H:i') : '',
            'modality' => Study::modalities($row, $m[2])[0] ?? '',
            'site' => $m[1],
            'title' => Study::title($row),
            'referrer' => Study::name((string) ($row['ReferringPhysicianName'] ?? '')),
            'study_uid' => $m[3],
            'pacs_accession' => self::accession($row),
        ], static fn (mixed $v): bool => $v !== '' && $v !== []);
    }

    /**
     * The report's studies at its site, the likeliest first: `match` is
     * 'uid' (already linked), 'cnp', 'name' or ''.
     *
     * By patient (the default, when there is a CNP or a name to look for and
     * the owner has not turned it off): the PACS is asked for that PatientID,
     * then that PatientName — a prefix of it, then any word of it — and the
     * first that answers wins; the day only narrows it when there is one. The
     * identifiers travel in a query file (Scu), never on a command line.
     * Without them: every study of the day for the report's modalities.
     * $patient overrides the report's own name and CNP (the tab's form);
     * $day '' means every date, null the report's study date.
     *
     * @param ?array{name: string, cnp: string} $patient
     *
     * @return array{site: ?string, day: string, rows: list<array<string, mixed>>, byPatient: bool}
     *
     * @throws DicomException
     */
    public function lookup(PageRecord $page, ?string $site, ?string $day, ?array $patient = null): array
    {
        $fm = $page->frontmatter;
        $servers = $this->servers();
        $site ??= \is_string($fm['site'] ?? null) && isset($servers[$fm['site']]) ? $fm['site'] : (array_key_first($servers) ?? null);
        $when = $day === null ? self::date((string) ($fm['study_date'] ?? '')) : ($day !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $day) : null);
        $day = $when !== false && $when !== null ? $when->format('Y-m-d') : '';
        $ask = $patient ?? self::pagePatient($page);
        $attempts = $this->patientQueries($ask);
        if ($site === null || !isset($servers[$site]) || ($day === '' && $attempts === [])) {
            return ['site' => $site, 'day' => $day, 'rows' => [], 'byPatient' => $attempts !== []];
        }
        $ownUid = \is_scalar($fm['study_uid'] ?? null) ? (string) $fm['study_uid'] : '';
        $codes = [];
        foreach ((array) ($fm['modality'] ?? []) as $modality) {
            array_push($codes, ...(self::QUERY[(string) $modality] ?? []));
        }
        $codes = array_values(array_unique($codes ?: array_map('strval', (array) $this->settings['modalities'])));

        $rows = [];
        foreach ($attempts ?: [[]] as $private) {
            foreach ($codes as $code) {
                $match = ($day !== '' ? ['StudyDate' => str_replace('-', '', $day)] : []) + ['ModalitiesInStudy' => $code];
                foreach ($this->scu->findStudies($servers[$site], $match, $private) as $row) {
                    $uid = (string) ($row['StudyInstanceUID'] ?? '');
                    $listed = array_filter(explode('\\', (string) ($row['ModalitiesInStudy'] ?? '')));
                    if ($uid === '' || ($listed !== [] && !\in_array($code, $listed, true))) {
                        continue;
                    }
                    $item = $this->row($site, $code, $row);
                    $item['match'] = match (true) {
                        $ownUid !== '' && $ownUid === $uid => 'uid',
                        $ask['cnp'] !== '' && $ask['cnp'] === $item['cnp'] => 'cnp',
                        $ask['name'] !== '' && self::sameName($ask['name'], (string) $item['patient']) => 'name',
                        default => '',
                    };
                    $rows[$uid] ??= $item;
                }
            }
            if ($rows !== []) {
                break;
            }
        }
        $rank = ['uid' => 0, 'cnp' => 1, 'name' => 2, '' => 3];
        $rows = array_values($rows);
        $newestFirst = $attempts !== [];
        usort($rows, static fn (array $a, array $b): int => $rank[$a['match']] <=> $rank[$b['match']] ?: ($newestFirst ? [$b['sort']] <=> [$a['sort']] : [$a['sort']] <=> [$b['sort']]));

        return ['site' => $site, 'day' => $day, 'rows' => \array_slice($rows, 0, 100), 'byPatient' => $attempts !== []];
    }

    /**
     * What to ask the PACS about a patient, most exact first: the CNP as
     * PatientID, then the name (ASCII, upper case) as a prefix pattern, then
     * as a contains pattern on its last word — a report may write the given
     * name first. Empty when there is nothing to ask or the owner switched
     * patient queries off.
     *
     * @param array{name: string, cnp: string} $ask
     *
     * @return list<array<string, string>>
     */
    public function patientQueries(array $ask): array
    {
        if (($this->settings['query_by_patient'] ?? true) === false) {
            return [];
        }
        $out = [];
        $id = preg_replace('/\s+/', '', $ask['cnp']) ?? '';
        if (preg_match('/^[A-Za-z0-9._-]{4,32}$/', $id) === 1) {
            $out[] = ['PatientID' => $id];
        }
        try {
            $words = array_values(array_filter(explode('-', Slug::normalize($ask['name'])), static fn (string $w): bool => \strlen($w) >= 2));
        } catch (InvalidArgumentException) {
            $words = [];
        }
        if ($words !== []) {
            $out[] = ['PatientName' => strtoupper($words[0]) . '*'];
            if (\count($words) > 1) {
                $out[] = ['PatientName' => '*' . strtoupper($words[\count($words) - 1]) . '*'];
            }
        }

        return $out;
    }

    /**
     * Links $page to study $uid at $site: one new revision with only what
     * the report is missing, or null when there is nothing to add.
     *
     * @throws DicomException
     * @throws InvalidArgumentException 'not-found' | 'mismatch' (another CNP) | 'linked-other' (another study)
     */
    public function link(PageRecord $page, User $principal, string $site, string $uid): ?PageRecord
    {
        if (!isset($this->servers()[$site]) || \strlen($uid) > 64 || preg_match(NewReport::STUDY_UID, $uid) !== 1) {
            throw new InvalidArgumentException('not-found');
        }
        $row = $this->study($site, $uid) ?? throw new InvalidArgumentException('not-found');
        $fm = $page->frontmatter;
        $own = self::pagePatient($page);
        $cnp = Study::cnp($row);
        if ($own['cnp'] !== '' && $cnp !== '' && $own['cnp'] !== $cnp) {
            throw new InvalidArgumentException('mismatch');
        }
        $ownUid = \is_scalar($fm['study_uid'] ?? null) ? (string) $fm['study_uid'] : '';
        if ($ownUid !== '' && $ownUid !== $uid) {
            throw new InvalidArgumentException('linked-other');
        }
        $before = $fm;
        $blank = static fn (mixed $v): bool => $v === null || $v === '' || $v === [];

        $patient = \is_array($fm['patient'] ?? null) ? $fm['patient'] : [];
        $name = Study::name((string) ($row['PatientName'] ?? ''));
        foreach (['name' => $name, 'cnp' => $cnp, 'sex' => Study::sex($row), 'born' => Study::born($row)] as $key => $value) {
            if ($blank($patient[$key] ?? null) && !$blank($value)) {
                $patient[$key] = $value;
            }
        }
        if ($patient !== []) {
            $fm['patient'] = $patient;
        }
        foreach (['exam_title' => Study::title($row), 'referrer' => Study::name((string) ($row['ReferringPhysicianName'] ?? '')), 'study_uid' => $uid, 'pacs_accession' => self::accession($row)] as $key => $value) {
            if ($blank($fm[$key] ?? null) && $value !== '') {
                $fm[$key] = $value;
            }
        }
        // Modality and time only when they agree with the report's path (its namespace and day)
        $segments = explode(':', $page->path);
        $modality = Study::modalities($row)[0] ?? null;
        if ($blank($fm['modality'] ?? null) && $modality !== null && ($this->newReport->modalityNamespaces()[$modality] ?? null) === ($segments[1] ?? null)) {
            $fm['modality'] = [$modality];
        }
        $when = Study::when($row);
        $pathDay = preg_match('/^(\d{6})-/', (string) end($segments), $m) === 1 ? $m[1] : null;
        if ($when !== null && $pathDay === $when->format('ymd')) {
            $current = \is_scalar($fm['study_date'] ?? null) ? (string) $fm['study_date'] : '';
            if ($current === '') {
                $fm['study_date'] = Study::hasTime($row) ? $when->format('Y-m-d\TH:i:sP') : $when->format('Y-m-d');
            } elseif (Study::hasTime($row) && $current === $when->format('Y-m-d')) {
                $fm['study_date'] = $when->format('Y-m-d\TH:i:sP');
            }
        }
        if ($fm === $before) {
            return null;
        }

        return $this->storage->save($page->path, $fm, $page->body, $page->rev, $principal->username, 'patient and exam data from the PACS');
    }

    /**
     * One study by UID at $site, or null.
     *
     * @return ?array<string, string>
     *
     * @throws DicomException
     */
    private function study(string $site, string $uid): ?array
    {
        $server = $this->servers()[$site] ?? null;
        if ($server === null) {
            return null;
        }
        foreach ($this->scu->findStudies($server, ['StudyInstanceUID' => $uid]) as $row) {
            if (($row['StudyInstanceUID'] ?? '') === $uid) {
                return $row;
            }
        }

        return null;
    }

    /**
     * A screen row.
     *
     * @param array<string, string> $row
     *
     * @return array<string, mixed>
     */
    private function row(string $site, string $queried, array $row): array
    {
        $when = Study::when($row);

        return [
            'site' => $site,
            'uid' => (string) $row['StudyInstanceUID'],
            'ref' => $site . ':' . $queried . ':' . $row['StudyInstanceUID'],
            'when' => $when !== null ? $when->format(Study::hasTime($row) ? 'Y-m-d H:i' : 'Y-m-d') : '',
            'sort' => $when?->format('YmdHi') ?? '',
            'modality' => implode(', ', Study::modalities($row, $queried)),
            'patient' => Study::name((string) ($row['PatientName'] ?? '')),
            'cnp' => Study::cnp($row),
            'born' => Study::born($row),
            'sex' => Study::sex($row),
            'description' => (string) ($row['StudyDescription'] ?? ''),
            'accession' => self::accession($row),
            'referrer' => Study::name((string) ($row['ReferringPhysicianName'] ?? '')),
        ];
    }

    /** @param array<string, string> $row */
    private static function accession(array $row): string
    {
        $accession = trim((string) ($row['AccessionNumber'] ?? ''));

        return preg_match(NewReport::PACS_ACCESSION, $accession) === 1 ? $accession : '';
    }

    /** Same person by name: the same words, diacritics and case aside */
    public static function sameName(string $a, string $b): bool
    {
        try {
            $wa = explode('-', Slug::normalize($a));
            $wb = explode('-', Slug::normalize($b));
        } catch (InvalidArgumentException) {
            return false;
        }
        sort($wa);
        sort($wb);

        return $wa === $wb;
    }

    /** @return array{name: string, cnp: string} */
    public static function pagePatient(PageRecord $page): array
    {
        $patient = \is_array($page->frontmatter['patient'] ?? null) ? $page->frontmatter['patient'] : [];
        $name = \is_scalar($patient['name'] ?? null) ? trim((string) $patient['name']) : '';
        $cnp = \is_scalar($patient['cnp'] ?? null) ? trim((string) $patient['cnp']) : '';

        return [
            'name' => $name !== '' ? $name : trim((string) ($page->frontmatter['title'] ?? '')),
            'cnp' => Cnp::isValid($cnp) ? $cnp : '',
        ];
    }

    /** The report's study date as Y-m-d, '' when it has none */
    public static function studyDay(PageRecord $page): string
    {
        return self::date((string) ($page->frontmatter['study_date'] ?? ''))?->format('Y-m-d') ?? '';
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $m) === 1 ? (DateTimeImmutable::createFromFormat('!Y-m-d', $m[0]) ?: null) : null;
    }
}
