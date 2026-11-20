<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * The frontmatter keys a signed report may change and stay signed (D66):
 * links and index aids that never print, so the report a reader holds
 * says exactly what the signed one said. Anything else, the body included,
 * is a correction, signed again by a radiologist (D3).
 */
final class SignedMeta
{
    /** Top-level keys a carried signature allows */
    public const TOP = ['summary', 'tags', 'priors'];

    /** Keys of one exam (`exams[*]`) a carried signature allows */
    public const EXAM = ['study_uid', 'order_ref'];

    /**
     * The allowlisted keys that differ between $old and $new (`summary`,
     * `exams.study_uid`, ...), [] when nothing changed, or null when
     * anything outside the allowlist differs (a body is not looked at here).
     *
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     *
     * @return ?list<string>
     */
    public static function changedKeys(array $old, array $new): ?array
    {
        $changed = [];
        foreach (self::TOP as $key) {
            if (!self::same($old[$key] ?? null, $new[$key] ?? null)) {
                $changed[] = $key;
            }
        }
        $oldExams = \is_array($old['exams'] ?? null) ? array_values($old['exams']) : null;
        $newExams = \is_array($new['exams'] ?? null) ? array_values($new['exams']) : null;
        if ($oldExams !== null && $newExams !== null && \count($oldExams) === \count($newExams)) {
            foreach ($newExams as $i => $exam) {
                $was = $oldExams[$i];
                if (!\is_array($exam) || !\is_array($was)) {
                    if (!self::same($was, $exam)) {
                        return null;
                    }
                    continue;
                }
                foreach (self::EXAM as $key) {
                    if (!self::same($was[$key] ?? null, $exam[$key] ?? null) && !\in_array('exams.' . $key, $changed, true)) {
                        $changed[] = 'exams.' . $key;
                    }
                }
                if (!self::same(self::without($was, self::EXAM), self::without($exam, self::EXAM))) {
                    return null;
                }
            }
            $old['exams'] = $new['exams'] = null;
        }

        return self::same(self::without($old, self::TOP), self::without($new, self::TOP)) ? $changed : null;
    }

    /**
     * @param array<array-key, mixed> $values
     * @param list<string> $keys
     *
     * @return array<array-key, mixed>
     */
    private static function without(array $values, array $keys): array
    {
        foreach ($keys as $key) {
            unset($values[$key]);
        }

        return $values;
    }

    /** Equal as YAML would store them: blanks are absent, map key order does not count */
    private static function same(mixed $a, mixed $b): bool
    {
        return self::norm($a) === self::norm($b);
    }

    private static function norm(mixed $value): mixed
    {
        if ($value === '' || $value === []) {
            return null;
        }
        if (!\is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $item = self::norm($item);
            if ($item !== null) {
                $out[$key] = $item;
            }
        }
        if ($out === []) {
            return null;
        }
        if (!array_is_list($value)) {
            ksort($out);
        }

        return $out;
    }
}
