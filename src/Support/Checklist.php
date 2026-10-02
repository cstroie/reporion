<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * A template's `checklist:` (roadmap phase 26, docs/FORMATS.md §3g) — what
 * an exam of its kind must address. A YAML list of lines:
 *
 *   checklist:
 *     - "# Menisci"                              a section heading
 *     - "Menisc medial | menisc medial"          an item, then its keywords
 *     - "Ligamente încrucișate | LIA, LIP, încrucișat"
 *     - Revărsat articular                       an item with no keywords
 *
 * `|` splits the label from its comma-separated keywords (the label may
 * itself hold commas — the same reason XRayVision's templates use `|`). An
 * item with keywords none of which appears in the exam's text is "not
 * mentioned" in the editor; one without keywords is only ever ticked by
 * hand. Pure functions, no I/O.
 */
final class Checklist
{
    /** A sane bound for a template's list */
    public const MAX = 80;

    /**
     * @return list<array{section: bool, label: string, keywords: list<string>}>
     */
    public static function parse(mixed $raw): array
    {
        $lines = \is_array($raw) ? array_values($raw) : (\is_string($raw) ? preg_split('/\R/', $raw) ?: [] : []);
        $out = [];
        foreach ($lines as $line) {
            if (\count($out) >= self::MAX) {
                break;
            }
            if (\is_array($line) && \count($line) === 1 && \is_string(array_key_first($line))) {
                // `- Menisc medial: [menisc]` — YAML's own map form, the keywords as a list
                $label = (string) array_key_first($line);
                $keywords = \is_array($line[$label]) ? $line[$label] : [$line[$label]];
                $line = $label . ' | ' . implode(', ', array_map(static fn (mixed $k): string => \is_scalar($k) ? (string) $k : '', $keywords));
            }
            if (!\is_scalar($line)) {
                continue;
            }
            $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $line));
            if ($text === '') {
                continue;
            }
            if (str_starts_with($text, '#')) {
                $label = trim(ltrim($text, '#'));
                if ($label !== '') {
                    $out[] = ['section' => true, 'label' => mb_substr($label, 0, 120), 'keywords' => []];
                }

                continue;
            }
            [$label, $words] = array_pad(explode('|', $text, 2), 2, '');
            $label = trim($label);
            if ($label === '') {
                continue;
            }
            $keywords = array_values(array_unique(array_filter(array_map(
                static fn (string $k): string => mb_substr(trim($k), 0, 60),
                explode(',', $words),
            ), static fn (string $k): bool => $k !== '')));
            $out[] = ['section' => false, 'label' => mb_substr($label, 0, 160), 'keywords' => \array_slice($keywords, 0, 12)];
        }

        return $out;
    }

    /**
     * Folded for matching: lower case, no diacritics (ș/ş, ț/ţ written either
     * way — D5's reason), single spaces.
     */
    public static function fold(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, ['ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't', 'ă' => 'a', 'â' => 'a', 'î' => 'i']);
        if (class_exists(\Normalizer::class)) {
            $text = (string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($text, \Normalizer::FORM_D));
        }

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Whether $text mentions an item: any of its keywords appears, folded.
     * null for an item without keywords — nothing to tell.
     *
     * @param list<string> $keywords
     */
    public static function mentioned(array $keywords, string $text): ?bool
    {
        if ($keywords === []) {
            return null;
        }
        $haystack = self::fold($text);
        foreach ($keywords as $keyword) {
            $needle = self::fold($keyword);
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * As plain text for a prompt ({checklist}): sections as headings, items
     * as "- label" lines; keywords left out (they are for matching).
     *
     * @param list<array{section: bool, label: string, keywords: list<string>}> $items
     */
    public static function asText(array $items): string
    {
        $lines = [];
        foreach ($items as $item) {
            $lines[] = $item['section'] ? "\n" . $item['label'] . ':' : '- ' . $item['label'];
        }

        return trim(implode("\n", $lines));
    }
}
