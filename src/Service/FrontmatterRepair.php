<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

/**
 * Finds and undoes the damage the editor island's autosave did before
 * 2026-09-26: it split the frontmatter line by line and saved each
 * "key: value" line as a flat string, so
 * - a nested block (`patient:`, an import `review:`) became '' and its
 *   fields (`name`, `born`, …) landed at the top level;
 * - every list (`modality`, `region`, `tags`, `priors`, `sequences`, …)
 *   became '';
 * - a quoted value kept its quotes as part of the text ("'RM cerebral'").
 *
 * History is append-only, so the last intact revision still has the real
 * frontmatter. repair() starts from it and carries over whatever was
 * genuinely edited since. Only revision 2 onwards can be damaged: the
 * autosave only ever saved over an existing page.
 */
final class FrontmatterRepair
{
    /**
     * Fields that hold a list or a block (conf/schema/*.json, plus the
     * importer's review block): the autosave wrote each of their header
     * lines as ''. Any other field may legitimately be '' — the importer
     * writes `summary: ''` on every page it creates.
     */
    private const BLOCKS_AND_LISTS = [
        'patient', 'review', 'modality', 'region', 'tags', 'priors',
        'sequences', 'phases', 'views', 'projections',
    ];

    /** Fields of the patient block, which the autosave moved to the top level */
    private const PATIENT_FIELDS = ['name', 'born', 'sex', 'cnp'];

    /**
     * What looks damaged in $frontmatter — empty means intact: a block or
     * list emptied to '', a patient field at the top level, or a value
     * carrying its own quotes.
     *
     * @param array<string, mixed> $frontmatter
     *
     * @return list<string>
     */
    public static function damage(array $frontmatter): array
    {
        $found = [];
        foreach ($frontmatter as $key => $value) {
            if (\in_array($key, self::PATIENT_FIELDS, true)) {
                $found[] = $key . ' moved to the top level';
                continue;
            }
            if (!\is_string($value)) {
                continue;
            }
            $unquoted = self::unquote($value);
            if ($unquoted !== $value) {
                $found[] = $key . ($unquoted === '' ? ' emptied' : ' quoted twice');
            } elseif ($value === '' && \in_array($key, self::BLOCKS_AND_LISTS, true)) {
                $found[] = $key . ' emptied';
            }
        }

        return $found;
    }

    /**
     * $good (the last intact revision's frontmatter) with the edits made
     * since carried over from $damaged. A block or list that became a string
     * keeps its intact value (the autosave could only ever write strings); a
     * field of a block that landed at the top level is dropped; a doubly
     * quoted value is unquoted; anything else that differs is an edit and
     * wins.
     *
     * @param array<string, mixed> $good
     * @param array<string, mixed> $damaged
     *
     * @return array<string, mixed>
     */
    public static function repair(array $good, array $damaged): array
    {
        $nestedKeys = [];
        foreach ($good as $value) {
            if (\is_array($value) && !array_is_list($value)) {
                $nestedKeys = [...$nestedKeys, ...array_keys($value)];
            }
        }

        $repaired = $good;
        foreach ($damaged as $key => $value) {
            if (\array_key_exists($key, $good) && \is_array($good[$key]) && !\is_array($value)) {
                continue;
            }
            if (!\array_key_exists($key, $good) && \in_array($key, $nestedKeys, true)) {
                continue;
            }
            if (\is_string($value)) {
                $value = self::unquote($value);
            }
            if ($value === '') {
                continue;
            }
            if (\array_key_exists($key, $good) && self::sameValue($good[$key], $value)) {
                continue;
            }
            $repaired[$key] = $value;
        }

        return $repaired;
    }

    /** A number, date, boolean or null that came back as its YAML text is the same value, not an edit */
    private static function sameValue(mixed $good, mixed $value): bool
    {
        if (!\is_string($value) || \is_array($good)) {
            return $good === $value;
        }

        return match (true) {
            $good === null => \in_array($value, ['null', '~'], true),
            \is_bool($good) => $value === ($good ? 'true' : 'false'),
            \is_scalar($good) => (string) $good === $value,
            default => false,
        };
    }

    /** Every layer of literal quotes — reopening a damaged page in the editor added one per round trip */
    private static function unquote(string $value): string
    {
        while (\strlen($value) >= 2 && (($value[0] === "'" && $value[-1] === "'") || ($value[0] === '"' && $value[-1] === '"'))) {
            $value = substr($value, 1, -1);
        }

        return $value;
    }
}
