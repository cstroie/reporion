<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Hipobridge;

use DateTimeImmutable;
use Reporion\Support\Cnp;
use Throwable;

/**
 * HippoBridge's FHIR resources read into plain arrays — pure functions, no
 * I/O. The shapes are HippoBridge's (hippoclient.py `fhir_response`s):
 *
 *   /fhir/Schedule            Bundle of ServiceRequest: id, identifier[request-code],
 *                             subject.display (name), category[0].coding[0].code
 *                             (modality slug), authoredOn, occurrenceDateTime (Data Efectuarii, absent until
 *                             performed), status, requester, note (ward)
 *   /fhir/ServiceRequest/{id} one order form: subject.identifier (CNP),
 *                             extension patientName/…, code.text (procedure),
 *                             bodySite[0].text (region), note (indication), requester
 *   /fhir/Patient?q=          a Patient, a Bundle of Patients, or an OperationOutcome
 *   /fhir/ServiceRequest?patient=  Bundle: code.coding[0].code (type), authoredOn,
 *                             bodySite[].text, requester
 *   /fhir/DiagnosticReport/{id}  presentedForm[]: {title, data, type, region,
 *                             validator, validation_date}, effectiveDateTime,
 *                             extension …-requester / …-justification
 */
final class Fhir
{
    /** HIS modality slug → Reporion modality code (conf/schema) */
    public const MODALITIES = ['ct' => 'CT', 'irm' => 'MR', 'eco' => 'US', 'radio' => 'XR'];

    /** HIS modality slug → Hipocrate lab id, the /fhir/Schedule filter (hippoclient.py _LAB_ID_TO_MODALITY) */
    public const LAB_IDS = ['ct' => '26', 'irm' => '32', 'eco' => '28', 'radio' => '49'];

    /** Schedule statuses that mean the exam was done or is being done */
    public const PERFORMED = ['active', 'completed', 'ended'];

    /** Statuses the worklist lists: the performed ones plus drafts, which are not performed yet (no date) */
    public const WORKLIST = ['active', 'completed', 'ended', 'draft'];

    /**
     * HippoBridge region labels (regions.cfg names, title-cased) → the
     * Reporion region vocabulary (conf/schema/base.json). Unlisted ones map
     * to nothing: the region is then chosen on the form.
     */
    public const REGIONS = [
        'brain' => 'neuro', 'head' => 'neuro', 'skull' => 'neuro',
        'neck' => 'head-neck', 'sinuses' => 'head-neck', 'ear' => 'head-neck', 'nose' => 'head-neck', 'mandible' => 'head-neck',
        'spine' => 'spine',
        'chest' => 'chest', 'ribs' => 'chest', 'sternum' => 'chest', 'clavicle' => 'chest',
        'abdomen' => 'abdomen', 'bowel' => 'abdomen', 'colon' => 'abdomen', 'rectum' => 'abdomen', 'urinary tract' => 'abdomen',
        'pelvis' => 'pelvis', 'scrotum' => 'pelvis', 'inguinal' => 'pelvis',
        'hip' => 'msk', 'knee' => 'msk', 'ankle' => 'msk', 'foot' => 'msk', 'hand' => 'msk', 'elbow' => 'msk', 'shoulder' => 'msk',
        'limbs' => 'msk', 'lower limb' => 'msk', 'upper limb' => 'msk',
    ];

    /** @param array<string, mixed> $data */
    public static function isOutcome(array $data): bool
    {
        return ($data['resourceType'] ?? null) === 'OperationOutcome';
    }

    /**
     * @param array<string, mixed> $bundle
     *
     * @return list<array<string, mixed>> the resources of $type in a Bundle
     */
    public static function entries(array $bundle, string $type): array
    {
        if (($bundle['resourceType'] ?? null) !== 'Bundle') {
            return [];
        }
        $out = [];
        foreach (\is_array($bundle['entry'] ?? null) ? $bundle['entry'] : [] as $entry) {
            $resource = \is_array($entry) && \is_array($entry['resource'] ?? null) ? $entry['resource'] : null;
            if ($resource !== null && ($resource['resourceType'] ?? null) === $type && self::str($resource['id'] ?? null) !== '') {
                $out[] = $resource;
            }
        }

        return $out;
    }

