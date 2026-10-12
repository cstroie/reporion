<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Groff\Ms;

use DateTimeImmutable;
use Exception;
use Reporion\Storage\PageRecord;
use Reporion\Support\Exams;
use Reporion\Support\MetaText;
use Reporion\Support\ReportName;

/**
 * A signed report as one groff ms document, in the house style of the
 * owner's sample: a comment header, the preamble from the plugin settings,
 * `.TL`/`.AU`/`.AI` front matter (the exam title, the site, the PACS
 * accession numbers and the day — never Reporion's own number, D20), the
 * indication as `.AB no … .AE`, the patient block, the body, and the
 * signature. The comments carry no path and no patient data (invariant 8).
 */
final class Document
{
    /**
     * A preamble line is a comment or one of the harmless set-up requests.
     * The same pattern guards the setting in plugin.json (a test keeps them
     * equal); here it is checked again because settings.yaml may be edited
     * by hand. `\X` is refused too: a device escape could carry commands.
     */
    public const PREAMBLE_LINE = '#^(?!.*\\\\X)\\.(?:\\\\".*|(?:nr|ds|as|ll|po|ps|vs|pl|hy|nh|ad|na|fam|ft|lt|ls|blm|cflags|hw)(?:[ \\t].*)?)$#';

    /**
     * @param array{name: string, devices: array<string, string>} $site   the report's site: its name, its devices' names
     * @param list<string>                                         $body   the body as ms lines (HtmlToMs)
     * @param ?array{name: string, parafa: string, at: string}     $signer
     */
    public static function build(PageRecord $record, array $site, array $body, ?array $signer, string $verifyUrl, string $preamble, string $font): string
    {
        $fm = Exams::flat($record->frontmatter);
        $title = ReportName::examTitle($fm) ?: t('print.untitled');
        $accessions = Exams::pacsAccessions($record->frontmatter);
        $day = MetaText::date($fm['study_date'] ?? null, MetaText::DATE);

        $out = [
            Escape::comment($title . ' — ' . t('groff.exported', [$record->rev])),
            '.\\" -*- mode: troff; coding: utf-8 -*-',
            '.\\" vim: set ft=groff tw=72 nowrap nu :',
            ...self::preambleLines($preamble, $font),
            '.TL',
            Escape::line(Escape::text($title)),
            '.AU',
            Escape::line(Escape::text($site['name'] !== '' ? $site['name'] : t('app.name'))),
            '.AI',
            Escape::line(Escape::text(implode(' · ', array_filter([t('print.kind'), implode(', ', $accessions), $day], static fn (string $part): bool => $part !== '')))),
        ];

        $indication = MetaText::text($fm['indication'] ?? null);
        if ($indication !== '') {
            array_push($out, '.AB no', Escape::line(Escape::text($indication)), '.AE');
        }

        array_push($out, ...self::patientBlock($fm, $site));
        array_push($out, ...$body);
        array_push($out, ...self::signature($record, $signer, $verifyUrl));

        return implode("\n", $out) . "\n";
    }

    /**
     * @return list<string> the accepted lines, {font} replaced; a refused one becomes a comment
     */
    public static function preambleLines(string $preamble, string $font): array
    {
        $out = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $preamble)) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $out[] = preg_match(self::PREAMBLE_LINE, $line) === 1
                ? str_replace('{font}', $font, $line)
                : '.\\" (a preamble line was left out: only comments and set-up requests are accepted)';
        }

        return $out;
    }

    /**
     * @param array<string, mixed>                                $fm
     * @param array{name: string, devices: array<string, string>} $site
     *
     * @return list<string>
     */
    private static function patientBlock(array $fm, array $site): array
    {
        $patient = \is_array($fm['patient'] ?? null) ? $fm['patient'] : [];
        $name = MetaText::text($patient['name'] ?? null);
        $age = self::age($patient['born'] ?? null, $fm['study_date'] ?? null);
        $sex = MetaText::text($patient['sex'] ?? null);
        $who = $name . ($age !== null ? ', ' . t('print.age', [$age]) : '') . ($sex !== '' ? ' (' . $sex . ')' : '');
        $device = MetaText::text($fm['device'] ?? null);

        $rows = [
            [[t('print.patient'), $who], [t('print.study_date'), MetaText::dateTime($fm['study_date'] ?? null, MetaText::DATE, ' H:i')]],
            [[t('print.referrer'), MetaText::text($fm['referrer'] ?? null)], [t('print.device'), ($site['devices'][$device] ?? '') ?: $device]],
            [[t('print.protocol'), MetaText::text($fm['protocol'] ?? null)], [t('print.region'), MetaText::text($fm['region'] ?? null)]],
        ];
        $lines = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as [$label, $value]) {
                if (trim($value) !== '') {
                    $cells[] = '\\f[B]' . Escape::text($label) . ':\\f[R] ' . Escape::text($value);
                }
            }
            if ($cells !== []) {
                array_push($lines, $lines === [] ? '.PP' : '.br', Escape::line(implode("\\h'1.5c'", $cells)));
            }
        }

        return $lines;
    }

    /**
     * @param ?array{name: string, parafa: string, at: string} $signer
     *
     * @return list<string>
     */
    private static function signature(PageRecord $record, ?array $signer, string $verifyUrl): array
    {
        if ($signer === null) {
            return ['.LP', Escape::line(Escape::text(t('print.unsigned')))];
        }
        $who = '\\f[B]' . Escape::text($signer['name']) . '\\f[R]';
        if ($signer['parafa'] !== '') {
            $who .= ' · ' . Escape::text(t('print.parafa', [$signer['parafa']]));
        }
        if ($signer['at'] !== '') {
            $who .= ' · ' . Escape::text(t('print.signed_at', [$signer['at']]));
        }

        return [
            '.LP',
            Escape::line($who),
            '.br',
            Escape::line(Escape::text(t('print.rev_signed', [$record->rev]))),
            '.br',
            // Monospace and a little smaller; \% keeps groff from breaking the address
            Escape::line('\\s-2\\f[CR]\\%' . Escape::text($verifyUrl) . '\\f[R]\\s0'),
        ];
    }

    /** Whole years at the study, or the study year less the birth year; null when unknown */
    private static function age(mixed $born, mixed $studyDate): ?int
    {
        $bornText = MetaText::text($born);
        $studyText = MetaText::text($studyDate);
        if ($bornText === '' || $studyText === '') {
            return null;
        }
        try {
            $study = \is_int($studyDate) ? new DateTimeImmutable('@' . $studyDate) : new DateTimeImmutable($studyText);
            if (preg_match('/^\d{4}$/', $bornText) === 1) {
                $years = (int) $study->format('Y') - (int) $bornText;
            } else {
                $from = new DateTimeImmutable($bornText);
                $years = $from <= $study ? $from->diff($study)->y : -1;
            }
        } catch (Exception) {
            return null;
        }

        return $years >= 0 && $years < 150 ? $years : null;
    }
}
