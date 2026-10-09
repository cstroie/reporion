<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * An AI prompt profile's own page (`ai:profiles:{profile}`, phase 15) carries
 * a markdown table of its actions — `| ID | Label | Tooltip | Icon | Result | Model |` (the last optional — `lite`, `normal` or `expert`, or `{server}:{alias}`; blank is normal)
 * — for a human to read and, since 2026-09-28, for `Service\Ai\Actions` to
 * build the editor's Assistant rail from: which actions exist, their order,
 * and their rail metadata all come from the **first** table's rows. A second
 * table (the "Disabled Actions" / "not implemented" habit already in these
 * pages) is never reached — moving a row out of the first table is how an
 * action stops appearing, no `enabled: false` needed. What each row *means*
 * (validating `result`, reading the id's own prompt page) is `Actions`' job;
 * this class only reads the syntax.
 *
 * A row whose ID cell is `---` (three dashes or more, the other cells
 * blank or not there) starts a new section of the rail (2026-10-08): it
 * comes back as a row with `id` '---', which Actions turns into a line
 * before the next action.
 */
final class ProfileTable
{
    private const SEPARATOR = '/^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)+\|?\s*$/';

    /** The id a section break comes back with */
    public const BREAK = '---';

    /**
     * @return list<array{id: string, label: string, tooltip: string, icon: string, result: string, model: string}>
     */
    public static function parse(string $body): array
    {
        $lines = preg_split('/\R/', $body) ?: [];
        $start = null;
        foreach ($lines as $n => $line) {
            if (!self::isRow($line) || !isset($lines[$n + 1]) || preg_match(self::SEPARATOR, $lines[$n + 1]) !== 1) {
                continue;
            }
            $start = $n + 2;
            break;
        }
        if ($start === null) {
            return [];
        }

        $rows = [];
        for ($n = $start; isset($lines[$n]) && self::isRow($lines[$n]); ++$n) {
            $cells = array_map('trim', explode('|', trim(trim($lines[$n]), '|')));
            if (preg_match('/^-{3,}$/', $cells[0]) === 1) {
                $rows[] = ['id' => self::BREAK, 'label' => '', 'tooltip' => '', 'icon' => '', 'result' => '', 'model' => ''];
                continue;
            }
            if (\count($cells) < 5) {
                continue;
            }
            $id = self::id($cells[0]);
            if ($id === '') {
                continue;
            }
            $rows[] = ['id' => $id, 'label' => $cells[1], 'tooltip' => $cells[2], 'icon' => $cells[3], 'result' => $cells[4], 'model' => $cells[5] ?? ''];
        }

        return $rows;
    }

    private static function isRow(string $line): bool
    {
        return str_starts_with(trim($line), '|');
    }

    /**
     * A plain id, or a DokuWiki link's (`[[ns:id]]`) last colon-segment —
     * lowercased either way. A piped link label (`[[ns:id|label]]`) never
     * reaches here intact: the row is already split on `|` by the time a
     * cell gets to this method, so such a link is truncated before its
     * `]]` and simply fails to look like a link.
     */
    private static function id(string $cell): string
    {
        if (preg_match('/^\[\[([^\]]+)\]\]$/', trim($cell), $m) === 1) {
            $cell = $m[1];
        }
        $colon = strrpos($cell, ':');

        return strtolower(trim($colon === false ? $cell : substr($cell, $colon + 1)));
    }
}
