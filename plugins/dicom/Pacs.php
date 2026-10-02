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
use Reporion\Support\Exams;
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

    /** Most studies one multi-exam report is started from */
    public const MULTI_MAX = 8;

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
     * listed. $patient (may be empty) limits it to one patient — for a site
     * with many exams — and is either a CNP or a name (2026-10-01: all digits,
     * spaces aside, is a CNP). A CNP is asked as PatientID and the rows kept
     * only when theirs is the same; a name is asked by its first, then last
     * word, the rows kept only when every word given starts a word of the
     * patient's name. Both go in a query file, never a command line (D1);
     * with `query_by_patient` off they only filter what came back.
     *
     * @return array{rows: list<array<string, mixed>>, errors: array<string, string>}
     */
    public function worklist(User $principal, ?string $site, DateTimeImmutable $from, DateTimeImmutable $to, ?string $modality = null, string $patient = ''): array
    {
        $servers = $this->servers();
        if ($site !== null) {
            $servers = array_intersect_key($servers, [$site => true]);
        }
        $modalities = $modality !== null ? array_intersect($this->modalities(), [$modality]) : $this->modalities();
        $range = $from->format('Ymd') === $to->format('Ymd') ? $from->format('Ymd') : $from->format('Ymd') . '-' . $to->format('Ymd');
        $rows = [];
        $errors = [];
        $id = self::patientId($patient);
        $attempts = $patient === '' ? [] : $this->patientQueries($id !== '' ? ['name' => '', 'cnp' => $id] : ['name' => $patient, 'cnp' => '']);
        $words = $id === '' ? self::words($patient) : [];
        foreach ($servers as $code => $server) {
            try {
                foreach ($modalities as $modality) {
                    foreach ($attempts ?: [[]] as $private) {
                        foreach ($this->scu->findStudies($server, ['StudyDate' => $range, 'ModalitiesInStudy' => (string) $modality], $private) as $row) {
                            $uid = (string) ($row['StudyInstanceUID'] ?? '');
                            $listed = array_filter(explode('\\', (string) ($row['ModalitiesInStudy'] ?? '')));
                            // Some PACS ignore the modality key: filter here too when it came back
                            if ($uid === '' || ($listed !== [] && !\in_array($modality, $listed, true))) {
                                continue;
                            }
                            if ($id !== '' && trim((string) ($row['PatientID'] ?? '')) !== $id) {
                                continue;
                            }
                            $item = $this->row($code, (string) $modality, $row);
                            if ($words !== [] && !self::hasWords($words, (string) $item['patient'])) {
                                continue;
                            }
                            $rows[$code . ' ' . $uid] ??= $item;
                        }
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
     * The guided form's fields for worklist ref "{site}:{modality}:{uid}", or
     * for several refs of one patient joined by commas — a multi-exam report,
     * one exam per study, in the order they were done — or null when a ref is
     * not one of ours, a study is not found, or the studies are not one
     * patient, one site, one modality and one day (the path of a report names
     * all four). The first study fills the report; each exam carries its own
     * title, `study_uid` and `pacs_accession` (docs/FORMATS.md §12).
     *
     * @return ?array<string, mixed>
     *
     * @throws DicomException
     */
    public function prefill(string $ref): ?array
    {
        $refs = array_values(array_unique(explode(',', $ref)));
        if (\count($refs) > self::MULTI_MAX) {
            return null;
        }
        $studies = [];
        foreach ($refs as $one) {
            if (preg_match(self::REF, $one, $m) !== 1 || \strlen($m[3]) > 64) {
                return null;
            }
            $row = $this->study($m[1], $m[3]);
            if ($row === null) {
                return null;
            }
            $studies[] = ['site' => $m[1], 'queried' => $m[2], 'uid' => $m[3], 'row' => $row, 'item' => $this->row($m[1], $m[2], $row)];
        }
        if (\count($studies) > 1) {
            $items = array_column($studies, 'item');
            if (\count(array_unique(array_map(static fn (array $i): string => $i['site'] . '|' . $i['modality'] . '|' . substr((string) $i['sort'], 0, 8), $items))) !== 1 || !self::samePatient($items)) {
                return null;
            }
            // The order they were done in; the PACS's own order breaks a tie
            usort($studies, static fn (array $a, array $b): int => strcmp((string) $a['item']['sort'], (string) $b['item']['sort']));
        }
        $first = $studies[0];
        $row = $first['row'];
        $when = Study::when($row);
        $cnp = Study::cnp($row);
        $referrer = '';
        foreach ($studies as $study) {
            $referrer = Study::name((string) ($study['row']['ReferringPhysicianName'] ?? ''));
            if ($referrer !== '') {
                break;
            }
        }
        $more = [];
        foreach (\array_slice($studies, 1) as $study) {
            $more[] = array_filter([
                'title' => Study::title($study['row']),
                'study_uid' => $study['uid'],
                'pacs_accession' => self::accession($study['row']),
            ], static fn (string $v): bool => $v !== '');
        }

        return array_filter([
            'name' => Study::name((string) ($row['PatientName'] ?? '')),
            'cnp' => $cnp,
            'sex' => $cnp === '' ? (string) Study::sex($row) : '',
            'born' => $cnp === '' ? (string) Study::born($row) : '',
            'date' => $when?->format('Y-m-d') ?? $this->today()->format('Y-m-d'),
            'time' => $when !== null && Study::hasTime($row) ? $when->format('H:i') : '',
            'modality' => Study::modalities($row, $first['queried'])[0] ?? '',
            'site' => $first['site'],
            'title' => Study::title($row),
            'referrer' => $referrer,
            'study_uid' => $first['uid'],
            'pacs_accession' => self::accession($row),
            'more' => $more,
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
     * $day '' means every date, null the report's own exam date (study_date,
     * else the yymmdd of its path). By patient a day is the centre of a window
     * of ±`search_window_days` (default 2): the closest studies come first.
     *
     * @param ?array{name: string, cnp: string} $patient
     *
     * @return array{site: ?string, day: string, rows: list<array<string, mixed>>, byPatient: bool, window: int}
     *
     * @throws DicomException
     */
    public function lookup(PageRecord $page, ?string $site, ?string $day, ?array $patient = null): array
    {
        $fm = $page->frontmatter;
        $servers = $this->servers();
        $site ??= \is_string($fm['site'] ?? null) && isset($servers[$fm['site']]) ? $fm['site'] : (array_key_first($servers) ?? null);
        $when = ($day ?? self::studyDay($page)) !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $day ?? self::studyDay($page)) : null;
        $day = $when !== false && $when !== null ? $when->format('Y-m-d') : '';
        $ask = $patient ?? self::pagePatient($page);
        $attempts = $this->patientQueries($ask);
        if ($site === null || !isset($servers[$site]) || ($day === '' && $attempts === [])) {
            return ['site' => $site, 'day' => $day, 'rows' => [], 'byPatient' => $attempts !== [], 'window' => 0];
        }
        $ownUids = self::studyUids($fm);
        $codes = [];
        foreach ((array) ($fm['modality'] ?? []) as $modality) {
            array_push($codes, ...(self::QUERY[(string) $modality] ?? []));
        }
        $codes = array_values(array_unique($codes ?: array_map('strval', (array) $this->settings['modalities'])));

        // By patient the day is the centre of a window (±search_window_days: a report's
        // date and the PACS's study date can differ by a day or two); a day listing is that day only
        $window = $attempts !== [] && $day !== '' ? max(0, (int) ($this->settings['search_window_days'] ?? 2)) : 0;
        $range = $day;
        if ($window > 0 && $when instanceof DateTimeImmutable) {
            $range = $when->modify('-' . $window . ' days')->format('Y-m-d') . '/' . $when->modify('+' . $window . ' days')->format('Y-m-d');
        }
        $rows = $this->collect($site, $codes, $range, $attempts, $ask, $ownUids);
        $rank = ['uid' => 0, 'cnp' => 1, 'name' => 2, '' => 3];
        $rows = array_values($rows);
        // By patient: the closest to the report's day first, then the newest; a day listing: by time
        $centre = $when instanceof DateTimeImmutable ? $when->getTimestamp() : 0;
        $distance = static function (array $r) use ($window, $centre): int {
            $date = $window > 0 ? DateTimeImmutable::createFromFormat('!Ymd', substr((string) $r['sort'], 0, 8)) : null;

            return $date instanceof DateTimeImmutable ? abs($date->getTimestamp() - $centre) : 0;
        };
        usort($rows, static fn (array $a, array $b): int => $rank[$a['match']] <=> $rank[$b['match']]
            ?: $distance($a) <=> $distance($b)
            ?: ($attempts !== [] ? [$b['sort']] <=> [$a['sort']] : [$a['sort']] <=> [$b['sort']]));

        return ['site' => $site, 'day' => $day, 'rows' => \array_slice($rows, 0, 100), 'byPatient' => $attempts !== [], 'window' => $window];
    }

    /**
     * One pass over the attempts (the first that answers wins) and the
     * report's DICOM modalities on $queryDay ('' = any date, 'Y-m-d/Y-m-d' a range): the studies by
     * UID, each ranked against what we look for.
     *
     * @param list<string>                     $codes
     * @param list<array<string, string>>      $attempts patient keys per try; [[]] = none (a day query)
     * @param array{name: string, cnp: string} $ask
     * @param list<string>                     $ownUids the report's own study UIDs
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws DicomException
     */
    private function collect(string $site, array $codes, string $queryDay, array $attempts, array $ask, array $ownUids): array
    {
        $server = $this->servers()[$site];
        $rows = [];
        foreach ($attempts ?: [[]] as $private) {
            foreach ($codes as $code) {
                $match = ($queryDay !== '' ? ['StudyDate' => str_replace(['-', '/'], ['', '-'], $queryDay)] : []) + ['ModalitiesInStudy' => $code];
                foreach ($this->scu->findStudies($server, $match, $private) as $row) {
                    $uid = (string) ($row['StudyInstanceUID'] ?? '');
                    $listed = array_filter(explode('\\', (string) ($row['ModalitiesInStudy'] ?? '')));
                    if ($uid === '' || ($listed !== [] && !\in_array($code, $listed, true))) {
                        continue;
                    }
                    $item = $this->row($site, $code, $row);
                    $item['match'] = match (true) {
                        \in_array($uid, $ownUids, true) => 'uid',
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

        return $rows;
    }

    /**
     * The worklist's patient field as a CNP (any patient ID, really): its
     * digits when it is nothing but digits and spaces, else '' — a name.
     */
    private static function patientId(string $patient): string
    {
        $digits = preg_replace('/\s+/', '', $patient) ?? '';

        return preg_match('/^\d+$/', $digits) === 1 ? $digits : '';
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
     * the report is missing, or null when there is nothing to add. $auto marks
     * the revision as a bulk (not hand) edit; $fillCnp false leaves the study's CNP
     * out — a name-only match must not import an identifier.
     *
     * @throws DicomException
     * @throws InvalidArgumentException 'not-found' | 'mismatch' (another CNP) | 'linked-other' (another study) | 'multi-exam' (a study no exam of the report holds)
     */
    public function link(PageRecord $page, string $actor, string $site, string $uid, bool $auto = false, bool $fillCnp = true): ?PageRecord
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
        $multi = Exams::isMulti($fm);
        $ownUids = self::studyUids($fm);
        if ($multi && !\in_array($uid, $ownUids, true)) {
            // Which exam would it be? Exams get their studies when the report is started from the worklist
            throw new InvalidArgumentException('multi-exam');
        }
        if (!$multi && $ownUids !== [] && $ownUids[0] !== $uid) {
            throw new InvalidArgumentException('linked-other');
        }
        $before = $fm;
        $blank = static fn (mixed $v): bool => $v === null || $v === '' || $v === [];

        $patient = \is_array($fm['patient'] ?? null) ? $fm['patient'] : [];
        $name = Study::name((string) ($row['PatientName'] ?? ''));
        foreach (['name' => $name, 'cnp' => $cnp, 'sex' => Study::sex($row), 'born' => Study::born($row)] as $key => $value) {
            if (($key !== 'cnp' || $fillCnp) && $blank($patient[$key] ?? null) && !$blank($value)) {
                $patient[$key] = $value;
            }
        }
        if ($patient !== []) {
            $fm['patient'] = $patient;
        }
        foreach (['exam_title' => $multi ? '' : Study::title($row), 'referrer' => Study::name((string) ($row['ReferringPhysicianName'] ?? '')), 'study_uid' => $multi ? '' : $uid, 'pacs_accession' => $multi ? '' : self::accession($row), 'pacs_institution' => self::text($row['InstitutionName'] ?? ''), 'pacs_device' => self::device($row)] as $key => $value) {
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

        return $this->storage->save($page->path, $fm, $page->body, $page->rev, $actor, 'patient and exam data from the PACS', $auto);
    }

    /**
     * Fills the report's blank patient fields (name, CNP, sex, birth date) from a
     * lookup row, without linking a study: for a report whose day holds several
     * studies of the one patient, where picking a single study would be a guess.
     * $fillCnp false leaves the CNP out (a name-only match). Null when there is
     * nothing to add.
     *
     * @param array<string, mixed> $row a `lookup()` row
     *
     * @throws InvalidArgumentException 'mismatch' (another CNP)
     */
    public function linkPatient(PageRecord $page, string $actor, array $row, bool $auto = false, bool $fillCnp = true): ?PageRecord
    {
        $own = self::pagePatient($page);
        $cnp = (string) ($row['cnp'] ?? '');
        if ($own['cnp'] !== '' && $cnp !== '' && $own['cnp'] !== $cnp) {
            throw new InvalidArgumentException('mismatch');
        }
        $fm = $page->frontmatter;
        $patient = \is_array($fm['patient'] ?? null) ? $fm['patient'] : [];
        foreach (['name' => $row['patient'] ?? '', 'cnp' => $cnp, 'sex' => $row['sex'] ?? '', 'born' => $row['born'] ?? ''] as $key => $value) {
            $blank = ($patient[$key] ?? null) === null || $patient[$key] === '';
            if (($key !== 'cnp' || $fillCnp) && $blank && $value !== '' && $value !== null) {
                $patient[$key] = $value;
            }
        }
        if ($patient === ($fm['patient'] ?? [])) {
            return null;
        }
        $fm['patient'] = $patient;

        return $this->storage->save($page->path, $fm, $page->body, $page->rev, $actor, 'patient data from the PACS', $auto);
    }

    /**
     * True when every row is the same patient: one CNP on all of them, or no
     * CNP on any and the same name, birth date and sex.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function samePatient(array $rows): bool
    {
        $first = $rows[0] ?? null;
        if ($first === null) {
            return false;
        }
        foreach ($rows as $r) {
            if ((string) $r['cnp'] !== (string) $first['cnp']) {
                return false;
            }
            if ((string) $first['cnp'] === '' && (!self::sameName((string) $first['patient'], (string) $r['patient'])
                || (string) $r['born'] !== (string) $first['born'] || (string) $r['sex'] !== (string) $first['sex'])) {
                return false;
            }
        }

        return true;
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
            'institution' => self::text($row['InstitutionName'] ?? ''),
            'device' => self::device($row),
            // What studies must share to be one multi-exam report: the worklist ticks only within a group
            'group' => $site . '|' . (Study::modalities($row, $queried)[0] ?? '') . '|' . substr($when?->format('Ymd') ?? '', 0, 8) . '|'
                . substr(sha1(Study::cnp($row) !== '' ? 'c' . Study::cnp($row) : 'n' . implode('-', self::sortedWords(Study::name((string) ($row['PatientName'] ?? '')))) . '|' . Study::born($row) . '|' . Study::sex($row)), 0, 12),
        ];
    }

    /** @param array<string, string> $row manufacturer, model and station as one line; '' when the PACS sent none */
    private static function device(array $row): string
    {
        $parts = array_filter([self::text($row['Manufacturer'] ?? ''), self::text($row['ManufacturerModelName'] ?? '')], static fn (string $v): bool => $v !== '');
        $station = self::text($row['StationName'] ?? '');

        return implode(' ', $parts) . ($station !== '' ? ($parts !== [] ? ' / ' : '') . $station : '');
    }

    /** a DICOM text value, trimmed and bounded, no control characters */
    private static function text(mixed $v): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', (string) $v) ?? ''), 0, 64);
    }

    /** @param array<string, string> $row */
    private static function accession(array $row): string
    {
        $accession = trim((string) ($row['AccessionNumber'] ?? ''));

        return preg_match(NewReport::PACS_ACCESSION, $accession) === 1 ? $accession : '';
    }

    /** @return list<string> the words of a name, normalised (no diacritics or case); [] for none */
    private static function words(string $name): array
    {
        try {
            return array_values(array_filter(explode('-', Slug::normalize($name)), static fn (string $w): bool => $w !== ''));
        } catch (InvalidArgumentException) {
            return [];
        }
    }

    /** @return list<string> */
    private static function sortedWords(string $name): array
    {
        $words = self::words($name);
        sort($words);

        return $words;
    }

    /** Whether every typed word begins a word of $patient's name */
    private static function hasWords(array $typed, string $patient): bool
    {
        $have = self::words($patient);
        foreach ($typed as $word) {
            if (array_filter($have, static fn (string $h): bool => str_starts_with($h, $word)) === []) {
                return false;
            }
        }

        return true;
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

    /**
     * The study UIDs a report holds: its own, or its exams' (a multi-exam
     * report keeps them per exam, docs/FORMATS.md §12), in order.
     *
     * @param array<string, mixed> $fm
     *
     * @return list<string>
     */
    public static function studyUids(array $fm): array
    {
        $uids = [];
        foreach ([$fm['study_uid'] ?? null, ...array_map(static fn (array $e): mixed => $e['study_uid'] ?? null, array_filter((array) ($fm['exams'] ?? []), 'is_array'))] as $uid) {
            if (\is_scalar($uid) && (string) $uid !== '') {
                $uids[] = (string) $uid;
            }
        }

        return array_values(array_unique($uids));
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

    /** The day the report says the exam was done, as Y-m-d: study_date, else the yymmdd of its path; '' when neither */
    public static function studyDay(PageRecord $page): string
    {
        $date = self::date((string) ($page->frontmatter['study_date'] ?? ''));
        if ($date === null && preg_match('/^(\d{6})-/', (string) substr((string) strrchr(':' . $page->path, ':'), 1), $m) === 1) {
            $parsed = DateTimeImmutable::createFromFormat('!ymd', $m[1]);
            $date = $parsed !== false && $parsed->format('ymd') === $m[1] ? $parsed : null;
        }

        return $date?->format('Y-m-d') ?? '';
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $m) === 1 ? (DateTimeImmutable::createFromFormat('!Y-m-d', $m[0]) ?: null) : null;
    }
}
