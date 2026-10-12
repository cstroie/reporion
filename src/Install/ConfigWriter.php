<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Install;

use RuntimeException;

/**
 * conf/local.php for the web installer (D43): conf/local.php.example with
 * the installer's values put in, so the comments that explain each key
 * stay with the file an admin opens later. Each value replaces one known
 * `'key' => …,` line; a line the example no longer has is an error rather
 * than a file silently missing the session secret.
 */
final class ConfigWriter
{
    /** The example's keys the installer sets, each found once in its section */
    private const KEYS = [
        'auth.session_secret' => 'session_secret',
    ];

    /**
     * @param array<string, string> $values dotted key (self::KEYS) → value
     *
     * @throws RuntimeException when the example does not have a key's line
     */
    public static function render(string $example, array $values): string
    {
        $text = $example;
        foreach ($values as $dotted => $value) {
            $key = self::KEYS[$dotted] ?? throw new RuntimeException('Unknown key ' . $dotted);
            $pattern = '/^(\s*\'' . preg_quote($key, '/') . '\'\s*=>\s*)\'[^\'\n]*\'(\s*,)/m';
            $count = 0;
            $text = (string) preg_replace_callback(
                $pattern,
                static fn (array $m): string => $m[1] . var_export($value, true) . $m[2],
                $text,
                1,
                $count,
            );
            if ($count !== 1) {
                throw new RuntimeException('conf/local.php.example has no line for ' . $dotted);
            }
        }

        return str_replace(
            '// conf/local.php — copy from this file and edit. NEVER committed (see .gitignore).',
            '// conf/local.php — written by the web installer (install.php) on ' . date('Y-m-d H:i') . '. NEVER committed (see .gitignore).',
            $text,
        );
    }

    /**
     * Writes $php to $file: temp file + rename, mode 0640, so the file is
     * never seen half written and never world-readable (it holds the
     * session secret).
     */
    public static function write(string $file, string $php): void
    {
        $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
        $old = umask(0027);
        try {
            if (@file_put_contents($tmp, $php, LOCK_EX) !== \strlen($php)) {
                @unlink($tmp);
                throw new RuntimeException('Could not write ' . $tmp);
            }
            @chmod($tmp, 0640);
            if (!@rename($tmp, $file)) {
                @unlink($tmp);
                throw new RuntimeException('Could not rename ' . $tmp);
            }
        } finally {
            umask($old);
        }
    }
}