    /**
     * /fhir/Schedule rows.
     *
     * @param array<string, mixed> $bundle
     *
     * @return list<array{id: string, code: string, patient: string, when: string, performed: string, modality: string, status: string, ward: string, requester: string, indication: string}>
     */
    public static function scheduleRows(array $bundle): array
    {
        $rows = [];
        foreach (self::entries($bundle, 'ServiceRequest') as $sr) {
            $rows[] = [
                'id' => self::str($sr['id']),
                'code' => self::str($sr['identifier'][0]['value'] ?? null),
                'patient' => self::displayName(self::str($sr['subject']['display'] ?? null)),
                'when' => self::str($sr['authoredOn'] ?? null),
                'performed' => self::str($sr['occurrenceDateTime'] ?? null),
                'modality' => self::str($sr['category'][0]['coding'][0]['code'] ?? null),
                'status' => self::str($sr['status'] ?? null),
                'ward' => self::ward($sr),
                'requester' => self::str($sr['requester']['display'] ?? null),
                'indication' => self::indication($sr),
            ];
        }

        return $rows;
    }

    /**
     * /fhir/ServiceRequest/{id} — the order form.
     *
     * @param array<string, mixed> $sr
     *
     * @return array{id: string, name: string, cnp: string, when: string, procedure: string, region: string, indication: string, requester: string}
     */
    public static function orderForm(array $sr): array
    {
        $ext = [];
        foreach (\is_array($sr['extension'] ?? null) ? $sr['extension'] : [] as $e) {
            if (\is_array($e) && \is_string($e['url'] ?? null)) {
                $ext[$e['url']] = self::str($e['valueString'] ?? null);
            }
        }
        return [
            'id' => self::str($sr['id'] ?? null),
            'name' => self::displayName($ext['patientName'] ?? ''),
            'cnp' => self::str($sr['subject']['identifier']['value'] ?? null),
            'when' => self::str($sr['authoredOn'] ?? null),
            'procedure' => self::str($sr['code']['text'] ?? null),
            'region' => self::str($sr['bodySite'][0]['text'] ?? null),
            'indication' => self::indication($sr),
            'requester' => self::str($sr['requester']['display'] ?? null),
        ];
    }

    /**
     * A ServiceRequest's clinical indication (its note of category
     * clinical-indication), or '' — list bundles may not carry it.
     *
     * @param array<string, mixed> $sr
     */
    private static function indication(array $sr): string
    {
        $indication = '';
        foreach (\is_array($sr['note'] ?? null) ? $sr['note'] : [] as $note) {
            if (\is_array($note) && self::str($note['category'][0]['text'] ?? null) === 'clinical-indication') {
                $indication = self::str($note['text'] ?? null);
            }
        }

        return $indication;
    }

    /**
     * The ward on a schedule row: its first uncategorised note.
     *
     * @param array<string, mixed> $sr
     */
    private static function ward(array $sr): string
    {
        foreach (\is_array($sr['note'] ?? null) ? $sr['note'] : [] as $note) {
            if (\is_array($note) && !isset($note['category'])) {
                return self::str($note['text'] ?? null);
            }
        }

        return '';
    }

    /**
     * A Patient resource.
     *
     * @param array<string, mixed> $p
     *
     * @return array{id: string, name: string, sex: ?string, born: ?int, cnp: string}
     */
    public static function patient(array $p): array
    {
        $name = \is_array($p['name'][0] ?? null) ? $p['name'][0] : [];
        $full = self::str($name['text'] ?? null);
        if ($full === '') {
            $given = array_map(static fn (mixed $g): string => self::str($g), \is_array($name['given'] ?? null) ? $name['given'] : []);
            $full = trim(self::str($name['family'] ?? null) . ' ' . implode(' ', $given));
        }
        $cnp = '';
        foreach (\is_array($p['identifier'] ?? null) ? $p['identifier'] : [] as $id) {
            if (\is_array($id) && str_ends_with(self::str($id['system'] ?? null), '/patient-cnp')) {
                $cnp = self::str($id['value'] ?? null);
            }
        }
        $sex = match (strtolower(self::str($p['gender'] ?? null))) {
            'male', 'm' => 'M',
            'female', 'f' => 'F',
            default => null,
        };
        $born = preg_match('/^(\d{4})-\d{2}-\d{2}/', self::str($p['birthDate'] ?? null), $m) === 1 ? (int) $m[1] : null;
        if ($cnp !== '' && Cnp::isValid($cnp)) {
            $sex = Cnp::sex($cnp) ?? $sex;
            $birth = Cnp::birthDate($cnp);
            $born = $birth !== null ? (int) $birth->format('Y') : $born;
        }

        return ['id' => self::str($p['id'] ?? null), 'name' => self::displayName($full), 'sex' => $sex, 'born' => $born, 'cnp' => $cnp];
    }

