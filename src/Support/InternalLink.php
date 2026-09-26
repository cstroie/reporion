<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * Links between pages (decided 2026-09-26). The canonical form in markdown
 * is the colon path, `[text](reports:mri:page)` — optionally `/`-prefixed,
 * optionally with a `#fragment`. The importer's older `ns/page` form is
 * recognised too, so imported and signed reports keep working links
 * without a new revision (D3). Either resolves to `{basePath}/ns:page`.
 *
 * assets/js/markdown-preview.js implements the same rules for the editor
 * preview; tests/Render/RenderConformanceTest holds the two together (D17).
 */
final class InternalLink
{
    /**
     * First segments that are never a page: URI schemes that would
     * otherwise look like a colon path (`tel:0722…`), and `media`, which
     * addresses uploaded files rather than pages.
     */
    private const NOT_PAGES = [
        'callto', 'data', 'file', 'ftp', 'ftps', 'geo', 'git', 'http', 'https', 'irc', 'javascript',
        'magnet', 'mailto', 'media', 'news', 'sms', 'ssh', 'tel', 'urn', 'vbscript', 'xmpp',
    ];

    private const COLON_FORM = '~^/?([a-z0-9][a-z0-9_-]*(?::[a-z0-9][a-z0-9_.-]*)+)$~i';
    private const SLASH_FORM = '~^([a-z0-9][a-z0-9_-]*(?:/[a-z0-9][a-z0-9_.-]*)+)$~i';

    /**
     * The page path a link destination points at, and its fragment — or
     * null for anything else (an external URL, a relative file, a bare word).
     *
     * @return ?array{path: string, fragment: string}
     */
    public static function parse(string $url): ?array
    {
        $hash = strpos($url, '#');
        $target = $hash === false ? $url : substr($url, 0, $hash);
        $fragment = $hash === false ? '' : substr($url, $hash);

        if (preg_match(self::COLON_FORM, $target, $m) === 1) {
            $path = strtolower($m[1]);
        } elseif (preg_match(self::SLASH_FORM, $target, $m) === 1) {
            $path = strtolower(str_replace('/', ':', $m[1]));
        } else {
            return null;
        }

        return \in_array(strstr($path, ':', true), self::NOT_PAGES, true) ? null : ['path' => $path, 'fragment' => $fragment];
    }

    /** The URL a link destination resolves to, or null when it is not a page link */
    public static function href(string $url, string $basePath): ?string
    {
        $link = self::parse($url);

        return $link === null ? null : $basePath . '/' . $link['path'] . $link['fragment'];
    }

    /**
     * Distinct page paths linked from a markdown body, for the index
     * (backlinks). A scan of link destinations, not a full parse: cheap
     * enough for index:rebuild, and a link written inside a code span
     * counting as a backlink is harmless.
     *
     * @return list<string>
     */
    public static function extract(string $body): array
    {
        preg_match_all('/\]\(\s*<?([^)\s>]+)/', $body, $matches);
        $paths = [];
        foreach ($matches[1] as $url) {
            $link = self::parse($url);
            if ($link !== null) {
                $paths[$link['path']] = true;
            }
        }

        return array_keys($paths);
    }
}
