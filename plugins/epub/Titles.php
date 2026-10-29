<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Epub;

use Reporion\Storage\PageRecord;
use Reporion\Support\PageTitle;

/** A page's heading in the book, and its body without the heading that repeats it */
final class Titles
{
    /** The page's own title, else its first heading of any level (a chapter that opens with `## I`), else the last part of its address */
    public static function of(PageRecord $record): string
    {
        $title = PageTitle::of($record->frontmatter, $record->body);
        if ($title !== '') {
            return $title;
        }
        if (preg_match('/\A\s*#{2,6}[ \t]+(.+?)[ \t#]*(?:\R|\z)/u', $record->body, $m) === 1 && trim($m[1]) !== '') {
            return trim($m[1]);
        }
        $segments = explode(':', $record->path);

        return str_replace('-', ' ', (string) end($segments));
    }

    /** The body without a first heading equal to $title: the book sets the title above it */
    public static function withoutHeading(string $body, string $title): string
    {
        $fold = static fn (string $s): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
        if (preg_match('/\A\s*#{1,6}[ \t]+(.+?)[ \t#]*(?:\R|\z)/u', $body, $m) === 1 && $fold($m[1]) === $fold($title)) {
            return ltrim(substr($body, \strlen($m[0])), "\r\n");
        }

        return $body;
    }
}
