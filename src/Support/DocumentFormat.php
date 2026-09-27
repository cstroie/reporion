<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * The "---\nfrontmatter\n---\n\nbody" shape a page's content editors work
 * with as one raw document — mirrors `Storage\FlatFile`'s own private
 * `encodeDocument()`/`parseDocument()` exactly, so what an editor shows is
 * byte-for-byte what ends up on disk. Extracted here once `Controller\EditorController`
 * and `Controller\NewPageController` both needed the identical ~10 lines;
 * `FlatFile` itself is left untouched (its private methods are stable and
 * tested, and this class exists for editors, not for storage's own write
 * path — Storage\FlatFile still owns every actual disk write, invariant 5).
 */
final class DocumentFormat
{
    /**
     * @param array<string, mixed> $frontmatter
     */
    public static function encode(array $frontmatter, string $body): string
    {
        return "---\n" . Yaml::dump($frontmatter, 4, 2) . "---\n\n" . $body;
    }

    /**
     * A document as typed by a person: parse() when it opens with a
     * frontmatter block, else no frontmatter (null) and the whole text as
     * the body — the caller fills the frontmatter in (Service\
     * FrontmatterGuess, or the page's current one). Something that opens
     * like a block but is not one is still an error: a half-typed block
     * must not quietly become body text.
     *
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    public static function parseOrBare(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        if (!str_starts_with(ltrim($raw, "\n"), '---')) {
            return [null, ltrim($raw, "\n")];
        }

        return self::parse(ltrim($raw, "\n"));
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    public static function parse(string $raw): array
    {
        // A browser <textarea> submits CRLF line endings on form POST
        // regardless of what the user typed or the OS — this is normal
        // HTML form behavior, not malformed input, so it must be normalized
        // before the "---\n"-anchored regex below, which only matches a
        // bare LF. Mirrors Storage\FlatFile::normalizeText()'s approach.
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        if (preg_match('/^---\n(.*?\n)---\n\n?(.*)$/s', $raw, $m) !== 1) {
            throw new RuntimeException(t('editor.err_malformed'));
        }

        $frontmatter = Yaml::parse($m[1]);
        // PHP represents a YAML list and a YAML mapping with the same
        // array type — is_array() alone would accept "- a\n- b\n" (a
        // list) as valid frontmatter. array_is_list() is what actually
        // distinguishes them.
        if (!\is_array($frontmatter) || ($frontmatter !== [] && array_is_list($frontmatter))) {
            throw new RuntimeException(t('editor.err_frontmatter_not_map'));
        }

        return [$frontmatter, $m[2]];
    }
}
