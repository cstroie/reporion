<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Import;

use Normalizer;

/**
 * One DokuWiki report template → a Reporion template page (D19: imported
 * as they are, no inheritance; the new-report form copies them).
 *
 * The archive's templates carry a catalogue heading ("====== Cap: Cerebral
 * ======") above the exam heading ("===== IRM Cerebral ====="), then the
 * findings text. A report made from a template should look like an imported
 * report — the exam title in `title`, the body straight into the text — so:
 * - one exam heading: it becomes the title, the body is what follows;
 * - several (a combined study, a whole spine, an oncology template): the
 *   catalogue label ("Combinat: CT Politraumatism") is the title and the
 *   headings stay as `##` sections;
 * - the catalogue label is kept as `template_label` — the new-report form
 *   lists templates by it, since several share one exam title;
 * - regions come from the label's category ("Cap" → neuro) through
 *   conf/import-map.json `template_category_region`; a category not listed
 *   gets none rather than a guess (the report keyword map read "Colangio" as
 *   vascular and the anterior neck as spine).
 * Nothing in the text is dropped except the headings that became the title
 * and DokuWiki macro lines (`~~…~~`), which are noted.
 */
final class TemplateConverter
{
    /**
     * @param array<string, list<string>> $categoryRegions category (lower-case, diacritics folded) → regions
     *
     * @return array{title: string, label: string, regions: list<string>, body: string, notes: list<string>}
     */
    public static function convert(string $text, array $categoryRegions): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $notes = [];
        $lines = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^\s*~~[A-Z_]+.*~~\s*$/', $line) === 1) {
                $notes[] = 'macro dropped: ' . trim($line);
                continue;
            }
            $lines[] = $line;
        }

        $label = '';
        $examHeadings = [];
        foreach ($lines as $i => $line) {
            if ($label === '' && preg_match('/^\s*={6}\s*(.+?)\s*={6}\s*$/', $line, $m) === 1) {
                $label = $m[1];
                unset($lines[$i]);
            } elseif (preg_match('/^\s*={5}\s*(.+?)\s*={5}\s*$/', $line, $m) === 1) {
                $examHeadings[$i] = $m[1];
            }
        }

        if (\count($examHeadings) === 1) {
            $title = reset($examHeadings);
            unset($lines[array_key_first($examHeadings)]);
        } else {
            $title = $label;
            if ($examHeadings !== []) {
                $notes[] = \count($examHeadings) . ' sections kept as headings';
            }
        }

        // Headings left: ===== → ##, as in any generic page (offset 0)
        $body = trim(SyntaxConverter::convert(implode("\n", $lines), 0)['markdown']) . "\n";

        return [
            'title' => $title,
            'label' => $label,
            'regions' => self::regions($label, $categoryRegions),
            'body' => $body,
            'notes' => $notes,
        ];
    }

    /**
     * @param array<string, list<string>|string> $categoryRegions
     *
     * @return list<string>
     */
    private static function regions(string $label, array $categoryRegions): array
    {
        if (!str_contains($label, ':')) {
            return [];
        }
        $category = trim((string) strstr($label, ':', true));
        $folded = mb_strtolower((string) preg_replace('/\p{Mn}+/u', '', Normalizer::normalize($category, Normalizer::FORM_D) ?: $category));
        $regions = $categoryRegions[$folded] ?? [];

        return \is_array($regions) ? array_values(array_map('strval', $regions)) : [];
    }
}
