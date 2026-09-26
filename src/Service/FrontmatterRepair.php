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
 * genuinely edited since.
 */
final class FrontmatterRepair
{
    /**
     * What looks damaged in $frontmatter — empty means intact. The
     * autosave turned every block or list header into '', which YAML itself
     * never produces for an empty field (that is null).
     *
     * @param array<string, mixed> $frontmatter
     *
     * @return list<string>
     */
    public static function damage(array $frontmatter): array
    {
        $found = [];
        foreach ($frontmatter as $key => $value) {
            if (!\is_string($value)) {
                continue;
            }
            $unquoted = self::unquote($value);
            if ($unquoted === '') {
                $found[] = $key . ' emptied';
            } elseif ($unquoted !== $value) {
                $found[] = $key . ' quoted twice';
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
