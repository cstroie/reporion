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

    private const FIELDS = ['name', 'cnp', 'sex', 'born', 'date', 'time', 'modality', 'site', 'device', 'regions', 'referrer', 'indication', 'template', 'title', 'priors'];

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
            if ($grant->role === GrantRole::Editor && ($grant->namespace === 'reports' || str_starts_with($grant->namespace, 'reports:'))) {
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
                $this->index->listRecent($principal, ['ns' => 'templates:' . $ns], 200)
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
            try {
                $template = str_starts_with($v['template'], 'templates:') && $this->index->findByPath($v['template'], $principal) !== null
                    ? $this->storage->read($v['template'])
                    : null;
            } catch (Throwable) {
                $template = null;
            }
            if ($template === null) {
                $errors['template'] = t('newr.err.template');
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
            // exports and public views use the exam title (Support\ReportName, D30)
            $body = '# ' . $v['name'] . "\n\n";
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
                'exam_title' => $v['title'] !== '' ? $v['title'] : (string) ($fromTemplate['title'] ?? ''),
                'visibility' => 'private',
                'modality' => [$v['modality']],
                'region' => $v['regions'] !== [] ? $v['regions'] : ($fromTemplate['region'] ?? null),
                'site' => $v['site'],
                'device' => $v['device'] !== '' ? $v['device'] : null,
                'study_date' => $studyDate,
                'patient' => $patient,
                'referrer' => $v['referrer'] !== '' ? $v['referrer'] : null,
                'indication' => $v['indication'] !== '' ? $v['indication'] : null,
                'protocol' => $fromTemplate['protocol'] ?? null,
                'template' => $template?->path,
                'priors' => $v['priors'] !== [] ? $v['priors'] : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
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
        $accession = $this->accessions->allocate($draft['siteCode'], (string) $draft['values']['modality'], substr((string) $draft['values']['date'], 2, 2));
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

    /** @return array<string, string> */
    private function namespaces(): array
    {
        return $this->modalityNamespaces !== [] ? $this->modalityNamespaces : self::DEFAULT_MODALITY_NAMESPACES;
    }
}
