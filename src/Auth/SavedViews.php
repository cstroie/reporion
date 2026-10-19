<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Auth;

/**
 * An account's saved views (2026-10-19, Notion's): named searches, stored
 * as `views` in `data/users/{username}.json` (D36) and shown in the
 * quick-navigation menu beside the pins. A view is `/search`'s own query
 * and nothing else: only its parameters are kept, so a view cannot carry
 * anything a search would not. Saving one grants nothing: every search
 * runs the visibility predicate (invariant 6).
 */
final class SavedViews
{
    public const MAX = 20;

    private const NAME_MAX = 60;

    /** `/search`'s parameters (SearchController, Index\Sqlite::FACETS) */
    private const KEYS = ['q', 'sort', 'ns', 'modality', 'region', 'site', 'device', 'status', 'tag'];

    /** A view as stored, or null when $name or $query cannot be one */
    public static function normalize(string $name, string $query): ?array
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            return null;
        }
        parse_str(ltrim($query, '?'), $fields);
        $kept = [];
        foreach (self::KEYS as $key) {
            $value = $fields[$key] ?? null;
            if (\is_string($value) && trim($value) !== '' && mb_strlen($value) <= 200) {
                $kept[$key] = trim($value);
            }
        }
        if ($kept === []) {
            return null;
        }

        return ['name' => $name, 'query' => http_build_query($kept)];
    }

    /**
     * A stored list made safe: each entry normalized, invalid ones dropped,
     * a later one of the same name gone, at most MAX kept
     *
     * @return list<array{name: string, query: string}>
     */
    public static function clean(mixed $raw): array
    {
        $views = [];
        foreach (\is_array($raw) ? $raw : [] as $entry) {
            $view = \is_array($entry) && \is_string($entry['name'] ?? null) && \is_string($entry['query'] ?? null)
                ? self::normalize($entry['name'], $entry['query'])
                : null;
            if ($view !== null && !\in_array(mb_strtolower($view['name']), array_map(static fn (array $v): string => mb_strtolower($v['name']), $views), true)) {
                $views[] = $view;
            }
        }

        return \array_slice($views, 0, self::MAX);
    }

    /**
     * $views with $view saved: one of the same name (any case) replaced in
     * its place, else added at the end; unchanged when the list is full
     *
     * @param list<array{name: string, query: string}> $views
     * @param array{name: string, query: string}       $view
     *
     * @return list<array{name: string, query: string}>
     */
    public static function put(array $views, array $view): array
    {
        foreach ($views as $i => $existing) {
            if (mb_strtolower($existing['name']) === mb_strtolower($view['name'])) {
                $views[$i] = $view;

                return $views;
            }
        }

        return \count($views) < self::MAX ? [...$views, $view] : $views;
    }

    /**
     * @param list<array{name: string, query: string}> $views
     *
     * @return list<array{name: string, query: string}>
     */
    public static function remove(array $views, string $name): array
    {
        return array_values(array_filter($views, static fn (array $v): bool => mb_strtolower($v['name']) !== mb_strtolower(trim($name))));
    }
}
