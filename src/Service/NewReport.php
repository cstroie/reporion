<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Reporion\Auth\GrantRole;
use Reporion\Auth\User;
use Reporion\Index\IndexInterface;
use Reporion\Schema\Loader;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\Cnp;
use Reporion\Support\PatientKey;
use Reporion\Support\ReportPath;
use Reporion\Support\Slug;
use Reporion\Support\Templates;
use Throwable;

/**
 * The guided new-report form (roadmap phase 7): patient, exam and template
 * in, a D1 path and a frontmatter out. Since 2026-09-26 the report is titled
 * by the patient's name (`title` and a first `#` heading) with the exam title
 * in `exam_title`, and a template contributes metadata only, not its text —
 * `reports:{modality-ns}:{site}:{yymmdd}-{slug(name)}`, with the patient
 * block, study date, modality and regions (D29), site and device, the
 * accession allocated at create (D20) and the template's body (D19,
 * Service\Duplicates — never its patient fields).
 *
 * The CNP is optional; when given it is checksum-validated and fills sex
 * and birth year, and becomes the strong patient key (D11).
 */
final class NewReport
{
    public const DEFAULT_MODALITY_NAMESPACES = ['MR' => 'mri', 'CT' => 'ct', 'US' => 'us', 'XR' => 'xr', 'MG' => 'mg'];

    private const FIELDS = ['name', 'cnp', 'sex', 'born', 'date', 'time', 'modality', 'site', 'device', 'regions', 'referrer', 'indication', 'template', 'title', 'priors', 'order_ref', 'study_uid', 'pacs_accession'];

    /** `order_ref`: the order this report answers in another system, `{system}:{Type}/{id}` */
    public const ORDER_REF = '/^[a-z0-9][a-z0-9-]{0,31}:[A-Za-z]{1,32}\/[A-Za-z0-9._-]{1,64}$/';

    /** `study_uid`: the DICOM Study Instance UID of the exam (PS3.5 §9.1: digits and dots, ≤ 64) */
    public const STUDY_UID = '/^[0-9]+(\.[0-9]+)*$/';

    /** `pacs_accession`: the accession number the PACS gave the study (DICOM SH, ≤ 16 characters) */
    public const PACS_ACCESSION = '/^[\x21-\x5B\x5D-\x7E][\x20-\x5B\x5D-\x7E]{0,15}$/';

