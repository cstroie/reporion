<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * The Atom feeds of public pages (Controller\FeedController): which namespaces
 * have one, and the <link rel="alternate"> targets a page's <head> advertises
 * for it. Pure — the configured list comes in, nothing is read here.
 */
final class Feeds
{
    /** Never a feed, whatever conf says (docs/architecture-api.md) */
    public const REPORT_ROOT = 'reports';

    /**
     * conf['feeds']['namespaces'], cleaned: no empties, no duplicates, and
     * never `reports` or anything under it.
     *
     * @param list<mixed> $configured
     *
     * @return list<string>
     */
    public static function clean(array $configured): array
    {
        $allowed = [];
        foreach ($configured as $ns) {
            $ns = trim((string) $ns, " \t:");
            if ($ns !== '' && $ns !== self::REPORT_ROOT && !str_starts_with($ns, self::REPORT_ROOT . ':')) {
                $allowed[] = $ns;
            }
        }

        return array_values(array_unique($allowed));
    }

    /**
     * The feed that covers $ns: $ns itself or its nearest ancestor in
     * $namespaces (a feed also carries its sub-namespaces), null if none.
     *
     * @param list<string> $namespaces
     */
    public static function covering(array $namespaces, string $ns): ?string
    {
        $candidate = trim($ns, ':');
        while ($candidate !== '') {
            if (\in_array($candidate, $namespaces, true)) {
                return $candidate;
            }
            $cut = strrpos($candidate, ':');
            $candidate = $cut === false ? '' : substr($candidate, 0, $cut);
        }

        return null;
    }

    /**
     * What a page's <head> advertises: the whole-site feed, and the feed that
     * covers the page's namespace. Empty when no feeds are configured.
     *
     * @param list<string> $namespaces the cleaned list (clean())
     *
     * @return list<array{href: string, title: string}>
     */
    public static function alternates(array $namespaces, string $ns, string $basePath): array
    {
        if ($namespaces === []) {
            return [];
        }

        $links = [['href' => $basePath . '/feed.atom', 'title' => t('feed.all')]];
        $covering = self::covering($namespaces, $ns);
        if ($covering !== null) {
            $links[] = ['href' => $basePath . '/feed/' . $covering . '.atom', 'title' => t('feed.ns', [$covering])];
        }

        return $links;
    }
}
