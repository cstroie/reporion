<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Schema;

/**
 * D7: "required" blocks signing, never saving — every `required: true` and
 * `required_for: ["sign"]` field in conf/schema/*.json (docs/architecture-storage-index.md
 * §7's own base.json comment: "required blocks SIGNING, never saving") is
 * the same gate, checked at the same one moment. There is deliberately no
 * `missingForSave()` — nothing validates a draft save against the schema,
 * and building an unused validation mode would be exactly the speculative
 * surface CLAUDE.md's working agreement warns against.
 */
final class Validator
{
    /**
     * @param array<string, mixed> $fields a merged view of what would be
     *        checked — frontmatter plus `status`/`visibility`, since both
     *        are schema-declared `required` fields but live in
     *        `meta.json`, never in the frontmatter YAML block itself
     * @param array<string, array<string, mixed>> $schemaFields Loader::fieldsFor()'s result
     *
     * @return list<string> dotted field names missing or empty, required to sign
     */
    public static function missingForSign(array $fields, array $schemaFields): array
    {
        return self::check($schemaFields, $fields, '');
    }

    /**
     * @param array<string, array<string, mixed>> $schemaFields
     * @param array<string, mixed> $values
     *
     * @return list<string>
     */
    private static function check(array $schemaFields, array $values, string $prefix): array
    {
        $missing = [];

        foreach ($schemaFields as $name => $def) {
            $dotted = $prefix === '' ? $name : "{$prefix}.{$name}";
            $value = $values[$name] ?? null;

            // A nested object field (only "patient" today) is never itself
            // "required" in the shipped schemas — only its sub-fields are —
            // so descending is the whole check for one of these; there is
            // nothing left to test at this level once we recurse.
            $nestedFields = $def['fields'] ?? null;
            if (\is_array($nestedFields)) {
                $nestedValues = \is_array($value) ? $value : [];
                array_push($missing, ...self::check($nestedFields, $nestedValues, $dotted));
                continue;
            }

            if (self::isRequiredForSign($def) && !self::isPresent($value)) {
                $missing[] = $dotted;
            }
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $def
     */
    private static function isRequiredForSign(array $def): bool
    {
        if (($def['required'] ?? false) === true) {
            return true;
        }

        $requiredFor = $def['required_for'] ?? [];

        return \is_array($requiredFor) && \in_array('sign', $requiredFor, true);
    }

    /**
     * One rule, in one place, for what "present" means regardless of field
     * type: null and '' are absent for text; [] is absent for a list;
     * whitespace-only text counts as absent too — a single space in
     * `summary` must not be enough to unlock signing.
     */
    private static function isPresent(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (\is_string($value)) {
            return trim($value) !== '';
        }
        if (\is_array($value)) {
            return $value !== [];
        }

        return true;
    }
}
