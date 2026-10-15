<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Hipobridge;

use DateTimeImmutable;
use InvalidArgumentException;
use Reporion\Auth\SystemActor;
use Reporion\Auth\User;
use Reporion\Index\IndexInterface;
use Reporion\Service\NewReport;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\Cnp;
use Reporion\Support\Exams;
use Reporion\Support\PageTitle;
use Reporion\Support\ReportPath;
use Reporion\Support\Slug;

/**
 * The two things the plugin does, over HippoBridge's FHIR interface:
 *
 * 1. worklist() + prefill(): recent performed exams of the configured
 *    modalities; picking one opens the guided new-report form filled from
 *    its order form (hook report.prefill): nothing is written until the
 *    user creates the report there.
 * 2. lookup() + import(): for an existing report, find its patient in the
 *    HIS, list their other exams, and bring the chosen ones' reports in as
 *    archived pages (other radiologists' reports, never signed here
 *    docs/FORMATS.md §3e), then fill what the report itself is missing
 *    (CNP, sex, birth year, referrer, indication, order) and add the priors
 *    one new revision, never overwriting a value already there.
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
     * @param ?string $modality a DICOM code (CT, MR …) to list only that modality
     *
     * @return array{from: string, to: string, rows: list<array<string, mixed>>}
     *
     * @throws HisException
     */
    public function worklist(User $principal, ?string $modality = null): array
    {
        $to = $this->today ?? new DateTimeImmutable('today');
        $from = $to->modify('-' . max(1, (int) $this->settings['lookback_days']) . ' days');
        $rows = [];
        // One query per modality: HippoBridge's unfiltered schedule can omit CT rows (its ARCHITECTURE.md)
        // sent side by side, read in the owner's order; the first that failed fails the list, as before
        $requests = [];
        foreach ((array) $this->settings['worklist_modalities'] as $slug) {
            // ?modality=CT (the new-report form's choice) narrows the list to that modality
            if (isset(Fhir::LAB_IDS[$slug]) && ($modality === null || (Fhir::MODALITIES[$slug] ?? null) === $modality)) {
                $requests[$slug] = ['/fhir/Schedule', [
                    'start_date' => $from->format('Y-m-d'),
                    'end_date' => $to->format('Y-m-d'),
                    'lab_id' => Fhir::LAB_IDS[$slug],
                    'status' => implode(',', Fhir::WORKLIST),
                    'limit' => max(1, (int) ($this->settings['worklist_limit'] ?? 200)),
                ]];
            }
        }
        foreach ($this->client->getMany($requests) as $slug => $bundle) {
            if ($bundle instanceof HisException) {
                throw $bundle;
            }
            foreach (Fhir::scheduleRows($bundle) as $row) {
                $row['modality'] = $row['modality'] !== '' ? $row['modality'] : $slug;
                if (isset(Fhir::MODALITIES[$row['modality']]) && \in_array($row['status'], Fhir::WORKLIST, true)) {
                    $rows[$row['id']] ??= $row + ['ref' => $row['modality'] . '.' . $row['id']];
                }
            }
        }
        $rows = array_values($rows);
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['performed'] ?: $b['when']), (string) ($a['performed'] ?: $a['when'])));
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
     * Finds the report's patient in the HIS: by $hisPatientId when the user
     * picked one, else by CNP, else by name, and their exams.
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
            if (preg_match('/^(?!\.+$)[A-Za-z0-9._-]{1,32}$/D', $hisPatientId) !== 1) {
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
        $shared = Exams::report($page->frontmatter);
        $studyDay = $shared['date'] !== '' ? $shared['date'] : null;
        $modalities = $shared['modality'];
        // The orders the report's exams already name (one per exam)
        $ownRefs = array_values(array_filter(array_map(static fn (array $e): string => (string) ($e['order_ref'] ?? ''), Exams::of($page->frontmatter)), static fn (string $r): bool => $r !== ''));
        foreach ($exams as $i => $exam) {
            $ref = self::orderRef($exam['id']);
            $exams[$i]['ref'] = $exam['type'] . '.' . $exam['id'];
            $exams[$i]['report'] = $existing[$ref] ?? null;
            $isThis = $ownRefs !== [] ? \in_array($ref, $ownRefs, true)
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

        // The reports to import, asked side by side; a failed one stops the
        // import where the loop reaches it, as asking one at a time did
        $fetch = [];
        foreach (array_unique($refs) as $ref) {
            $exam = $exams[$ref] ?? null;
            if ($exam !== null && $ref !== $thisRef && $exam['report'] === null) {
                $fetch[$ref] = ['/fhir/DiagnosticReport/' . $exam['id']];
            }
        }
        $fetched = $fetch !== [] ? $this->client->getMany($fetch) : [];

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
            $report = $fetched[$ref];
            if ($report instanceof HisException) {
                throw $report;
            }
            $data = Fhir::report($report, $exam['type']);
            $when = Fhir::date($data['when']) ?? Fhir::date($exam['when']);
            if ($ns === null || $site === null || $when === null || $data['forms'] === [] || $name === '') {
                ++$empty;
                continue;
            }
            ['frontmatter' => $frontmatter, 'body' => $body] = $this->priorDocument($exam, $data, $patient, $name, $modality, $site, $when);
            $created[] = $this->storage->create(
                ReportPath::pathFor($ns, $site, $when->format('ymd'), Slug::normalize($name)),
                $frontmatter,
                $body,
                SystemActor::IMPORTER,
                'imported from HippoBridge',
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
        // One exam per form, each with its title, time and regions; the order is the first exam's
        $exams = [];
        $body = '# ' . $name . "\n\n";
        $time = $when->format('H:i') !== '00:00' ? $when->format('H:i') : '';
        foreach ($data['forms'] as $n => $form) {
            $title = $form['title'] !== '' ? $form['title'] : ($exam['display'] !== '' ? $exam['display'] : $modality);
            $body .= '## ' . $title . "\n\n" . $form['text'] . "\n\n";
            $regions = [];
            foreach ([$form['region'], ...$exam['regions']] as $label) {
                $region = Fhir::region((string) $label);
                if ($region !== null) {
                    $regions[$region] = true;
                }
            }
            $exams[] = array_filter([
                'title' => $title,
                'time' => $time,
                'region' => array_keys($regions),
                'order_ref' => $n === 0 ? self::orderRef((string) $exam['id']) : '',
            ], static fn (mixed $v): bool => $v !== '' && $v !== []);
        }
        $validators = array_values(array_unique(array_filter(array_column($data['forms'], 'validator'))));

        return [
            'frontmatter' => array_filter([
                'title' => $name,
                'visibility' => 'private',
                'status' => 'archived',
                'modality' => [$modality],
                'site' => $site,
                'study_date' => $when->format('Y-m-d'),
                'exams' => $exams,
                'patient' => array_filter(['name' => $name, 'sex' => $patient['sex'], 'born' => $patient['born'], 'cnp' => Cnp::isValid((string) $patient['cnp']) ? $patient['cnp'] : null], static fn (mixed $v): bool => $v !== null && $v !== ''),
                'referrer' => $data['requester'] !== '' ? $data['requester'] : ($exam['requester'] !== '' ? $exam['requester'] : null),
                'indication' => $data['justification'] !== '' ? $data['justification'] : null,
                'radiologist' => $validators !== [] ? implode(', ', $validators) : null,
                'imported_from' => self::SOURCE . ':DiagnosticReport/' . $exam['id'],
            ], static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== []),
            'body' => rtrim($body) . "\n",
        ];
    }

    /**
     * One new revision of $page with only what it is missing, and the new
     * priors appended, or null when nothing changes.
     *
     * @param array<string, mixed>      $patient
     * @param ?array<string, mixed>     $thisExam
     * @param list<string>              $priorPaths
     */
    private function fillReport(PageRecord $page, User $principal, array $patient, ?array $thisExam, array $priorPaths): ?PageRecord
    {
        // The report in its current shape: what an order fills is the report's (modality, day, referrer,
        // indication) or one exam's (title, region, time, order), never a copy of one on the other
        $fm = Exams::normalize($page->frontmatter, $page->frontmatter);
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
        if (($own['name'] ?? '') === '' && $patient['name'] !== '') {
            $own['name'] = $patient['name'];
        }
        if ($own !== []) {
            $fm['patient'] = $own;
        }
        if ($thisExam !== null) {
            $order = $this->client->get('/fhir/ServiceRequest/' . $thisExam['id']);
            $form = ($order['resourceType'] ?? null) === 'ServiceRequest' ? Fhir::orderForm($order) : null;
            // The exam the order is: the one that names it, else a single-exam report's; on a
            // report of several that names none, only the report's own fields are filled
            $ref = self::orderRef((string) $thisExam['id']);
            $at = null;
            foreach ($fm['exams'] as $i => $e) {
                if (\is_array($e) && ($e['order_ref'] ?? null) === $ref) {
                    $at = $i;
                }
            }
            $at ??= \count($fm['exams']) === 1 ? 0 : null;
            if ($form !== null) {
                $fm = $this->fillFromOrder($fm, $page->path, $form, $thisExam, $at);
            }
            if ($at !== null) {
                $fm['exams'][$at]['order_ref'] ??= $ref;
            }
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

        return $this->storage->save($page->path, $fm, $page->body, $page->rev, SystemActor::IMPORTER, 'patient data and priors from HippoBridge');
    }

    /**
     * The blanks of $fm the order form of the report's own exam can fill.
     * The report's path already says its modality, site and day, and the
     * exam is the user's pick: the study time and the modality are filled
     * only when they agree with the path, and the site never is. The
     * referrer, the indication, the modality and the day are the report's;
     * the title, region and time are those of exam $at (none: not filled).
     *
     * @param array<string, mixed>                                                                          $fm
     * @param array{when: string, procedure: string, region: string, indication: string, requester: string} $form
     * @param array<string, mixed>                                                                          $exam
     * @param ?int                                                                                          $at   the report's exam the order is
     *
     * @return array<string, mixed>
     */
    private function fillFromOrder(array $fm, string $path, array $form, array $exam, ?int $at): array
    {
        $isBlank = static fn (mixed $v): bool => $v === null || $v === '' || $v === [];
        $blank = static fn (string $key): bool => $isBlank($fm[$key] ?? null);
        if ($blank('referrer') && $form['requester'] !== '') {
            $fm['referrer'] = $form['requester'];
        }
        if ($blank('indication') && $form['indication'] !== '') {
            $fm['indication'] = $form['indication'];
        }
        if ($at !== null && $isBlank($fm['exams'][$at]['title'] ?? null) && $form['procedure'] !== '') {
            $fm['exams'][$at]['title'] = mb_convert_case(mb_strtolower($form['procedure']), MB_CASE_TITLE);
        }
        if ($at !== null && $isBlank($fm['exams'][$at]['region'] ?? null)) {
            $regions = array_values(array_unique(array_filter(array_map(
                static fn (mixed $label): ?string => Fhir::region((string) $label),
                [$form['region'], ...(array) ($exam['regions'] ?? [])],
            ))));
            if ($regions !== []) {
                $fm['exams'][$at]['region'] = $regions;
            }
        }

        $segments = explode(':', $path);
        $modality = Fhir::MODALITIES[(string) $exam['type']] ?? null;
        $report = Exams::report($fm);
        if ($report['modality'] === [] && $modality !== null && ($this->newReport->modalityNamespaces()[$modality] ?? null) === ($segments[1] ?? null)) {
            $fm['modality'] = [$modality];
        }

        $when = Fhir::date($form['when']) ?? Fhir::date((string) $exam['when']);
        $pathDay = preg_match('/^(\d{6})-/', (string) end($segments), $m) === 1 ? $m[1] : null;
        if ($when !== null && $pathDay === $when->format('ymd')) {
            if ($report['date'] === '') {
                $fm['study_date'] = $when->format('Y-m-d');
            }
            if ($at !== null && $when->format('H:i') !== '00:00' && $isBlank($fm['exams'][$at]['time'] ?? null)) {
                $fm['exams'][$at]['time'] = $when->format('H:i');
            }
        }

        return $fm;
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
            'name' => $name !== '' ? $name : PageTitle::of($page->frontmatter, $page->body),
            'cnp' => \is_scalar($patient['cnp'] ?? null) ? trim((string) $patient['cnp']) : '',
        ];
    }
}
