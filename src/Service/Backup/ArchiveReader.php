<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Backup;

use Reporion\Exception\BackupException;

/**
 * Reads what ArchiveWriter wrote (D44) — and treats it as untrusted: every
 * entry name is checked before anything is created, links and device nodes
 * are refused, and every file must match the sha256 the manifest (the last
 * entry) states. Extracts to a directory the caller gives, or — with no
 * directory — only verifies.
 */
final class ArchiveReader
{
    /** What an archive may hold: the data tree, and these two conf files */
    private const ALLOWED = '#^(?:manifest\.json|data(?:/.+)?|conf/local\.php|conf/patient_merges\.json)$#';

    /** @var resource */
    private $gz;

    /**
     * @param ?callable(string): void $progress called with the top-level part of each file's name
     */
    public function __construct(private readonly string $path, private readonly mixed $progress = null)
    {
    }

    /**
     * @return array{manifest: array<string, mixed>, files: int, bytes: int}
     *
     * @throws BackupException on anything that makes the archive untrustworthy
     */
    public function extract(?string $dest): array
    {
        $gz = @gzopen($this->path, 'rb');
        if ($gz === false) {
            throw new BackupException('Cannot read the backup file');
        }
        $this->gz = $gz;
        try {
            return $this->walk($dest);
        } finally {
            gzclose($gz);
        }
    }

    /**
     * @return array{manifest: array<string, mixed>, files: int, bytes: int}
     */
    private function walk(?string $dest): array
    {
        $seen = [];
        $bytes = 0;
        $manifest = null;
        $paxName = null;
        while (true) {
            $block = $this->readExact(512, true);
            if ($block === null || trim($block, "\0") === '') {
                break;
            }
            $header = $this->parse($block);
            if ($header['type'] === 'x') {
                $paxName = $this->paxPath($this->readBody($header['size']));
                continue;
            }
            $name = $paxName ?? ($header['prefix'] !== '' ? $header['prefix'] . '/' . $header['name'] : $header['name']);
            $paxName = null;
            $name = rtrim($name, '/');
            if ($header['type'] === '5') {
                $this->checkName($name);
                if ($dest !== null) {
                    $this->makeDir($dest . '/' . $name);
                }
                continue;
            }
            if (!\in_array($header['type'], ['0', "\0"], true)) {
                throw new BackupException('The archive holds an entry that is not a plain file or directory; refusing it');
            }
            $this->checkName($name);
            if ($name === 'manifest.json') {
                $decoded = json_decode($this->readBody($header['size']), true);
                if (!\is_array($decoded)) {
                    throw new BackupException('The archive manifest is not readable');
                }
                $manifest = $decoded;
                continue;
            }
            if ($manifest !== null) {
                throw new BackupException('The archive has entries after its manifest; refusing it');
            }
            if (isset($seen[$name])) {
                throw new BackupException('The archive lists a file twice; refusing it');
            }
            $seen[$name] = $this->copyFile($name, $header['size'], $dest);
            $bytes += $header['size'];
            if ($this->progress !== null) {
                ($this->progress)($name);
            }
        }
        if ($manifest === null) {
            throw new BackupException('The archive has no manifest: it is incomplete or not a Reporion backup');
        }
        $this->compare($manifest, $seen);

        return ['manifest' => $manifest, 'files' => \count($seen), 'bytes' => $bytes];
    }

    /**
     * @param array<string, mixed>                       $manifest
     * @param array<string, array{size: int, sha256: string}> $seen
     */
    private function compare(array $manifest, array $seen): void
    {
        if ((int) ($manifest['format'] ?? 0) !== 1) {
            throw new BackupException('The archive was made by a newer or unknown Reporion; format ' . (int) ($manifest['format'] ?? 0));
        }
        $listed = [];
        foreach ((array) ($manifest['files'] ?? []) as $file) {
            if (!\is_array($file) || !isset($file['path'], $file['size'], $file['sha256'])) {
                throw new BackupException('The archive manifest is malformed');
            }
            $listed[(string) $file['path']] = $file;
        }
        if (array_diff_key($listed, $seen) !== [] || array_diff_key($seen, $listed) !== []) {
            throw new BackupException('The archive does not hold the files its manifest lists: it is truncated or was altered');
        }
        foreach ($seen as $name => $actual) {
            if ((int) $listed[$name]['size'] !== $actual['size'] || !hash_equals((string) $listed[$name]['sha256'], $actual['sha256'])) {
                throw new BackupException('A file in the archive does not match its recorded checksum: the archive is damaged');
            }
        }
    }

