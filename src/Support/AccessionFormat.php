<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * D20 accession numbers, `{SITE}-{MOD}-{yy}-{seq}` by default (conf
 * `accession.pattern` / `seq_pad`), with one sequence per site + modality
 * + year. Shared by the importer (Import\AccessionAllocator) and native
 * creates (Service\Accessions), so both follow one rule.
 */
final class AccessionFormat
{
    public const DEFAULT_PATTERN = '{SITE}-{MOD}-{yy}-{seq}';
    public const DEFAULT_PAD = 4;

    /** The counter key: site code (lower-case) : modality : yy */
    public static function key(string $site, string $modality, string $yy): string
    {
        return mb_strtolower($site) . ':' . $modality . ':' . $yy;
    }

    public static function format(string $pattern, int $pad, string $site, string $modality, string $yy, int $seq): string
    {
        return strtr($pattern, [
            '{SITE}' => mb_strtoupper($site),
            '{MOD}' => $modality,
            '{yy}' => $yy,
            '{seq}' => str_pad((string) $seq, $pad, '0', STR_PAD_LEFT),
        ]);
    }

    /** A regex with named groups site, mod, yy, seq for accessions of $pattern */
    public static function regex(string $pattern): string
    {
        return '/^' . strtr(preg_quote($pattern, '/'), [
            '\{SITE\}' => '(?<site>.+?)',
            '\{MOD\}' => '(?<mod>.+?)',
            '\{yy\}' => '(?<yy>\d{2})',
            '\{seq\}' => '(?<seq>\d+)',
        ]) . '$/u';
    }

    /**
     * Highest seq per key among every page's `accession:` frontmatter line
     * on disk (invariant 1 — not the index).
     *
     * @return array<string, int>
     */
    public static function issuedOnDisk(string $pagesRoot, string $pattern): array
    {
        if (!is_dir($pagesRoot)) {
            return [];
        }
        $regex = self::regex($pattern);
        $issued = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pagesRoot, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getFilename() !== 'current.md') {
                continue;
            }
            $head = (string) file_get_contents($file->getPathname(), false, null, 0, 4096);
            // Frontmatter only — never a line in the report body
            if (preg_match('/\A---\n(.*?\n)---\n/s', $head, $frontmatter) !== 1
                || preg_match('/^accession:[ \t]*["\']?([^"\'\n]+?)["\']?[ \t]*$/m', $frontmatter[1], $line) !== 1
                || preg_match($regex, $line[1], $m) !== 1) {
                continue;
            }
            $key = self::key($m['site'], $m['mod'], $m['yy']);
            $issued[$key] = max($issued[$key] ?? 0, (int) $m['seq']);
        }

        return $issued;
    }
}
