<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Hipobridge;

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
 * The two things the plugin does, over HippoBridge's FHIR interface:
 *
 * 1. worklist() + prefill(): recent performed exams of the configured
 *    modalities; picking one opens the guided new-report form filled from
 *    its order form (hook report.prefill) — nothing is written until the
 *    user creates the report there.
 * 2. lookup() + import(): for an existing report, find its patient in the
 *    HIS, list their other exams, and bring the chosen ones' reports in as
 *    archived pages (other radiologists' reports, never signed here —
 *    docs/FORMATS.md §3e), then fill what the report itself is missing
 *    (CNP, sex, birth year, referrer, indication, order) and add the priors
 *    — one new revision, never overwriting a value already there.
 *
 * Every page written goes through Storage (invariant 5); every HIS order
 * is referenced as `hipobridge:ServiceRequest/{id}` (order_ref).
 */
final class His
{
    public const SOURCE = 'hipobridge';

    /** A worklist/prior reference: HIS modality slug + request id */
    private const REF = '/^(ct|irm|eco|radio)\.(\d{1,12})$/';

    /**
     * @param array<string, mixed> $settings the plugin's (plugin.json defaults applied)
     */
    public function __construct(
        private readonly Client $client,
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
        private readonly NewReport $newReport,
        private readonly array $settings,
        private readonly ?DateTimeImmutable $today = null,
    ) {
    }

    public static function orderRef(string $requestId): string
    {
        return self::SOURCE . ':ServiceRequest/' . $requestId;
    }

