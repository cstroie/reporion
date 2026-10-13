<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Groff\Ms;

use Reporion\Storage\PageRecord;
use Reporion\Support\MetaText;
use Reporion\Support\PageTitle;

/**
 * An ordinary page — never a report — as one groff ms document, in the house
 * style of the owner's sample: a comment header, the preamble from the plugin
 * settings, `.TL`/`.AU`/`.AI` front matter (the title, who last edited it,
 * its revision and day), the page's summary as `.AB no … .AE`, then the body.
 * The comments carry no path (invariant 8). There is no patient block and no
 * signature: the plugin does not handle patient data.
 */
final class Document
{
    /**
     * A preamble line is a comment or one of the harmless set-up requests.
     * The same pattern guards the setting in plugin.json (a test keeps them
     * equal); here it is checked again because settings.yaml may be edited
     * by hand. `\X` is refused too: a device escape could carry commands.
     */
    public const PREAMBLE_LINE = '#^(?!.*\\\\X)\\.(?:\\\\".*|(?:nr|ds|as|ll|po|ps|vs|pl|hy|nh|ad|na|fam|ft|lt|ls|blm|cflags|hw)(?:[ \\t].*)?)$#';

    /** The title the page is typeset under: its own, else the last part of its address */
    public static function title(PageRecord $record): string
    {
        $title = PageTitle::of($record->frontmatter, $record->body);
        if ($title !== '') {
            return $title;
        }
        $segments = explode(':', $record->path);

        return str_replace('-', ' ', (string) end($segments));
    }

    /**
     * The body without a first `#` heading that repeats the title — the title
     * is already set above it.
     */
    public static function withoutTitleHeading(string $body, string $title): string
    {
        $fold = static fn (string $s): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
        if (preg_match('/\A\s*#[ \t]+(.+?)[ \t#]*(?:\R|\z)/u', $body, $m) === 1 && $fold($m[1]) === $fold($title)) {
            return ltrim(substr($body, \strlen($m[0])), "\r\n");
        }

        return $body;
    }

    /**
     * @param list<string> $body the body as ms lines (HtmlToMs)
     */
    public static function build(PageRecord $record, array $body, string $preamble, string $font): string
    {
        $fm = $record->frontmatter;
        $title = self::title($record);
        $revlog = $record->revlog;
        $last = $revlog !== [] ? end($revlog) : [];
        $editor = \is_array($last) && \is_string($last['by'] ?? null) && $last['by'] !== '' ? display_name($last['by']) : '';
        $day = \is_array($last) ? MetaText::date($last['ts'] ?? null, MetaText::DATE) : '';

        $out = [
            Escape::comment($title . ' — ' . t('groff.exported', [$record->rev])),
            '.\\" -*- mode: troff; coding: utf-8 -*-',
            '.\\" vim: set ft=groff tw=72 nowrap nu :',
            ...self::preambleLines($preamble, $font),
            '.TL',
            Escape::line(Escape::text($title)),
        ];
        if ($editor !== '') {
            array_push($out, '.AU', Escape::line(Escape::text($editor)));
        }
        $info = implode(' · ', array_filter([t('groff.rev', [$record->rev]), $day], static fn (string $part): bool => $part !== ''));
        array_push($out, '.AI', Escape::line(Escape::text($info)));

        $summary = MetaText::text($fm['summary'] ?? null);
        if ($summary !== '') {
            array_push($out, '.AB no', Escape::line(Escape::text($summary)), '.AE');
        }
        array_push($out, ...$body);

        return implode("\n", $out) . "\n";
    }

    /**
     * @return list<string> the accepted lines, {font} replaced; a refused one becomes a comment
     */
    public static function preambleLines(string $preamble, string $font): array
    {
        $out = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $preamble)) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $out[] = preg_match(self::PREAMBLE_LINE, $line) === 1
                ? str_replace('{font}', $font, $line)
                : '.\\" (a preamble line was left out: only comments and set-up requests are accepted)';
        }

        return $out;
    }
}
