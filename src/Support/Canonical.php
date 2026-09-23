<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * Canonical bytes for a signature digest (docs/FORMATS.md §8, D3): a
 * cosmetic re-save (key reordering, quote style, a stray trailing space)
 * must never change what a signature's `sha256` covers, or a legally
 * signed document could be made to look tampered with by nothing more
 * than re-serialising it. The only implementation — nothing else may
 * compute signing bytes by hand.
 *
 * Idempotent by construction: canonicalising already-canonical bytes must
 * be a no-op (docs/FORMATS.md §8's own stated test). "Unquoted where YAML
 * allows" is whatever `Symfony\Yaml`'s own dumper decides — its quoting
 * rules are a black box this class doesn't second-guess, but they are a
 * pure function of the input, which is what idempotence actually needs.
 */
final class Canonical
{
    /**
     * @param array<string, mixed> $frontmatter
     * @param array<string, array<string, mixed>> $schemaFields `Schema\Loader::fieldsFor()`'s
     *        result — frontmatter keys are reordered to match schema
     *        declaration order (recursing into nested object fields, e.g.
     *        "patient"), never sorted alphabetically or left as submitted.
     *        Keys the schema doesn't know about keep their original
     *        relative order, appended after every schema-known key. An
     *        empty array means "no reordering" — the frontmatter's own
     *        key order is used as-is.
     */
    public static function bytes(array $frontmatter, string $body, array $schemaFields = []): string
    {
        $ordered = self::stripNulls(self::reorder($frontmatter, $schemaFields));
        $yaml = $ordered === [] ? '' : Yaml::dump($ordered, 1, 2);

        return "---\n" . $yaml . "---\n\n" . self::normalizeBody($body);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, array<string, mixed>> $schemaFields
     *
     * @return array<string, mixed>
     */
    private static function reorder(array $values, array $schemaFields): array
    {
        if ($schemaFields === []) {
            return $values;
        }

        $ordered = [];
        foreach ($schemaFields as $name => $def) {
            if (!\array_key_exists($name, $values)) {
                continue;
            }
            $value = $values[$name];
            $nestedSchema = $def['fields'] ?? null;
            if (\is_array($nestedSchema) && \is_array($value)) {
                $value = self::reorder($value, $nestedSchema);
            }
            $ordered[$name] = $value;
        }

        // Anything the schema doesn't know about is real data too (D7:
        // saving is never blocked by the schema) — it keeps its original
        // relative order, appended after every schema-known key.
        foreach ($values as $key => $value) {
            if (!\array_key_exists($key, $ordered)) {
                $ordered[$key] = $value;
            }
        }

        return $ordered;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function stripNulls(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }
            $result[$key] = \is_array($value) ? self::stripNulls($value) : $value;
        }

        return $result;
    }

    private static function normalizeBody(string $body): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $body);
        $lines = array_map(
            static fn (string $line): string => rtrim($line, " \t"),
            explode("\n", $normalized)
        );

        return rtrim(implode("\n", $lines), "\n") . "\n";
    }
}
