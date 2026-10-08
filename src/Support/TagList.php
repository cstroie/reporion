<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * The assistant's `tags` answer as the list that goes into the `tags`
 * field (2026-10-08). The prompt asks for "exam type, region, findings",
 * comma separated, or NONE; a small model slips, so this guards: NONE, or
 * one item that is not an exam type (prose, a refusal), is no tags at all.
 * Otherwise each tag is trimmed of quotes, markers and final punctuation,
 * lowercased, mapped onto the tag dictionary's own spelling when it is an
 * entry or one of its synonyms (case, diacritics aside — "fractură" is
 * `fractura`, "adenopatii" is `adenopatie`), de-duplicated, at most MAX.
 * An item longer than MAX_WORDS is a sentence, not a tag, and is dropped.
 * NORMAL with a finding beside it (more than exam type, region and itself)
 * contradicts itself: the finding stays, NORMAL goes — Similar reports
 * leaves a normal report out, so a doubtful one must not be.
 */
final class TagList
{
    public const MAX = 5;

    public const MAX_WORDS = 4;

    /** The tag of a report with no abnormality (the `tags` prompt's rule) */
    public const NORMAL = 'normal';

    /** First words that name an examination: what the first tag must be when there is only one */
    public const EXAM_TYPES = ['radiografie', 'rx', 'ct', 'tc', 'angio-ct', 'angiocomputertomografie', 'irm', 'rm', 'rmn', 'angio-irm', 'angio-rm', 'ecografie', 'eco', 'mamografie', 'tomosinteza', 'scintigrafie', 'pet-ct', 'fluoroscopie', 'osteodensitometrie'];

    /**
     * @param array<string, array{synonyms: list<string>}> $dictionary TagDictionary::all()
     *
     * @return list<string>
     */
    public static function parse(string $answer, array $dictionary = []): array
    {
        $text = trim(str_replace('**', '', $answer));
        $first = trim((string) preg_replace('/^(tags|etichete)\s*:\s*/iu', '', $text));
        if ($first === '' || preg_match('/^NONE\b/i', $first) === 1) {
            return [];
        }
        // Commas, or one tag per line when a model lists them
        $items = preg_split('/\s*[,\n;]\s*/u', $first) ?: [];
        $tags = [];
        foreach ($items as $item) {
            $item = (string) preg_replace('/^(#{1,6}|[-*•]|\d+[.)])\s+/u', '', trim($item));
            $item = trim($item, " \t\"'„“”«»`.!:");
            $item = mb_strtolower((string) preg_replace('/\s+/u', ' ', $item));
            if ($item !== '' && $item !== 'none' && \count(explode(' ', $item)) <= self::MAX_WORDS) {
                $tags[] = self::canonical($item, $dictionary);
            }
        }
        $tags = array_values(array_unique($tags));
        if (\in_array(self::NORMAL, $tags, true) && \count($tags) > 3) {
            $tags = array_values(array_diff($tags, [self::NORMAL]));
        }
        if (\count($tags) === 1 && \count($items) === 1 && !self::isExamType($tags[0])) {
            return [];
        }

        return \array_slice($tags, 0, self::MAX);
    }

    private static function isExamType(string $tag): bool
    {
        $word = Slug::fold(explode(' ', $tag)[0]);
        foreach (self::EXAM_TYPES as $type) {
            if ($word === Slug::fold($type)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, array{synonyms: list<string>}> $dictionary */
    private static function canonical(string $tag, array $dictionary): string
    {
        $key = Slug::fold($tag);
        if ($key === '') {
            return $tag;
        }
        foreach ($dictionary as $entry => $details) {
            foreach ([(string) $entry, ...($details['synonyms'] ?? [])] as $term) {
                if (Slug::fold($term) === $key) {
                    return (string) $entry;
                }
            }
        }

        return $tag;
    }
}