    /**
     * @return array{size: int, sha256: string}
     */
    private function copyFile(string $name, int $size, ?string $dest): array
    {
        $out = null;
        $target = null;
        if ($dest !== null) {
            $target = $dest . '/' . $name;
            $this->makeDir(\dirname($target));
            $out = @fopen($target, 'xb');
            if ($out === false) {
                throw new BackupException('Cannot write a restored file (exists already, or the disk is not writable)');
            }
        }
        $hash = hash_init('sha256');
        $left = $size;
        try {
            while ($left > 0) {
                $chunk = $this->readExact(min(1 << 20, $left));
                hash_update($hash, $chunk);
                if ($out !== null && fwrite($out, $chunk) === false) {
                    throw new BackupException('Cannot write a restored file (disk full?)');
                }
                $left -= \strlen($chunk);
            }
            if ($out !== null) {
                fflush($out);
                if (\function_exists('fsync')) {
                    fsync($out);
                }
            }
        } finally {
            if ($out !== null) {
                fclose($out);
            }
        }
        if ($target !== null) {
            @chmod($target, 0660);
        }
        $this->skipPad($size);

        return ['size' => $size, 'sha256' => hash_final($hash)];
    }

    private function makeDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new BackupException('Cannot create a restored directory');
        }
    }

    private function checkName(string $name): void
    {
        $bad = $name === ''
            || preg_match(self::ALLOWED, $name) !== 1
            || preg_match('#(^|/)\.\.?(/|$)|//|[\x00-\x1f\\\\]#', $name) === 1;
        if ($bad) {
            throw new BackupException('The archive holds an entry outside data/ and the two conf files; refusing it');
        }
    }

    /**
     * @return array{name: string, prefix: string, size: int, type: string}
     */
    private function parse(string $block): array
    {
        $sum = 0;
        for ($i = 0; $i < 512; ++$i) {
            $sum += ($i >= 148 && $i < 156) ? 32 : \ord($block[$i]);
        }
        if ((int) octdec(trim(substr($block, 148, 8), "\0 ")) !== $sum) {
            throw new BackupException('The archive is damaged (a header checksum does not match)');
        }
        $size = (int) octdec(trim(substr($block, 124, 12), "\0 "));
        if ($size < 0) {
            throw new BackupException('The archive is damaged (a negative size)');
        }

        return [
            'name' => rtrim(substr($block, 0, 100), "\0"),
            'prefix' => rtrim(substr($block, 345, 155), "\0"),
            'size' => $size,
            'type' => $block[156],
        ];
    }

    private function paxPath(string $data): ?string
    {
        foreach (explode("\n", $data) as $record) {
            if (preg_match('/^\d+ path=(.*)$/s', $record, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    private function readBody(int $size): string
    {
        if ($size > 4 * 1024 * 1024) {
            throw new BackupException('The archive is damaged (an oversized header record)');
        }
        $body = $size > 0 ? $this->readExact($size) : '';
        $this->skipPad($size);

        return $body;
    }

    private function skipPad(int $size): void
    {
        $rest = $size % 512;
        if ($rest !== 0) {
            $this->readExact(512 - $rest);
        }
    }

    /**
     * @return ($eofOk is true ? ?string : string)
     */
    private function readExact(int $length, bool $eofOk = false): ?string
    {
        $data = '';
        while (\strlen($data) < $length) {
            $part = gzread($this->gz, $length - \strlen($data));
            if ($part === false || $part === '') {
                if ($eofOk && $data === '') {
                    return null;
                }
                throw new BackupException('The archive is truncated');
            }
            $data .= $part;
        }

        return $data;
    }
}
