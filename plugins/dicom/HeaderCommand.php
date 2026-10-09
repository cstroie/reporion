<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Dicom;

use InvalidArgumentException;
use Reporion\Cli\CommandHelp;
use Reporion\Cli\CommandInterface;
use Reporion\Cli\Output;
use Reporion\Support\Cnp;

/**
 * bin/reporion dicom:header <file.dcm> [--values] [--json]
 *
 * Reads one DICOM file's header (Header — never the pixel data,
 * the file is not kept) and says which of a new report's fields it could
 * start: for each, the DICOM element it comes from and whether the file has
 * it filled, empty or not at all, plus the cross-checks a CNP in PatientID
 * allows. It is for finding out what a site's scanners actually write, before
 * deciding how far "start a report from a DICOM file" is worth building.
 *
 * The header is patient data: values are printed only with --values (and the
 * file's own path never), as a run on the owner's terminal. Writes nothing.
 */
final class HeaderCommand implements CommandInterface
{
    /** Reporion field → the DICOM keyword it is read from */
    private const FIELDS = [
        'patient.name' => 'PatientName',
        'patient.cnp' => 'PatientID',
        'patient.born' => 'PatientBirthDate',
        'patient.sex' => 'PatientSex',
        'study_date' => 'StudyDate',
        'study_time' => 'StudyTime',
        'modality' => 'Modality',
        'exam_title' => 'StudyDescription',
        'region' => 'BodyPartExamined',
        'pacs_accession' => 'AccessionNumber',
        'study_uid' => 'StudyInstanceUID',
        'referrer' => 'ReferringPhysicianName',
        'indication' => 'ReasonForStudy',
        'indication (alt)' => 'ReasonForTheRequestedProcedure',
        'indication (alt 2)' => 'RequestedProcedureDescription',
        'device (maker)' => 'Manufacturer',
        'device (model)' => 'ManufacturerModelName',
        'device (station)' => 'StationName',
        'site (institution)' => 'InstitutionName',
        'field_strength' => 'MagneticFieldStrength',
        'sequences (this series)' => 'SeriesDescription',
        'protocol' => 'ProtocolName',
        'contrast' => 'ContrastBolusAgent',
    ];

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Shows which report fields one DICOM file\'s header could fill. Reads the header only, keeps nothing, opens no index.',
            usage: '<file.dcm> [--values] [--json]',
            options: [
                '--values' => 'print the values too (patient data; hidden otherwise)',
                '--json' => 'print the result as JSON',
            ],
            details: 'The file\'s own path is never printed.',
        );
    }

    public function run(array $args, Output $output): int
    {
        $file = null;
        foreach ($args as $arg) {
            if (!str_starts_with($arg, '--')) {
                $file = $arg;
            }
        }
        if ($file === null) {
            $output->error('usage: dicom:header <file.dcm> [--values] [--json]');

            return 1;
        }
        $bytes = is_file($file) && is_readable($file) ? file_get_contents($file) : false;
        if ($bytes === false) {
            $output->error('The file cannot be read');

            return 1;
        }
        try {
            $header = Header::parse($bytes);
        } catch (InvalidArgumentException $e) {
            $output->error($e->getMessage());

            return 1;
        }

        $values = \in_array('--values', $args, true);
        $elements = $header['elements'];
        $rows = [];
        foreach (self::FIELDS as $field => $keyword) {
            $state = !isset($elements[$keyword]) ? 'absent' : ($elements[$keyword] === '' ? 'empty' : 'filled');
            $rows[] = ['field' => $field, 'dicom' => $keyword, 'state' => $state] + ($values && $state === 'filled' ? ['value' => $elements[$keyword]] : []);
        }
        $checks = $this->checks($elements);
        $summary = [
            'transfer_syntax' => $header['transfer_syntax'],
            'character_set' => $elements['SpecificCharacterSet'] ?? '(none: Latin-1 assumed)',
            'other_elements_ignored' => $header['other'],
        ];

        if (\in_array('--json', $args, true)) {
            $output->line((string) json_encode(['file' => $summary, 'fields' => $rows, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        foreach ($summary as $key => $value) {
            $output->line(\sprintf('%-24s %s', str_replace('_', ' ', $key), $value));
        }
        $output->line();
        foreach ($rows as $row) {
            $output->line(\sprintf('%-24s %-32s %-7s%s', $row['field'], $row['dicom'], $row['state'], isset($row['value']) ? '  ' . $row['value'] : ''));
        }
        $output->line();
        foreach ($checks as $check) {
            $output->line($check);
        }
        if (!$values) {
            $output->line('(values hidden: add --values to print them)');
        }

        return 0;
    }

    /**
     * What PatientID and PatientBirthDate say about each other — said in words,
     * never with the CNP or the date in them.
     *
     * @param array<string, string> $e
     *
     * @return list<string>
     */
    private function checks(array $e): array
    {
        $checks = [];
        $id = preg_replace('/\s+/', '', $e['PatientID'] ?? '') ?? '';
        if ($id === '') {
            $checks[] = 'PatientID: nothing there, so no CNP from this file';
        } elseif (Cnp::isValid($id)) {
            $checks[] = 'PatientID: a valid CNP';
            $born = (int) Cnp::birthDate($id)?->format('Y');
            $dicomBorn = preg_match('/^(\d{4})\d{4}$/', $e['PatientBirthDate'] ?? '', $m) === 1 ? (int) $m[1] : null;
            $checks[] = $dicomBorn === null ? 'PatientBirthDate: not usable; the birth year would come from the CNP'
                : ($dicomBorn === $born ? 'PatientBirthDate: agrees with the CNP' : 'PatientBirthDate: DISAGREES with the CNP');
            $sex = Cnp::sex($id);
            $dicomSex = strtoupper(trim($e['PatientSex'] ?? ''));
            $checks[] = $sex === null || $dicomSex === '' ? 'PatientSex: nothing to compare with the CNP'
                : ($dicomSex === $sex ? 'PatientSex: agrees with the CNP' : 'PatientSex: DISAGREES with the CNP');
        } else {
            $checks[] = preg_match('/^\d{13}$/', $id) === 1 ? 'PatientID: 13 digits but fails the CNP checksum' : 'PatientID: not a CNP (an internal id or something else)';
        }
        $name = $e['PatientName'] ?? '';
        $checks[] = $name === '' ? 'PatientName: nothing there' : 'PatientName: ' . (str_contains($name, '^') ? 'SURNAME^GIVEN form, as expected' : 'no ^ separator, so surname and given names cannot be told apart');

        return $checks;
    }
}