    /**
     * Recent performed exams, newest first, each with the report already
     * made for it (a page the caller can see with its order_ref), if any.
     *
     * @return array{from: string, to: string, rows: list<array<string, mixed>>}
     *
     * @throws HisException
     */
    public function worklist(User $principal): array
    {
        $to = $this->today ?? new DateTimeImmutable('today');
        $from = $to->modify('-' . max(1, (int) $this->settings['lookback_days']) . ' days');
        $rows = [];
        // One query per modality: HippoBridge's unfiltered schedule can omit CT rows (its ARCHITECTURE.md)
        foreach ((array) $this->settings['worklist_modalities'] as $slug) {
            if (!isset(Fhir::LAB_IDS[$slug])) {
                continue;
            }
            $bundle = $this->client->get('/fhir/Schedule', [
                'start_date' => $from->format('Y-m-d'),
                'end_date' => $to->format('Y-m-d'),
                'lab_id' => Fhir::LAB_IDS[$slug],
                'status' => implode(',', Fhir::PERFORMED),
            ]);
            foreach (Fhir::scheduleRows($bundle) as $row) {
                $row['modality'] = $row['modality'] !== '' ? $row['modality'] : $slug;
                if (isset(Fhir::MODALITIES[$row['modality']]) && \in_array($row['status'], Fhir::PERFORMED, true)) {
                    $rows[$row['id']] ??= $row + ['ref' => $row['modality'] . '.' . $row['id']];
                }
            }
        }
        $rows = array_values($rows);
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['when'], (string) $a['when']));
        $existing = $this->index->findByOrderRefs(array_map(static fn (array $r): string => self::orderRef((string) $r['id']), $rows), $principal);
        foreach ($rows as $i => $row) {
            $rows[$i]['report'] = $existing[self::orderRef((string) $row['id'])] ?? null;
        }

        return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'rows' => $rows];
    }

    /**
     * The guided form's fields for worklist ref "{slug}.{id}", or null when
     * the ref is not one of ours or the order cannot be read.
     *
     * @return ?array<string, mixed>
     *
     * @throws HisException
     */
    public function prefill(string $ref, User $principal): ?array
    {
        if (preg_match(self::REF, $ref, $m) !== 1) {
            return null;
        }
        $data = $this->client->get('/fhir/ServiceRequest/' . $m[2]);
        if (($data['resourceType'] ?? null) !== 'ServiceRequest') {
            return null;
        }
        $order = Fhir::orderForm($data);
        $when = Fhir::date($order['when']);
        $region = Fhir::region($order['region']);

        return array_filter([
            'name' => $order['name'],
            'cnp' => Cnp::isValid($order['cnp']) ? $order['cnp'] : '',
            'date' => $when?->format('Y-m-d') ?? ($this->today ?? new DateTimeImmutable('today'))->format('Y-m-d'),
            'time' => $when !== null && $when->format('H:i') !== '00:00' ? $when->format('H:i') : '',
            'modality' => Fhir::MODALITIES[$m[1]],
            'site' => $this->site($principal),
            'regions' => $region !== null ? [$region] : [],
            'title' => $order['procedure'] !== '' ? mb_convert_case(mb_strtolower($order['procedure']), MB_CASE_TITLE) : '',
            'referrer' => $order['requester'],
            'indication' => $order['indication'],
            'order_ref' => self::orderRef($m[2]),
        ], static fn (mixed $v): bool => $v !== '' && $v !== []);
    }

    /**
     * Finds the report's patient in the HIS — by $hisPatientId when the user
     * picked one, else by CNP, else by name — and their exams.
     *
     * @return array{candidates: list<array<string, mixed>>, patient: ?array<string, mixed>, exams: list<array<string, mixed>>, match: ?string, mismatch: bool}
     *
     * @throws HisException
     */
    public function lookup(PageRecord $page, User $principal, ?string $hisPatientId): array
    {
        $none = ['candidates' => [], 'patient' => null, 'exams' => [], 'match' => null, 'mismatch' => false];
        $own = self::pagePatient($page);
        if ($hisPatientId !== null) {
            if (preg_match('/^[A-Za-z0-9._-]{1,32}$/', $hisPatientId) !== 1) {
                return $none;
            }
            $found = $this->client->get('/fhir/Patient/' . $hisPatientId);
        } else {
            $term = $own['cnp'] !== '' && Cnp::isValid($own['cnp']) ? $own['cnp'] : $own['name'];
            if (mb_strlen($term) < 3) {
                return $none;
            }
            $found = $this->client->get('/fhir/Patient', ['q' => $term]);
        }
        if (($found['resourceType'] ?? null) === 'Bundle') {
            $candidates = array_map([Fhir::class, 'patient'], Fhir::entries($found, 'Patient'));

            return ['candidates' => $candidates] + $none;
        }
        if (($found['resourceType'] ?? null) !== 'Patient') {
            return $none;
        }
        $patient = Fhir::patient($found);
        if ($patient['id'] === '') {
            return $none;
        }
        // A search hit may be a short record: the full one carries the CNP
        if ($hisPatientId === null && $patient['cnp'] === '') {
            $full = $this->client->get('/fhir/Patient/' . $patient['id']);
            if (($full['resourceType'] ?? null) === 'Patient') {
                $patient = Fhir::patient($full);
            }
        }
        $mismatch = $own['cnp'] !== '' && $patient['cnp'] !== '' && $own['cnp'] !== $patient['cnp'];

        $wanted = array_intersect((array) $this->settings['prior_types'], array_keys(Fhir::MODALITIES));
        $exams = array_values(array_filter(
            Fhir::requests($this->client->get('/fhir/ServiceRequest', ['patient' => $patient['id']])),
            static fn (array $r): bool => \in_array($r['type'], $wanted, true)
        ));
        $existing = $this->index->findByOrderRefs(array_map(static fn (array $r): string => self::orderRef($r['id']), $exams), $principal);
        $match = null;
        $studyDay = Fhir::date((string) ($page->frontmatter['study_date'] ?? ''))?->format('Y-m-d');
        $modalities = array_map('strval', (array) ($page->frontmatter['modality'] ?? []));
        $ownRef = (string) ($page->frontmatter['order_ref'] ?? '');
        foreach ($exams as $i => $exam) {
            $ref = self::orderRef($exam['id']);
            $exams[$i]['ref'] = $exam['type'] . '.' . $exam['id'];
            $exams[$i]['report'] = $existing[$ref] ?? null;
            $isThis = $ownRef !== '' ? $ownRef === $ref
                : Fhir::date($exam['when'])?->format('Y-m-d') === $studyDay && \in_array(Fhir::MODALITIES[$exam['type']], $modalities, true);
            if ($isThis && $match === null) {
                $match = $exams[$i]['ref'];
            }
        }

        return ['candidates' => [], 'patient' => $patient, 'exams' => $exams, 'match' => $match, 'mismatch' => $mismatch];
    }

    /**
     * Brings the chosen exams' reports in and updates $page. $refs are
     * "{slug}.{id}" of exams to import; $thisRef, if any, is the order the
     * report itself answers. Exams that already have a page are linked, not
     * imported again.
     *
     * @param list<string> $refs
     *
     * @return array{created: list<PageRecord>, linked: list<string>, empty: int, updated: ?PageRecord}
     *
     * @throws HisException
     * @throws InvalidArgumentException when the HIS patient's CNP is not the report's
     */
    public function import(PageRecord $page, User $principal, string $hisPatientId, array $refs, ?string $thisRef): array
    {
        $lookup = $this->lookup($page, $principal, $hisPatientId);
        $patient = $lookup['patient'] ?? throw new InvalidArgumentException('patient');
        if ($lookup['mismatch']) {
            throw new InvalidArgumentException('mismatch');
        }
        $exams = [];
        foreach ($lookup['exams'] as $exam) {
            $exams[$exam['ref']] = $exam;
        }
        $site = $this->site($principal);
        $namespaces = $this->newReport->modalityNamespaces();
        $name = $patient['name'] !== '' ? $patient['name'] : self::pagePatient($page)['name'];

        $created = [];
        $linked = [];
        $empty = 0;
        foreach (array_unique($refs) as $ref) {
            $exam = $exams[$ref] ?? null;
            if ($exam === null || $ref === $thisRef) {
                continue;
            }
            if ($exam['report'] !== null) {
                $linked[] = (string) $exam['report'];
                continue;
            }
            $modality = Fhir::MODALITIES[$exam['type']];
            $ns = $namespaces[$modality] ?? null;
            $report = $this->client->get('/fhir/DiagnosticReport/' . $exam['id']);
            $data = Fhir::report($report, $exam['type']);
            $when = Fhir::date($data['when']) ?? Fhir::date($exam['when']);
            if ($ns === null || $site === null || $when === null || $data['forms'] === [] || $name === '') {
                ++$empty;
                continue;
            }
            ['frontmatter' => $frontmatter, 'body' => $body] = $this->priorDocument($exam, $data, $patient, $name, $modality, $site, $when);
            $created[] = $this->storage->create(
                'reports:' . $ns . ':' . $site . ':' . $when->format('ymd') . '-' . Slug::normalize($name),
                $frontmatter,
                $body,
                $principal->username,
                'imported from HIS',
            );
        }

        $updated = $this->fillReport($page, $principal, $patient, $thisRef !== null ? ($exams[$thisRef] ?? null) : null, [
            ...array_map(static fn (PageRecord $r): string => $r->path, $created),
            ...$linked,
        ]);

        return ['created' => $created, 'linked' => $linked, 'empty' => $empty, 'updated' => $updated];
    }

    /**
     * A prior's frontmatter and body: patient block, exam, the HIS text as
     * is under the D30 headings, archived, with where it came from.
     *
     * @param array<string, mixed> $exam
     * @param array{when: string, requester: string, justification: string, forms: list<array{title: string, text: string, region: string, validator: string}>} $data
     * @param array<string, mixed> $patient
     *
     * @return array{frontmatter: array<string, mixed>, body: string}
     */
    private function priorDocument(array $exam, array $data, array $patient, string $name, string $modality, string $site, DateTimeImmutable $when): array
    {
        $titles = [];
        $regions = [];
        $body = '# ' . $name . "\n\n";
        foreach ($data['forms'] as $form) {
            $title = $form['title'] !== '' ? $form['title'] : ($exam['display'] !== '' ? $exam['display'] : $modality);
            $titles[] = $title;
            $body .= '## ' . $title . "\n\n" . $form['text'] . "\n\n";
            foreach ([$form['region'], ...$exam['regions']] as $label) {
                $region = Fhir::region((string) $label);
                if ($region !== null) {
                    $regions[$region] = true;
                }
            }
        }
        $validators = array_values(array_unique(array_filter(array_column($data['forms'], 'validator'))));
        $multi = \count($data['forms']) > 1;

        return [
            'frontmatter' => array_filter([
                'title' => $name,
                'exam_title' => implode(' + ', $titles),
                'visibility' => 'private',
                'status' => 'archived',
                'modality' => [$modality],
                'region' => $regions !== [] ? array_keys($regions) : null,
                'site' => $site,
                'study_date' => $when->format('H:i') !== '00:00' ? $when->format('Y-m-d\TH:i:sP') : $when->format('Y-m-d'),
                'exams' => $multi ? array_map(static fn (string $t): array => ['title' => $t], $titles) : null,
                'patient' => array_filter(['name' => $name, 'sex' => $patient['sex'], 'born' => $patient['born'], 'cnp' => Cnp::isValid((string) $patient['cnp']) ? $patient['cnp'] : null], static fn (mixed $v): bool => $v !== null && $v !== ''),
                'referrer' => $data['requester'] !== '' ? $data['requester'] : ($exam['requester'] !== '' ? $exam['requester'] : null),
                'indication' => $data['justification'] !== '' ? $data['justification'] : null,
                'radiologist' => $validators !== [] ? implode(', ', $validators) : null,
                'order_ref' => self::orderRef((string) $exam['id']),
                'imported_from' => self::SOURCE . ':DiagnosticReport/' . $exam['id'],
            ], static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== []),
            'body' => rtrim($body) . "\n",
        ];
    }

    /**
     * One new revision of $page with only what it is missing, and the new
     * priors appended — or null when nothing changes.
     *
     * @param array<string, mixed>      $patient
     * @param ?array<string, mixed>     $thisExam
     * @param list<string>              $priorPaths
     */
    private function fillReport(PageRecord $page, User $principal, array $patient, ?array $thisExam, array $priorPaths): ?PageRecord
    {
        $fm = $page->frontmatter;
        $before = $fm;
        $own = \is_array($fm['patient'] ?? null) ? $fm['patient'] : [];
        if (($own['cnp'] ?? '') === '' && Cnp::isValid((string) $patient['cnp'])) {
            $own['cnp'] = $patient['cnp'];
        }
        if (($own['sex'] ?? '') === '' && $patient['sex'] !== null) {
            $own['sex'] = $patient['sex'];
        }
        if (($own['born'] ?? '') === '' && $patient['born'] !== null) {
            $own['born'] = $patient['born'];
        }
        if ($own !== []) {
            $fm['patient'] = $own;
        }
        if ($thisExam !== null) {
            $order = $this->client->get('/fhir/ServiceRequest/' . $thisExam['id']);
            $form = ($order['resourceType'] ?? null) === 'ServiceRequest' ? Fhir::orderForm($order) : null;
            if ($form !== null && ($fm['referrer'] ?? '') === '' && $form['requester'] !== '') {
                $fm['referrer'] = $form['requester'];
            }
            if ($form !== null && ($fm['indication'] ?? '') === '' && $form['indication'] !== '') {
                $fm['indication'] = $form['indication'];
            }
            $fm['order_ref'] ??= self::orderRef((string) $thisExam['id']);
        }
        $priors = array_values(array_filter(array_map('strval', (array) ($fm['priors'] ?? []))));
        foreach ($priorPaths as $path) {
            if ($path !== $page->path && !\in_array($path, $priors, true)) {
                $priors[] = $path;
            }
        }
        if ($priors !== []) {
            $fm['priors'] = $priors;
        }
        if ($fm === $before) {
            return null;
        }

        return $this->storage->save($page->path, $fm, $page->body, $page->rev, $principal->username, 'patient data and priors from HIS');
    }

    /**
     * The site of pages made from HIS data: the configured one if it is a
     * site, else the first site.
     */
    public function site(User $principal): ?string
    {
        $sites = array_map('strval', array_keys($this->newReport->options($principal)['sites']));
        $wanted = (string) $this->settings['site'];

        return \in_array($wanted, $sites, true) ? $wanted : ($sites[0] ?? null);
    }

    /** @return array{name: string, cnp: string} */
    public static function pagePatient(PageRecord $page): array
    {
        $patient = \is_array($page->frontmatter['patient'] ?? null) ? $page->frontmatter['patient'] : [];
        $name = \is_scalar($patient['name'] ?? null) ? trim((string) $patient['name']) : '';

        return [
            'name' => $name !== '' ? $name : trim((string) ($page->frontmatter['title'] ?? '')),
            'cnp' => \is_scalar($patient['cnp'] ?? null) ? trim((string) $patient['cnp']) : '',
        ];
    }
}
