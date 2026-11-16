<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Guide;

use Reporion\Exception\GuideException;
use Reporion\Support\GuideCodes;

/**
 * The guide's CSV (GIRI `GHID.csv`, D62) read into rows: the 13 columns by name, UTF-8, each row
 * given its situation's stable key (chapter | subchapter | situation, normalised: `NR.CRT` is
 * renumbered at the editors' request, so it is never a key). Values outside the legends are
 * kept and listed as warnings. Pure: reads a string, writes nothing.
 */
final class GuideCsv
{
    public const COLUMNS = ['NR.CRT', 'Capitol', 'Subcapitol', 'Situația Clinică', 'Tip', 'Examen', 'Indicație', 'Grad Indicație', 'Comentarii', 'Alte informații', 'Doza Min', 'Doza Max', 'Terapeutic'];

    /** The most rows a guide may have (1 341 in 2026) */
    public const MAX_ROWS = 20000;

    /**
     * @return array{rows: list<array{key: string, situation: string, ord: int, chapter: string, subchapter: string, title: string, tip: string, exam: string, indication: string, grade: string, comment: string, other: string, dose_min: string, dose_max: string, therapeutic: ?int}>, warnings: list<string>, situations: int}
     *
     * @throws GuideException INVALID (not the guide's columns, not UTF-8, no rows), PATIENT_DATA
     */
    public static function parse(string $bytes): array
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }
        if ($bytes === '' || !mb_check_encoding($bytes, 'UTF-8')) {
            throw new GuideException(GuideException::INVALID, 'not UTF-8 text');
        }
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $bytes);
        rewind($handle);
        $header = fgetcsv($handle, null, ',', '"', '');
        $header = \is_array($header) ? array_map(static fn (?string $h): string => trim((string) $h), $header) : [];
        $at = [];
        foreach (self::COLUMNS as $column) {
            $i = array_search($column, $header, true);
            if ($i === false) {
                fclose($handle);
                throw new GuideException(GuideException::INVALID, 'no column ' . $column);
            }
            $at[$column] = $i;
        }
        $rows = [];
        $warnings = [];
        $seen = [];
        $line = 1;
        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            ++$line;
            if ($cells === [null] || \count(array_filter($cells, static fn (?string $c): bool => trim((string) $c) !== '')) === 0) {
                continue;
            }
            if (\count($rows) >= self::MAX_ROWS) {
                fclose($handle);
                throw new GuideException(GuideException::INVALID, 'more than ' . self::MAX_ROWS . ' rows');
            }
            $cell = static fn (string $column): string => self::clean((string) ($cells[$at[$column]] ?? ''));
            $row = [
                'chapter' => $cell('Capitol'), 'subchapter' => $cell('Subcapitol'), 'title' => $cell('Situația Clinică'),
                'tip' => strtoupper($cell('Tip')), 'exam' => $cell('Examen'), 'indication' => $cell('Indicație'), 'grade' => $cell('Grad Indicație'),
                'comment' => $cell('Comentarii'), 'other' => $cell('Alte informații'), 'dose_min' => $cell('Doza Min'), 'dose_max' => $cell('Doza Max'),
            ];
            if ($row['chapter'] === '' || $row['title'] === '') {
                $warnings[] = 'line ' . $line . ': no chapter or situation, left out';
                continue;
            }
            if (\Reporion\Service\Library\Library::holdsPatientData(implode("\n", $row))) {
                fclose($handle);
                throw new GuideException(GuideException::PATIENT_DATA, 'line ' . $line);
            }
            $therapeutic = mb_strtolower($cell('Terapeutic'));
            $row['therapeutic'] = $therapeutic === 'da' ? 1 : ($therapeutic === 'nu' ? 0 : null);
            if (!isset(GuideCodes::TYPES[$row['tip']])) {
                $warnings[] = 'line ' . $line . ': type "' . $row['tip'] . '" is not in the legend';
            }
            if ($row['indication'] !== '' && GuideCodes::indicationCode($row['indication']) === '') {
                $warnings[] = 'line ' . $line . ': indication "' . $row['indication'] . '" is not in the legend';
            }
            if ($row['grade'] !== '' && !\in_array($row['grade'], GuideCodes::GRADES, true)) {
                $warnings[] = 'line ' . $line . ': grade "' . $row['grade'] . '" is not in the legend';
            }
            $situation = self::situationKey($row['chapter'], $row['subchapter'], $row['title']);
            $n = $seen[$situation] = ($seen[$situation] ?? 0) + 1;
            $rows[] = ['key' => $situation . '.' . $n, 'situation' => $situation, 'ord' => \count($rows) + 1] + $row;
        }
        fclose($handle);
        if ($rows === []) {
            throw new GuideException(GuideException::INVALID, 'no rows');
        }

        return ['rows' => $rows, 'warnings' => $warnings, 'situations' => \count($seen)];
    }

    /** A situation's key: the first 12 hex of sha256 of its chapter, subchapter and text, normalised */
    public static function situationKey(string $chapter, string $subchapter, string $title): string
    {
        $norm = static fn (string $s): string => mb_strtolower(self::clean($s));

        return substr(hash('sha256', $norm($chapter) . '|' . $norm($subchapter) . '|' . $norm($title)), 0, 12);
    }

    /** NFC when intl is there, cedilla ş ţ as comma ș ț, one space, trimmed */
    private static function clean(string $s): string
    {
        if (class_exists(\Normalizer::class)) {
            $s = (string) \Normalizer::normalize($s, \Normalizer::FORM_C);
        }
        $s = strtr($s, ['ş' => 'ș', 'Ş' => 'Ș', 'ţ' => 'ț', 'Ţ' => 'Ț']);

        return trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $s));
    }
}
