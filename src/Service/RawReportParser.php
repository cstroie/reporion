<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Support\DocumentFormat;

/**
 * Parses pre-2026 radiology report pages (old format: # Name / **indication**
 * / *date* / body) and restructures them into the post-2026 shape
 * (## exam_title, ### Descriere, ### Concluzii).
 *
 * Pure service — static methods only, like FrontmatterGuess. No constructor
 * injection needed. No framework, no static state except pure helpers.
 *
 * @internal
 */
final class RawReportParser
{
    /** Exam-title keyword table: exam title → list of Romanian keywords */
    private const EXAM_KEYWORDS = [
        'CT Cerebral' => ['TCC', 'traumatism cranian', 'encefal', 'cranial', 'cerebral'],
        'CT Genunchi' => ['genunchi', 'tibială', 'ligament cruciat', 'menisc', 'eminență tibială'],
        'CT Coloană lombară' => ['coloană lombară', 'lombară', 'L1-L5', 'disc lombar'],
        'CT Coloană cervicală' => ['colană cervicală', 'cervicală', 'C1-C6', 'vertebrală cervicală'],
        'CT Abdomen' => ['hepatic', 'ficat', 'abdominal', 'subdiafragmatic', 'splina', 'rinichi', 'renal', 'suprarenalian'],
        'CT Torace' => ['torace', 'pleural', 'pulmonar', 'bronhic'],
        'IRM Coloană lombară' => ['coloană lombară', 'disc L5-S1', 'disc lombar'],
        'IRM Coloană cervicală' => ['coloană cervicală', 'cervicală'],
        'IRM Genunchi' => ['genunchi', 'menisc', 'ligament încrucișat'],
        'IRM Abdomen' => ['hepatic', 'ficat', 'abdominal', 'rinichi'],
    ];

    /** Modality code → exam-title prefix */
    private const MODALITY_PREFIX = [
        'ct' => 'CT',
        'mri' => 'IRM',
        'us' => 'US',
        'xr' => 'XR',
        'mg' => 'MG',
    ];

    /** Exam-title keyword fragment → region */
    private const EXAM_TO_REGION = [
        'Cerebral' => 'neuro',
        'Genunchi' => 'msk',
        'Coloană' => 'spine',
        'Abdomen' => 'abdomen',
        'Torace' => 'chest',
    ];

    /**
     * Detect old format: after the `# Name` heading, the next non-empty line
     * starts with `**` (bold indication).
     */
    public static function isOldFormat(string $raw): bool
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $lines = explode("\n", $raw);

        $foundH1 = false;
        foreach ($lines as $line) {
            if (! $foundH1) {
                if (preg_match('/^ {0,3}#{1,6}[ \t]+.+$/', $line) === 1) {
                    $foundH1 = true;
                }

                continue;
            }

            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            return str_starts_with($trimmed, '**');
        }

