<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Plugin;

/**
 * Where the real dcmtk tools are, for the tests that talk to them. A tool of
 * the same name may be someone else's — pynetdicom installs a findscu,
 * storescu, storescp and echoscu of its own, and it can come first on PATH —
 * so a directory counts only when its findscu says it is dcmtk and every tool
 * asked for is in it. DCMTK_BIN names the directory outright.
 */
final class Dcmtk
{
    /**
     * @param list<string> $tools
     *
     * @return array{0: ?string, 1: string} the directory, or null and why not
     */
    public static function bin(array $tools): array
    {
        $env = getenv('DCMTK_BIN');
        $candidates = $env !== false && $env !== ''
            ? [$env]
            : array_unique([...explode(PATH_SEPARATOR, (string) getenv('PATH')), '/usr/bin', '/usr/local/bin', '/opt/dcmtk/bin']);
        $seen = [];
        foreach ($candidates as $dir) {
            $dir = rtrim($dir, '/');
            if ($dir === '' || !is_executable($dir . '/findscu')) {
                continue;
            }
            if (!self::isDcmtk($dir . '/findscu')) {
                $seen[] = $dir . '/findscu is not dcmtk';
                continue;
            }
            $missing = array_values(array_filter($tools, static fn (string $tool): bool => !is_executable($dir . '/' . $tool)));
            if ($missing === []) {
                return [$dir, ''];
            }
            $seen[] = $dir . ' lacks ' . implode(', ', $missing);
        }

        return [null, 'no dcmtk with ' . implode(', ', $tools) . ($seen !== [] ? ' (' . implode('; ', $seen) . ')' : '') . ' — set DCMTK_BIN'];
    }

    private static function isDcmtk(string $findscu): bool
    {
        return str_contains((string) shell_exec(escapeshellarg($findscu) . ' --version 2>&1'), '$dcmtk:');
    }

    /**
     * The reporion-dicom-* query directories in the temp dir right now — a
     * test compares before and after, since a shared /tmp may hold another
     * process's.
     *
     * @return list<string>
     */
    public static function queryDirs(): array
    {
        return glob(sys_get_temp_dir() . '/reporion-dicom-*') ?: [];
    }
}
