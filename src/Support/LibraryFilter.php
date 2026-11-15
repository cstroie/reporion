<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * The filters of the library's view beyond the tag and the text (docs/library.md, D60): the year
 * (`2020`, `2018-2022`, `2020-`, `-2015`), the journal (a word of its name), whether the source has
 * its file, a notice (`any`, `retracted`, `concern`, `corrected`), whether nothing cites it, and the
 * caller's own mark (`toread`, `reading`, `read`, `star`, `none`: Auth\LibraryMarks, 2026-11-15). They
 * are `/library`'s query parameters, so a filter is a link, and saved as a collection (Auth\SavedViews,
 * kind `library`) it is that query: it grants nothing, the listing still runs the visibility predicate
 * (invariant 6). Pure functions over the rows Service\Library\Library::entries() lists.
 */
final class LibraryFilter
{
    /** The filter parameters, in the order a saved collection prints them */
    public const KEYS = ['year', 'journal', 'file', 'notice', 'uncited', 'mark'];

    /** The values of `mark`: the caller's own reading status, a star, or no mark at all (Auth\LibraryMarks) */
    public const MARKS = ['toread', 'reading', 'read', 'star', 'none'];

    /**
     * The filters in a query, cleaned: a parameter that is none of them, or has a value it cannot read, is dropped
     *
     * @param array<string, mixed> $query
     *
     * @return array<string, string>
     */
    public static function of(array $query): array
    {
        $filter = [];
        $year = \is_string($query['year'] ?? null) ? trim($query['year']) : '';
        if ($year !== '' && $year !== '-' && preg_match('/^\d{4}(?:-(?:\d{4})?)?$|^-\d{4}$/', $year) === 1) {
            $filter['year'] = $year;
        }
        $journal = \is_string($query['journal'] ?? null) ? trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $query['journal'])) : '';
        if ($journal !== '' && mb_strlen($journal) <= 100) {
            $filter['journal'] = $journal;
        }
        if (\in_array($query['file'] ?? null, ['yes', 'no'], true)) {
            $filter['file'] = (string) $query['file'];
        }
        if (\in_array($query['notice'] ?? null, ['any', ...SourceNotice::STATUSES], true)) {
            $filter['notice'] = (string) $query['notice'];
        }
        if (($query['uncited'] ?? null) === '1') {
            $filter['uncited'] = '1';
        }
        if (\in_array($query['mark'] ?? null, self::MARKS, true)) {
            $filter['mark'] = (string) $query['mark'];
        }

        return $filter;
    }

    /**
     * The entries that pass every filter given
     *
     * @param list<array<string, mixed>> $entries Library::entries(): year, journal, ext, cited, notice; mark and star as the controller adds them
     * @param array<string, string>      $filter  of()
     *
     * @return list<array<string, mixed>>
     */
    public static function apply(array $entries, array $filter): array
    {
        if ($filter === []) {
            return $entries;
        }
        $from = $to = null;
        if (isset($filter['year'])) {
            [$a, $b] = array_pad(explode('-', $filter['year'], 2), 2, null);
            $from = $a !== '' ? (int) $a : null;
            $to = $b === null ? $from : ($b !== '' ? (int) $b : null);
        }
        $journal = isset($filter['journal']) ? Slug::fold($filter['journal']) : null;

        return array_values(array_filter($entries, static function (array $e) use ($filter, $from, $to, $journal): bool {
            $year = $e['year'] ?? null;
            if (isset($filter['year']) && ($year === null || ($from !== null && $year < $from) || ($to !== null && $year > $to))) {
                return false;
            }
            if ($journal !== null && !str_contains(Slug::fold((string) ($e['journal'] ?? '')), $journal)) {
                return false;
            }
            if (isset($filter['file']) && (($e['ext'] ?? '') !== '') !== ($filter['file'] === 'yes')) {
                return false;
            }
            if (isset($filter['notice']) && ($filter['notice'] === 'any' ? ($e['notice'] ?? null) === null : ($e['notice'] ?? null) !== $filter['notice'])) {
                return false;
            }
            if (isset($filter['uncited']) && (int) ($e['cited'] ?? 0) > 0) {
                return false;
            }
            if (isset($filter['mark'])) {
                $status = (string) ($e['mark'] ?? '');
                $star = ($e['star'] ?? false) === true;
                if (match ($filter['mark']) {
                    'star' => !$star,
                    'none' => $status !== '' || $star,
                    default => $status !== $filter['mark'],
                }) {
                    return false;
                }
            }

            return true;
        }));
    }
}
