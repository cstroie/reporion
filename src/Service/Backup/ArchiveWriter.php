<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Backup;

use Reporion\Exception\BackupException;

/**
 * Streams a gzip-compressed ustar archive (D44): one file at a time, never
 * the whole tree in memory, no second copy on disk. Names over 100 bytes go
 * in ustar's prefix field or a PAX `path` record. Every file's size and
 * sha256 are kept for the manifest, which is the archive's last entry.
 */
final class ArchiveWriter
{
    /** @var resource */
    private $gz;

    /** @var list<array{path: string, size: int, sha256: string}> */
    private array $files = [];

    private int $dirs = 0;

    public function __construct(private readonly string $path)
    {
        $gz = @gzopen($path, 'wb6');
        if ($gz === false) {
            throw new BackupException('Cannot write the backup file');
        }
        $this->gz = $gz;
    }

    public function addDirectory(string $name): void
    {
        $this->header(rtrim($name, '/') . '/', 0, '5', 0770);
        ++$this->dirs;
    }

    /**
     * @throws BackupException when the file cannot be read in full (it changed while being read)
     */
    public function addFile(string $name, string $source): void
    {
        $in = @fopen($source, 'rb');
        if ($in === false) {
            throw new BackupException('Cannot read a file to back up');
        }
        try {
            $stat = fstat($in);
            $size = $stat === false ? 0 : (int) $stat['size'];
            $this->header($name, $size, '0', 0660);
            $hash = hash_init('sha256');
            $left = $size;
            while ($left > 0) {
                $chunk = fread($in, min(1 << 20, $left));
                if ($chunk === false || $chunk === '') {
                    throw new BackupException('A file shrank while it was being backed up; run the backup again');
                }
                hash_update($hash, $chunk);
                $this->write($chunk);
                $left -= \strlen($chunk);
            }
        } finally {
            fclose($in);
        }
        $this->pad($size);
        $this->files[] = ['path' => $name, 'size' => $size, 'sha256' => hash_final($hash)];
    }

    /** Writes a small generated entry (not part of the manifest's file list) */
    public function addString(string $name, string $content): void
    {
        $this->header($name, \strlen($content), '0', 0660);
        $this->write($content);
        $this->pad(\strlen($content));
    }

    /**
     * @param array<string, mixed> $manifest the extra fields; files, counts and format are added here
     *
     * @return array{files: int, bytes: int}
     */
    public function finish(array $manifest): array
    {
        $bytes = array_sum(array_column($this->files, 'size'));
        $manifest = ['format' => 1, 'files' => $this->files, 'file_count' => \count($this->files), 'dir_count' => $this->dirs, 'bytes' => $bytes] + $manifest;
        $this->addString('manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->write(str_repeat("\0", 1024));
        if (!gzclose($this->gz)) {
            throw new BackupException('Cannot finish the backup file');
        }

        return ['files' => \count($this->files), 'bytes' => (int) $bytes];
    }

    public function abort(): void
    {
        @gzclose($this->gz);
        @unlink($this->path);
    }

    private function write(string $data): void
    {
        if ($data !== '' && gzwrite($this->gz, $data) === false) {
            throw new BackupException('Cannot write the backup file (disk full?)');
        }
    }

    private function pad(int $size): void
    {
        $rest = $size % 512;
        if ($rest !== 0) {
            $this->write(str_repeat("\0", 512 - $rest));
        }
    }

    private function header(string $name, int $size, string $type, int $mode): void
    {
        $prefix = '';
        $short = $name;
        if (\strlen($name) > 100) {
            $cut = strrpos(substr($name, 0, 156), '/');
            if ($cut !== false && $cut > 0 && \strlen($name) - $cut - 1 <= 100 && \strlen(rtrim($name, '/')) - $cut - 1 > 0) {
                $prefix = substr($name, 0, $cut);
                $short = substr($name, $cut + 1);
            } else {
                $this->pax($name);
                $short = substr($name, 0, 100);
            }
        }
        $this->block($short, $prefix, $size, $type, $mode);
    }

    private function pax(string $name): void
    {
        $record = ' path=' . $name . "\n";
        $length = \strlen($record) + 1;
        while (\strlen((string) $length) + \strlen($record) !== $length) {
            $length = \strlen((string) $length) + \strlen($record);
        }
        $data = $length . $record;
        $this->block('PaxHeader', '', \strlen($data), 'x', 0644);
        $this->write($data);
        $this->pad(\strlen($data));
    }

    private function block(string $name, string $prefix, int $size, string $type, int $mode): void
    {
        $fields = [
            str_pad($name, 100, "\0"),
            sprintf('%07o', $mode) . "\0",
            sprintf('%07o', 0) . "\0",
            sprintf('%07o', 0) . "\0",
            sprintf('%011o', $size) . "\0",
            sprintf('%011o', time()) . "\0",
            '        ',
            $type,
            str_repeat("\0", 100),
            "ustar\0" . '00',
            str_pad('', 32, "\0"),
            str_pad('', 32, "\0"),
            sprintf('%07o', 0) . "\0",
            sprintf('%07o', 0) . "\0",
            str_pad($prefix, 155, "\0"),
        ];
        $block = str_pad(implode('', $fields), 512, "\0");
        $sum = 0;
        for ($i = 0; $i < 512; ++$i) {
            $sum += \ord($block[$i]);
        }
        $block = substr_replace($block, sprintf('%06o', $sum) . "\0 ", 148, 8);
        $this->write($block);
    }
}
