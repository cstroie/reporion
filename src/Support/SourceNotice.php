<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * What the publisher or the indexers say became of a library source after it was published: retracted,
 * under an expression of concern, or corrected (docs/library.md, 2026-11-04). Read from CrossRef's
 * `updated-by` (Retraction Watch's data is in it) and PubMed's `Retracted Publication` type and
 * `CommentsCorrections` (the identifier is all that is sent, as for any lookup), kept as the source's
 * frontmatter `notice` (FORMATS §3o): `{status, items: [{type, date?, doi?}], found, from}`.
 * A source with nothing to report has no `notice`. Pure functions: the network is Service\Library\Lookup's.
 */
final class SourceNotice
{
    /** From the worst to the mildest */
    public const STATUSES = ['retracted', 'concern', 'corrected'];

    /** An item's type, as CrossRef and PubMed both say it after folding */
    public const TYPES = ['retraction', 'concern', 'correction'];

    private const MAX_ITEMS = 10;

    /** CrossRef's update types (Retraction Watch's among them) */
    private const CROSSREF = [
        'retraction' => 'retraction', 'withdrawal' => 'retraction', 'removal' => 'retraction',
        'partial_retraction' => 'concern', 'expression_of_concern' => 'concern',
        'correction' => 'correction', 'corrigendum' => 'correction', 'erratum' => 'correction', 'addendum' => 'correction', 'clarification' => 'correction',
    ];

    /** PubMed's `CommentsCorrections` RefType values */
    private const PUBMED = [
        'RetractionIn' => 'retraction', 'PartialRetractionIn' => 'concern', 'ExpressionOfConcernIn' => 'concern',
        'ErratumIn' => 'correction', 'CorrectedandRepublishedIn' => 'correction',
    ];

    /**
     * The items of CrossRef's `updated-by` list
     *
     * @return list<array{type: string, date?: string, doi?: string}>
     */
    public static function fromCrossref(mixed $updatedBy): array
    {
        $items = [];
        foreach (\is_array($updatedBy) ? $updatedBy : [] as $update) {
            $type = \is_array($update) && \is_string($update['type'] ?? null) ? self::CROSSREF[strtolower($update['type'])] ?? null : null;
            if ($type === null) {
                continue;
            }
            $item = ['type' => $type];
            $parts = $update['updated']['date-parts'][0] ?? null;
            if (\is_array($parts) && isset($parts[0]) && is_numeric($parts[0]) && (int) $parts[0] >= 1000) {
                $item['date'] = sprintf('%04d', (int) $parts[0]) . (isset($parts[1]) && is_numeric($parts[1]) ? sprintf('-%02d', (int) $parts[1]) . (isset($parts[2]) && is_numeric($parts[2]) ? sprintf('-%02d', (int) $parts[2]) : '') : '');
            }
            $doi = \is_string($update['DOI'] ?? null) ? ArticleIds::normalizeDoi($update['DOI']) : null;
            if ($doi !== null) {
                $item['doi'] = $doi;
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * The items PubMed's record carries
     *
     * @param list<string> $publicationTypes the record's PublicationType texts
     * @param list<string> $refTypes         the RefType of each CommentsCorrections
     *
     * @return list<array{type: string}>
     */
    public static function fromPubmed(array $publicationTypes, array $refTypes): array
    {
        $types = [];
        if (\in_array('Retracted Publication', $publicationTypes, true)) {
            $types['retraction'] = true;
        }
        foreach ($refTypes as $ref) {
            if (isset(self::PUBMED[$ref])) {
                $types[self::PUBMED[$ref]] = true;
            }
        }

        return array_map(static fn (string $type): array => ['type' => $type], array_keys($types));
    }

    /**
     * The notice kept for $items, or null when there is nothing to report. CrossRef's items (with
     * their date) come first; a PubMed one of a type CrossRef already gave adds nothing.
     *
     * @param list<array{type: string, date?: string, doi?: string}> $items
     * @param list<string>                                           $from  where they were read (`crossref`, `pubmed`)
     *
     * @return ?array{status: string, items: list<array{type: string, date?: string, doi?: string}>, found: string, from: string}
     */
    public static function build(array $items, array $from, string $today): ?array
    {
        $kept = [];
        $seen = [];
        foreach ($items as $item) {
            $key = $item['type'] . '|' . ($item['date'] ?? '') . '|' . ($item['doi'] ?? '');
            $typeHasDetail = isset($item['date']) || isset($item['doi']);
            // A bare PubMed item of a type that has a detailed one is the same event
            if (!$typeHasDetail && array_filter($items, static fn (array $o): bool => $o['type'] === $item['type'] && (isset($o['date']) || isset($o['doi']))) !== []) {
                continue;
            }
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $kept[] = $item;
            }
        }
        if ($kept === []) {
            return null;
        }
        usort($kept, static fn (array $a, array $b): int => [array_search($a['type'], self::TYPES, true), $a['date'] ?? ''] <=> [array_search($b['type'], self::TYPES, true), $b['date'] ?? '']);
        $status = ['retraction' => 'retracted', 'concern' => 'concern', 'correction' => 'corrected'][$kept[0]['type']];

        return ['status' => $status, 'items' => \array_slice($kept, 0, self::MAX_ITEMS), 'found' => $today, 'from' => implode('+', $from)];
    }

    /**
     * A stored `notice` made safe to read, null when it is none
     *
     * @return ?array{status: string, items: list<array{type: string, date?: string, doi?: string}>, found: string, from: string}
     */
    public static function clean(mixed $raw): ?array
    {
        if (!\is_array($raw) || !\in_array($raw['status'] ?? null, self::STATUSES, true)) {
            return null;
        }
        $items = [];
        foreach (\is_array($raw['items'] ?? null) ? $raw['items'] : [] as $item) {
            if (!\is_array($item) || !\in_array($item['type'] ?? null, self::TYPES, true)) {
                continue;
            }
            $clean = ['type' => $item['type']];
            if (\is_string($item['date'] ?? null) && preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', $item['date']) === 1) {
                $clean['date'] = $item['date'];
            }
            if (\is_string($item['doi'] ?? null) && ($doi = ArticleIds::normalizeDoi($item['doi'])) !== null) {
                $clean['doi'] = $doi;
            }
            $items[] = $clean;
        }

        return [
            'status' => $raw['status'],
            'items' => \array_slice($items, 0, self::MAX_ITEMS),
            'found' => \is_string($raw['found'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw['found']) === 1 ? $raw['found'] : '',
            'from' => \is_string($raw['from'] ?? null) ? preg_replace('/[^a-z+]/', '', $raw['from']) ?? '' : '',
        ];
    }

    /** Whether two notices say the same, whenever each was found */
    public static function same(?array $a, ?array $b): bool
    {
        $strip = static fn (?array $n): ?array => $n === null ? null : ['status' => $n['status'], 'items' => $n['items']];

        return $strip($a) === $strip($b);
    }
}