    /**
     * /fhir/ServiceRequest?patient= — the patient's exams, newest first.
     *
     * @param array<string, mixed> $bundle
     *
     * @return list<array{id: string, type: string, display: string, when: string, regions: list<string>, requester: string, indication: string}>
     */
    public static function requests(array $bundle): array
    {
        $rows = [];
        foreach (self::entries($bundle, 'ServiceRequest') as $sr) {
            $regions = [];
            foreach (\is_array($sr['bodySite'] ?? null) ? $sr['bodySite'] : [] as $site) {
                $text = \is_array($site) ? self::str($site['text'] ?? null) : '';
                if ($text !== '') {
                    $regions[] = $text;
                }
            }
            $rows[] = [
                'id' => self::str($sr['id']),
                'type' => self::str($sr['code']['coding'][0]['code'] ?? null),
                'display' => self::str($sr['code']['coding'][0]['display'] ?? null),
                'when' => self::str($sr['authoredOn'] ?? null),
                'regions' => $regions,
                'requester' => self::str($sr['requester']['display'] ?? null),
                'indication' => self::indication($sr),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($b['when'], $a['when']));

        return $rows;
    }

    /**
     * /fhir/DiagnosticReport/{id}: the report text of each study of $type
     * (all of them when none carries a type), with who validated it.
     *
     * @param array<string, mixed> $report
     *
     * @return array{when: string, requester: string, justification: string, forms: list<array{title: string, text: string, region: string, validator: string}>}
     */
    public static function report(array $report, string $type): array
    {
        $ext = [];
        foreach (\is_array($report['extension'] ?? null) ? $report['extension'] : [] as $e) {
            if (\is_array($e) && \is_string($e['url'] ?? null)) {
                $ext[substr($e['url'], (int) strrpos($e['url'], '/') + 1)] = self::str($e['valueString'] ?? null);
            }
        }
        $all = [];
        $typed = [];
        foreach (\is_array($report['presentedForm'] ?? null) ? $report['presentedForm'] : [] as $form) {
            $text = \is_array($form) ? trim(self::str($form['data'] ?? null)) : '';
            if ($text === '') {
                continue;
            }
            $row = [
                'title' => self::str($form['title'] ?? null),
                'text' => $text,
                'region' => self::str($form['region'] ?? null),
                'validator' => self::str($form['validator'] ?? null),
            ];
            $all[] = $row;
            if (strtolower(self::str($form['type'] ?? null)) === strtolower($type)) {
                $typed[] = $row;
            }
        }

        return [
            'when' => self::str($report['effectiveDateTime'] ?? null),
            'requester' => $ext['diagnostic-report-requester'] ?? '',
            'justification' => $ext['diagnostic-report-justification'] ?? '',
            'forms' => $typed !== [] ? $typed : $all,
        ];
    }

    /** A HIS region label → a Reporion region, or null */
    public static function region(string $label): ?string
    {
        return self::REGIONS[strtolower(trim(str_replace('_', ' ', $label)))] ?? null;
    }

    /** "POPESCU ANA-MARIA" → "POPESCU Ana-Maria": family name as is, given names title-cased */
    public static function displayName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
        if ($parts === []) {
            return '';
        }
        $family = mb_strtoupper(array_shift($parts));
        $given = array_map(static fn (string $p): string => mb_convert_case(mb_strtolower($p), MB_CASE_TITLE), $parts);

        return trim($family . ' ' . implode(' ', $given));
    }

    /** "2026-09-20 10:15" / ISO → DateTimeImmutable, or null */
    public static function date(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    private static function str(mixed $value): string
    {
        return \is_scalar($value) ? trim((string) $value) : '';
    }
}
