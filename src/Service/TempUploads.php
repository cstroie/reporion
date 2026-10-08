<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use InvalidArgumentException;
use Reporion\Support\Fsync;
use RuntimeException;

/**
 * Files that live only until they are used: an upload that is read once and
 * gone (a DICOM file the guided new-report form is filled from). Under
 * data/tmp/{bucket}/, named by a random token; never part of a page, never
 * indexed, never backed up as content — deleting the directory loses nothing
 * (invariant 1). Plugins get this service in place of the filesystem (D9).
 *
 * take() reads and removes in one step, so a file is used at most once; put()
 * sweeps what is older than the bucket's lifetime, so an upload nobody
 * followed up on does not stay.
 */
final class TempUploads
{
    /** How long an unused file stays */
    public const LIFETIME = 3600;

    public function __construct(private readonly string $dataRoot)
    {
    }

    /** @return string the token to take() it with */
    public function put(string $bucket, string $bytes): string
    {
        $dir = $this->dir($bucket);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('The temporary directory cannot be created');
        }
        $this->sweep($dir);

        $token = bin2hex(random_bytes(16));
        $final = $dir . '/' . $token;
        // Temp name + fsync + rename (invariant 7): never a half-written file under the token
        $part = $final . '.part';
        if (file_put_contents($part, $bytes) === false) {
            throw new RuntimeException('The upload cannot be stored');
        }
        @chmod($part, 0660);
        Fsync::file($part);
        if (!rename($part, $final)) {
            @unlink($part);
            throw new RuntimeException('The upload cannot be stored');
        }

        return $token;
    }

    /** The bytes stored under $token, which are removed — or null when there are none (never stored, used, or expired) */
    public function take(string $bucket, string $token): ?string
    {
        if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
            return null;
        }
        $file = $this->dir($bucket) . '/' . $token;
        if (!is_file($file)) {
            return null;
        }
        $fresh = (int) filemtime($file) >= time() - self::LIFETIME;
        $bytes = @file_get_contents($file);
        @unlink($file);

        return $bytes === false || !$fresh ? null : $bytes;
    }

    private function dir(string $bucket): string
    {
        if (preg_match('/^[a-z0-9-]{1,32}$/', $bucket) !== 1) {
            throw new InvalidArgumentException('Invalid bucket name');
        }

        return $this->dataRoot . '/tmp/' . $bucket;
    }

    private function sweep(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file) && (int) filemtime($file) < time() - self::LIFETIME) {
                @unlink($file);
            }
        }
    }
}