        return false;
    }

    /**
     * Parse an old-format page into structured data.
     *
     * @param array<string, mixed> $frontmatter Existing frontmatter (from parseOrBare; may be null)
     *
     * @return array{
     *   indication: string,
     *   date: ?string,
     *   description: string,
     *   conclusion: string,
     *   examTitle: string,
     *   fmUpdates: array<string, mixed>,
     *   confidence: list<string>
     * }|null
     */
    public static function parse(string $raw, string $importedFrom, array $frontmatter): ?array
    {
        if (! self::isOldFormat($raw)) {
            return null;
        }

        [, $body] = DocumentFormat::parseOrBare($raw);

        $lines = explode("\n", $body);

        // Skip past the # Name heading
        $i = 0;
        for (; $i < count($lines); $i++) {
            if (preg_match('/^ {0,3}#{1,6}[ \t]+.+$/', $lines[$i]) === 1) {
                $i++;
                break;
            }
        }

        // Collect non-empty lines after the heading
        $contentLines = [];
        for (; $i < count($lines); $i++) {
            if (trim($lines[$i]) !== '') {
                $contentLines[] = $lines[$i];
            }
        }

        // Extract indication (**...**) and date (*...*)
        $indication = '';
        $date = null;
        $bodyLines = [];
        $stage = 'indication';

        foreach ($contentLines as $line) {
            $trimmed = trim($line);

            if ($stage === 'indication' && str_starts_with($trimmed, '**') && str_ends_with($trimmed, '**')) {
                $indication = trim(substr($trimmed, 2, -2));
                continue;
            }

            if ($stage === 'indication' && preg_match('/^\*(.+)\*$/', $trimmed, $m)) {
                $date = trim($m[1]);
                $stage = 'body';
                continue;
            }

            if ($stage === 'indication') {
                $stage = 'date';
            }

            if (($stage === 'date' || $stage === 'indication') && preg_match('/^\*(.+)\*$/', $trimmed, $m)) {
                $date = trim($m[1]);
                $stage = 'body';
                continue;
            }

            $stage = 'body';
            $bodyLines[] = $line;
        }

        // Each remaining line is a paragraph (empty separator lines were already stripped)
        $paragraphs = $bodyLines;
        $bodyText = implode("\n", $bodyLines);

        // Last paragraph → conclusion, rest → description
        $conclusion = '';
        $description = '';
        if (count($paragraphs) === 1) {
            $conclusion = $paragraphs[0];
        } elseif (count($paragraphs) > 1) {
            $conclusion = array_pop($paragraphs);
            $description = implode("\n\n", $paragraphs);
        }

        // Deduce exam title
        $fromKeyword = false;
        $examTitle = self::deduceExamTitle($importedFrom, $frontmatter, $bodyText, $fromKeyword);

        // Confidence flags
        $confidence = [];
        if ($indication !== '') {
            $confidence[] = 'high';
        }
        if ($date !== null) {
            $confidence[] = 'high';
        }
        if ($fromKeyword) {
            $confidence[] = 'medium';
        } else {
            $confidence[] = 'low';
        }
        if (count($paragraphs) === 0) {
            $confidence[] = 'low';
        }

        // Modality / region fixes
        $fmModality = $frontmatter['modality'] ?? null;
        $fmRegion = $frontmatter['region'] ?? null;
        $pathModality = self::modalityFromPath($importedFrom);

        $needsModalityFix = self::isFallbackModality($fmModality);
        $needsRegionFix = self::isFallbackRegion($fmRegion);

        // Build fmUpdates
        $fmUpdates = [];
        if ($indication !== '') {
            $fmUpdates['indication'] = $indication;
        }
        $fmUpdates['summary'] = $conclusion;
        $fmUpdates['exam_title'] = $examTitle;
        if ($needsModalityFix && $pathModality !== null) {
            $fmUpdates['modality'] = [$pathModality];
        }
        if ($needsRegionFix) {
            $fmUpdates['region'] = [self::regionFromExamTitle($examTitle)];
        }
        if ($date !== null && ($frontmatter['study_date'] ?? null) === null) {
            $fmUpdates['study_date'] = $date;
        }

        return [
            'indication' => $indication,
            'date' => $date,
            'description' => $description,
            'conclusion' => $conclusion,
            'examTitle' => $examTitle,
            'fmUpdates' => $fmUpdates,
            'confidence' => $confidence,
        ];
    }

    /** Modality code → exam-title prefix, or null if unmapped */
    private static function modalityFromPath(string $importedFrom): ?string
    {
        $segments = explode('/', $importedFrom);
        foreach ($segments as $segment) {
            if (array_key_exists($segment, self::MODALITY_PREFIX)) {
                return self::MODALITY_PREFIX[$segment];
            }
        }

        return null;
    }

    /** Modality keywords to check before specific exam keywords */
    private const MODALITY_KEYWORDS = ['RM', 'MRI', 'Ultrasonograf', 'Ecografie', 'XR', 'X-ray', 'MG', 'Mammografie', 'PET', 'SPECT'];

    /**
     * Deduce exam title from: (a) body modality keyword,
     * (b) body keyword scan, (c) path modality segment,
     * (d) existing frontmatter modality.
     */
    private static function deduceExamTitle(string $importedFrom, array $frontmatter, string $body, bool &$fromKeyword): string
    {
        // (a) Body modality keyword — whole-word match only
        foreach (self::MODALITY_KEYWORDS as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $body)) {
                $fromKeyword = true;

                return $keyword;
            }
        }

        // (b) Body keyword scan — highest fidelity for specific exams
        foreach (self::EXAM_KEYWORDS as $exam => $keywords) {
            foreach ($keywords as $keyword) {
                if (mb_stripos($body, $keyword) !== false) {
                    $fromKeyword = true;

                    return $exam;
                }
            }
        }

        $fromKeyword = false;

        // (c) Path modality segment
        $pathPrefix = self::modalityFromPath($importedFrom);
        if ($pathPrefix !== null) {
            return $pathPrefix . ' Exam';
        }

        // (d) Frontmatter modality
        $fmModality = $frontmatter['modality'] ?? null;
        if (is_array($fmModality) && count($fmModality) > 0) {
            return $fmModality[0] . ' Exam';
        }
        if (is_string($fmModality) && $fmModality !== '') {
            return $fmModality . ' Exam';
        }

        return 'Exam';
    }

    /** Whether the modality value is a fallback placeholder */
    private static function isFallbackModality(mixed $modality): bool
    {
        if ($modality === 'other' || $modality === 'whole-body') {
            return true;
        }
        if (is_array($modality) && count($modality) === 1 && in_array($modality[0], ['other', 'whole-body'], true)) {
            return true;
        }

        return false;
    }

    /** Whether the region value is a fallback placeholder */
    private static function isFallbackRegion(mixed $region): bool
    {
        if ($region === 'whole-body') {
            return true;
        }
        if (is_array($region) && count($region) === 1 && $region[0] === 'whole-body') {
            return true;
        }

        return false;
    }

    /** Region from exam title */
    private static function regionFromExamTitle(string $examTitle): string
    {
        foreach (self::EXAM_TO_REGION as $keyword => $region) {
            if (str_contains($examTitle, $keyword)) {
                return $region;
            }
        }

        return 'whole-body';
    }
}
