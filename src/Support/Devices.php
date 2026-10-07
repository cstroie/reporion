<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * A site's devices (`sites.{code}.devices` in data/settings.yaml, Admin →
 * Sites). An entry is a plain name — `GA-CT-01: "Siemens Somatom Emotion 16"`
 * — or, once a PACS scanner is linked to it (2026-10-07), a map with the
 * names the PACS gives that scanner:
 *
 *     GA-MR-02:
 *       name: "Virtutii GE 1.5T"
 *       pacs: ["GE MEDICAL SYSTEMS SIGNA HDxt / MRC25120"]
 *
 * A PACS name is what Plugin\Dicom\Pacs::device() makes of the study's
 * Manufacturer, ManufacturerModelName and StationName — the `pacs_device`
 * a linked report keeps. Both shapes are read here; nothing else looks
 * inside an entry.
 */
final class Devices
{
    /**
     * Code → name, whatever the entry's shape
     *
     * @param array<string, mixed> $site
     *
     * @return array<string, string>
     */
    public static function names(array $site): array
    {
        $out = [];
        foreach (self::entries($site) as $code => $entry) {
            $out[$code] = $entry['name'];
        }

        return $out;
    }

    /**
     * Code → {name, pacs}
     *
     * @param array<string, mixed> $site
     *
     * @return array<string, array{name: string, pacs: list<string>}>
     */
    public static function entries(array $site): array
    {
        $out = [];
        foreach (\is_array($site['devices'] ?? null) ? $site['devices'] : [] as $code => $entry) {
            if (\is_array($entry)) {
                $pacs = array_values(array_filter(array_map(static fn (mixed $k): string => self::key(\is_scalar($k) ? (string) $k : ''), \is_array($entry['pacs'] ?? null) ? $entry['pacs'] : []), static fn (string $k): bool => $k !== ''));
                $out[(string) $code] = ['name' => MetaText::text($entry['name'] ?? null), 'pacs' => $pacs];
            } else {
                $out[(string) $code] = ['name' => \is_scalar($entry) ? (string) $entry : '', 'pacs' => []];
            }
        }

        return $out;
    }

    /**
     * The device code a PACS scanner name is linked to at this site, or null
     *
     * @param array<string, mixed> $site
     */
    public static function forPacs(array $site, string $pacsName): ?string
    {
        $want = self::fold($pacsName);
        if ($want === '') {
            return null;
        }
        foreach (self::entries($site) as $code => $entry) {
            foreach ($entry['pacs'] as $key) {
                if (self::fold($key) === $want) {
                    return $code;
                }
            }
        }

        return null;
    }

    /**
     * An entry as it is written back: a plain name while nothing is linked
     *
     * @param array{name: string, pacs: list<string>} $entry
     *
     * @return string|array{name: string, pacs: list<string>}
     */
    public static function dump(array $entry): string|array
    {
        return $entry['pacs'] === [] ? $entry['name'] : ['name' => $entry['name'], 'pacs' => array_values($entry['pacs'])];
    }

    /**
     * The next free code for a new device at this site: the prefix its
     * devices already use (GA-CT-01 → "GA"), else $fallback, then the
     * modality and a two-digit number — GA-MR-04
     *
     * @param array<string, mixed> $site
     */
    public static function suggestCode(array $site, string $modality, string $fallback): string
    {
        $codes = array_keys(self::entries($site));
        $prefix = '';
        foreach ($codes as $code) {
            if (preg_match('/^([A-Za-z0-9]+)-[A-Za-z]+-\d+$/', $code, $m) === 1) {
                $prefix = strtoupper($m[1]);
                break;
            }
        }
        $prefix = $prefix !== '' ? $prefix : (strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $fallback) ?? '') ?: 'DEV');
        $mod = strtoupper(preg_replace('/[^A-Za-z]/', '', $modality) ?? '') ?: 'XX';
        for ($n = 1; $n < 100; ++$n) {
            $code = \sprintf('%s-%s-%02d', $prefix, $mod, $n);
            if (!\in_array(strtolower($code), array_map('strtolower', $codes), true)) {
                return $code;
            }
        }

        return $prefix . '-' . $mod . '-' . (\count($codes) + 1);
    }

    /** A PACS name as kept: one line, no list separators (`|` `;`), bounded */
    public static function key(string $pacsName): string
    {
        return mb_substr(trim((string) preg_replace('/[\s|;]+/u', ' ', $pacsName)), 0, 200);
    }

    private static function fold(string $pacsName): string
    {
        return mb_strtolower(self::key($pacsName));
    }
}