    /**
     * @param list<string>                         $modalities         conf/schema modality codes (MR, CT, …)
     * @param array<string, array<string, mixed>>  $sites              config `sites` (Admin → Settings)
     * @param array<string, string>                $modalityNamespaces modality code → namespace segment
     */
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
        private readonly Accessions $accessions,
        private readonly Loader $schemas,
        private readonly array $modalities,
        private readonly array $sites,
        private readonly array $modalityNamespaces,
    ) {
    }

    /**
     * Who gets the guided form, and the "new exam for this patient" actions:
     * the owner, or anyone with an editor grant somewhere under reports:.
     * The path a create finally lands on is still checked with canWrite().
     */
    public static function canCreateReports(User $principal): bool
    {
        if ($principal->isOwner) {
            return true;
        }
        foreach ($principal->grants as $grant) {
            if ($grant->role === GrantRole::Editor && ReportPath::isReportNamespace($grant->namespace)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The form's fields for a new exam of the same patient (roadmap phase 9):
     * the patient, the same modality (the first, if several), site, device,
     * regions and referrer, the previous summary as the indication, today's
     * date, and the report itself as the prior. All editable on the form.
     *
     * @return array<string, mixed>
     */
    public function prefill(PageRecord $previous): array
    {
        $fm = $previous->frontmatter;
        $patient = \is_array($fm['patient'] ?? null) ? $fm['patient'] : [];
        $text = static fn (mixed $value): string => \is_scalar($value) ? trim((string) $value) : '';
        $list = static fn (mixed $value): array => array_values(array_filter(array_map($text, \is_array($value) ? $value : [$value]), static fn (string $v): bool => $v !== ''));

        return [
            'name' => $text($patient['name'] ?? null),
            'cnp' => $text($patient['cnp'] ?? null),
            'sex' => $text($patient['sex'] ?? null),
            'born' => $text($patient['born'] ?? null),
            'date' => date('Y-m-d'),
            'modality' => $list($fm['modality'] ?? null)[0] ?? '',
            'site' => $text($fm['site'] ?? null),
            'device' => $text($fm['device'] ?? null),
            'regions' => $list($fm['region'] ?? null),
            'referrer' => $text($fm['referrer'] ?? null),
            'indication' => $text($fm['summary'] ?? null),
            'priors' => [$previous->path],
        ];
    }

    /**
     * What the form offers: modalities that have a namespace, sites with
     * their devices, the region vocabulary, and the templates the caller
     * can read, per modality.
     *
     * @return array{modalities: array<string, string>, sites: array<string, array{name: string, devices: array<string, string>}>, regions: list<string>, templates: array<string, list<array{path: string, title: string}>>}
     */
    public function options(User $principal): array
    {
        $modalities = [];
        $templates = [];
        foreach ($this->modalities as $code) {
            $ns = $this->namespaces()[$code] ?? null;
            if ($ns === null) {
                continue;
            }
            $modalities[$code] = $ns;
            $templates[$code] = array_map(
                // Listed by the catalogue label when there is one — several templates share an exam title
                static fn (array $row): array => ['path' => (string) $row['path'], 'title' => (string) ($row['template_label'] ?? null ?: $row['title'] ?: $row['path'])],
                $this->index->listRecent($principal, ['ns' => Templates::NS . ':' . $ns], 200)
            );
            usort($templates[$code], static fn (array $a, array $b): int => strcmp($a['title'], $b['title']));
        }
        $sites = [];
        foreach ($this->sites as $code => $site) {
            $sites[(string) $code] = [
                'name' => (string) ($site['name'] ?? '') !== '' ? (string) $site['name'] : (string) $code,
                'devices' => array_map('strval', \is_array($site['devices'] ?? null) ? $site['devices'] : []),
            ];
        }

        return [
            'modalities' => $modalities,
            'sites' => $sites,
            'regions' => array_values(array_map('strval', (array) ($this->schemas->fieldsFor([])['region']['values'] ?? []))),
            'templates' => $templates,
        ];
    }

    /**
     * Validates the form and works out everything a create needs.
     *
     * @param array<string, mixed> $raw
     *
     * @return array{values: array<string, mixed>, errors: array<string, string>, path: ?string, frontmatter: ?array<string, mixed>, body: string, derived: array{sex: ?string, born: ?int, age: ?int}, sameDay: list<array<string, mixed>>, priorRows: list<array<string, mixed>>, accession: ?string, siteCode: ?string}
     */
    public function draft(array $raw, User $principal): array
    {
        $v = [];
        foreach (self::FIELDS as $field) {
            $v[$field] = \in_array($field, ['regions', 'priors'], true)
                ? array_values(array_filter((array) ($raw[$field] ?? []), 'is_string'))
                : trim(\is_string($raw[$field] ?? null) ? $raw[$field] : '');
        }
        // More exams in the same report (phase 12): the first is the fields
        // above, each further one a row of its own. "+ exam" and "remove"
        // are submit buttons, so the form works without JavaScript
        $v['more'] = [];
        foreach (\is_array($raw['more'] ?? null) ? $raw['more'] : [] as $row) {
            if (\is_array($row)) {
                $v['more'][] = [
                    'title' => trim(\is_string($row['title'] ?? null) ? $row['title'] : ''),
                    'regions' => array_values(array_filter((array) ($row['regions'] ?? []), 'is_string')),
                    'template' => trim(\is_string($row['template'] ?? null) ? $row['template'] : ''),
                ];
            }
        }
        $action = \is_string($raw['action'] ?? null) ? $raw['action'] : '';
        if ($action === 'add_exam') {
            $v['more'][] = ['title' => '', 'regions' => [], 'template' => ''];
        } elseif (preg_match('/^remove_exam:(\d+)$/', $action, $m) === 1) {
            unset($v['more'][(int) $m[1]]);
            $v['more'] = array_values($v['more']);
        }
        $v['name'] = (string) preg_replace('/\s+/u', ' ', $v['name']);
        $v['cnp'] = (string) preg_replace('/\s+/', '', $v['cnp']);
        $errors = [];
        $options = $this->options($principal);

        // Patient
        $slug = null;
        try {
            $slug = mb_strlen($v['name']) >= 2 ? Slug::normalize($v['name']) : null;
        } catch (InvalidArgumentException) {
        }
        if ($slug === null) {
            $errors['name'] = t('newr.err.name');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $v['date']) ?: null;
        if ($date === null || $date->format('Y-m-d') !== $v['date']) {
            $errors['date'] = t('newr.err.date');
            $date = null;
        }
        $sex = \in_array($v['sex'], ['M', 'F'], true) ? $v['sex'] : null;
        $born = ctype_digit($v['born']) ? (int) $v['born'] : null;
        if ($v['born'] !== '' && ($born === null || $born < 1880 || $born > (int) date('Y'))) {
            $errors['born'] = t('newr.err.born');
            $born = null;
        }
        $birthDate = null;
        if ($v['cnp'] !== '') {
            if (!Cnp::isValid($v['cnp'])) {
                $errors['cnp'] = t('newr.err.cnp');
            } else {
                $birthDate = Cnp::birthDate($v['cnp']);
                $cnpSex = Cnp::sex($v['cnp']);
                if ($sex !== null && $cnpSex !== null && $sex !== $cnpSex) {
                    $errors['sex'] = t('newr.err.sex_cnp');
                }
                $sex = $cnpSex ?? $sex;
                $born = $birthDate !== null ? (int) $birthDate->format('Y') : $born;
            }
        }
        $age = $birthDate !== null && $date !== null ? Cnp::age($birthDate, $date) : ($born !== null && $date !== null ? (int) $date->format('Y') - $born : null);

        // Set by a plugin's prefill (hook report.prefill), carried through the form
        if ($v['order_ref'] !== '' && preg_match(self::ORDER_REF, $v['order_ref']) !== 1) {
            $v['order_ref'] = '';
        }
        if ($v['study_uid'] !== '' && (\strlen($v['study_uid']) > 64 || preg_match(self::STUDY_UID, $v['study_uid']) !== 1)) {
            $v['study_uid'] = '';
        }
        if ($v['pacs_accession'] !== '' && preg_match(self::PACS_ACCESSION, $v['pacs_accession']) !== 1) {
            $v['pacs_accession'] = '';
        }

        // Exam
        if ($v['time'] !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v['time']) !== 1) {
            $errors['time'] = t('newr.err.time');
        }
        $ns = $options['modalities'][$v['modality']] ?? null;
        if ($ns === null) {
            $errors['modality'] = t('newr.err.modality');
        }
        if ($options['sites'] !== [] ? !isset($options['sites'][$v['site']]) : preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $v['site']) !== 1) {
            $errors['site'] = t('newr.err.site');
        }
        $devices = $options['sites'][$v['site']]['devices'] ?? [];
        if ($v['device'] !== '' && $devices !== [] && !isset($devices[$v['device']])) {
            $errors['device'] = t('newr.err.device');
        }
        if (array_diff($v['regions'], $options['regions']) !== []) {
            $errors['regions'] = t('newr.err.regions');
        }

        // Template: the caller must be able to read it
        $template = null;
        if ($v['template'] !== '') {
            $template = $this->readableTemplate($v['template'], $principal);
            if ($template === null) {
                $errors['template'] = t('newr.err.template');
            }
        }

        // Each further exam: its regions, its template (readable), a title
        $moreTemplates = [];
        foreach ($v['more'] as $i => $row) {
            if (array_diff($row['regions'], $options['regions']) !== []) {
                $errors['more.' . $i] = t('newr.err.regions');
            }
            $moreTemplates[$i] = null;
            if ($row['template'] !== '') {
                $moreTemplates[$i] = $this->readableTemplate($row['template'], $principal);
                if ($moreTemplates[$i] === null) {
                    $errors['more.' . $i] = t('newr.err.template');
                }
            }
        }

        // Priors: readable reports only; anything else is dropped, not an error
        $priorRows = [];
        foreach (array_unique($v['priors']) as $prior) {
            $row = ReportPath::isReport($prior) ? $this->index->findByPath($prior, $principal) : null;
            if ($row !== null) {
                $priorRows[] = $row;
            }
        }
        $v['priors'] = array_column($priorRows, 'path');

        $path = $ns !== null && $slug !== null && $date !== null && !isset($errors['site'])
            ? 'reports:' . $ns . ':' . $v['site'] . ':' . $date->format('ymd') . '-' . $slug
            : null;
        $siteCode = !isset($errors['site']) ? ((string) ($this->sites[$v['site']]['accession_code'] ?? '') ?: $v['site']) : null;
        $accession = $siteCode !== null && $ns !== null && $date !== null ? $this->accessions->peek($siteCode, $v['modality'], $date->format('y')) : null;

        $sameDay = [];
        if ($slug !== null && $date !== null) {
            $sameDay = $this->index->findSameDay(
                $v['cnp'] !== '' && !isset($errors['cnp']) ? PatientKey::strong($v['cnp']) : null,
                PatientKey::weak($v['name'], $born, $sex),
                $date->format('Y-m-d'),
                $principal
            );
        }

        $frontmatter = null;
        $body = '';
        if ($errors === [] && $path !== null && $date !== null) {
            // The template gives metadata only — never its text (decided 2026-09-26)
            [$fromTemplate] = $template !== null ? Duplicates::document($template) : [[], ''];
            // The patient's name titles the report on screen and heads its text;
            // exports and public views use the exam title (Support\ReportName, D30).
            // The exam heads its own part at `##` — the one shape every report
            // has (docs/FORMATS.md §11), and a report's first exam in phase 12
            $examTitle = $v['title'] !== '' ? $v['title'] : (string) ($fromTemplate['title'] ?? '');
            $body = '# ' . $v['name'] . "\n\n" . ($examTitle !== '' ? '## ' . $examTitle . "\n\n" : '');
            $regions = $v['regions'] !== [] ? $v['regions'] : ($fromTemplate['region'] ?? null);
            $exams = null;
            if ($v['more'] !== []) {
                // Several exams: one ## each, with its sections to fill; the
                // title and regions of the whole are the exams' (phase 12)
                $exams = [['title' => $examTitle, 'region' => array_values((array) ($regions ?? []))]];
                foreach ($v['more'] as $i => $row) {
                    [$rowTemplate] = $moreTemplates[$i] !== null ? Duplicates::document($moreTemplates[$i]) : [[], ''];
                    $exams[] = [
                        'title' => $row['title'] !== '' ? $row['title'] : (string) ($rowTemplate['title'] ?? ''),
                        'region' => $row['regions'] !== [] ? $row['regions'] : array_values((array) ($rowTemplate['region'] ?? [])),
                    ];
                }
                foreach ($exams as $n => $exam) {
                    if ($exam['title'] === '') {
                        $errors[$n === 0 ? 'title' : 'more.' . ($n - 1)] = t('newr.err.exam_title');
                    }
                }
                $examTitle = implode(' + ', array_column($exams, 'title'));
                $regions = array_values(array_unique(array_merge(...array_column($exams, 'region')))) ?: null;
                $body = '# ' . $v['name'] . "\n\n";
                foreach ($exams as $exam) {
                    $body .= '## ' . $exam['title'] . "\n\n### Descriere\n\n### Concluzii\n\n";
                }
                $exams = array_map(static fn (array $exam): array => array_filter($exam, static fn (mixed $x): bool => $x !== []), $exams);
            }
            $patient = array_filter([
                'name' => $v['name'],
                'sex' => $sex,
                'born' => $born,
                'cnp' => $v['cnp'] !== '' ? $v['cnp'] : null,
            ], static fn (mixed $value): bool => $value !== null);
            $studyDate = $v['time'] !== ''
                ? (new DateTimeImmutable($v['date'] . ' ' . $v['time']))->format('Y-m-d\TH:i:sP')
                : $v['date'];
            $frontmatter = array_filter([
                'title' => $v['name'],
                'exam_title' => $examTitle,
                'visibility' => 'private',
                'modality' => [$v['modality']],
                'region' => $regions,
                'site' => $v['site'],
                'device' => $v['device'] !== '' ? $v['device'] : null,
                'study_date' => $studyDate,
                'exams' => $exams,
                'patient' => $patient,
                'referrer' => $v['referrer'] !== '' ? $v['referrer'] : null,
                'indication' => $v['indication'] !== '' ? $v['indication'] : null,
                'protocol' => $fromTemplate['protocol'] ?? null,
                'template' => $template?->path,
                'priors' => $v['priors'] !== [] ? $v['priors'] : null,
                'order_ref' => $v['order_ref'] !== '' ? $v['order_ref'] : null,
                'study_uid' => $v['study_uid'] !== '' ? $v['study_uid'] : null,
                'pacs_accession' => $v['pacs_accession'] !== '' ? $v['pacs_accession'] : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
            if ($errors !== []) {
                $frontmatter = null;
            }
        }

        return [
            'values' => $v,
            'errors' => $errors,
            'path' => $path,
            'frontmatter' => $frontmatter,
            'body' => $body,
            'derived' => ['sex' => $sex, 'born' => $born, 'age' => $age],
            'sameDay' => $sameDay,
            'priorRows' => $priorRows,
            'accession' => $accession,
            'siteCode' => $siteCode,
        ];
    }

    /**
     * Allocates the accession and creates the page — private, draft. The
     * caller has checked write access to the path and audits the create.
     *
     * @param array{values: array<string, mixed>, path: ?string, frontmatter: ?array<string, mixed>, body: string, siteCode: ?string} $draft
     */
    public function create(array $draft, string $actor): PageRecord
    {
        if ($draft['path'] === null || $draft['frontmatter'] === null || $draft['siteCode'] === null) {
            throw new InvalidArgumentException('The draft has errors');
        }
        $frontmatter = $draft['frontmatter'];
        $yy = substr((string) $draft['values']['date'], 2, 2);
        if (\is_array($frontmatter['exams'] ?? null)) {
            // One number per exam, in order, and none for the page (D20, phase 12)
            foreach ($frontmatter['exams'] as $i => $exam) {
                $frontmatter['exams'][$i]['accession'] = $this->accessions->allocate($draft['siteCode'], (string) $draft['values']['modality'], $yy);
            }

            return $this->storage->create($draft['path'], $frontmatter, $draft['body'], $actor);
        }
        $accession = $this->accessions->allocate($draft['siteCode'], (string) $draft['values']['modality'], $yy);
        // Right after study_date, where the imported reports carry it
        $ordered = [];
        foreach ($frontmatter as $key => $value) {
            $ordered[$key] = $value;
            if ($key === 'study_date') {
                $ordered['accession'] = $accession;
            }
        }

        return $this->storage->create($draft['path'], $ordered, $draft['body'], $actor);
    }

    /** A template page the caller can read, or null */
    private function readableTemplate(string $path, User $principal): ?PageRecord
    {
        try {
            return str_starts_with($path, Templates::NS . ':') && $this->index->findByPath($path, $principal) !== null
                ? $this->storage->read($path)
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, string> modality code → namespace segment, as configured */
    public function modalityNamespaces(): array
    {
        return $this->namespaces();
    }

    /** @return array<string, string> */
    private function namespaces(): array
    {
        return $this->modalityNamespaces !== [] ? $this->modalityNamespaces : self::DEFAULT_MODALITY_NAMESPACES;
    }
}
