<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

use Reporion\Service\Backup\ProcessRun;
use Reporion\Storage\StorageInterface;

/**
 * A library source's cover: the first page of its PDF as a small JPEG, a visual clue to tell one
 * paper from another (docs/library.md, *The reader*). Made by `pdftoppm` (an argument list, no
 * shell, a timeout: as the OCR's pages) the first time it is asked for and kept beside the PDF,
 * `data/sources/{ab}/{sha256}.thumb.jpg` (owner, 2026-11-15): backed up with it, made again
 * when missing; the name is no original's (`{sha256}.{ext}`), so integrity:verify passes it over.
 * A PDF pdftoppm cannot draw leaves an empty `{sha256}.thumb.none`, so it is not tried on every
 * view. Only PDFs have a cover; the caller checks who may see the source.
 */
final class Thumbnails
{
    /** The cover's width in pixels: twice the card's 150 px, for dense screens */
    public const WIDTH = 300;

    private const SECONDS = 20;

    /**
     * @param \Closure(list<string>, int, array<string, string>): array{0: int, 1: string}|null $runner
     */
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly string $pdftoppm = 'pdftoppm',
        private readonly ?\Closure $runner = null,
    ) {
    }

    /** Whether a source with this `original` can have a cover at all (a PDF) */
    public static function possible(mixed $original): bool
    {
        return \is_array($original) && ($original['ext'] ?? null) === 'pdf' && \is_string($original['sha256'] ?? null);
    }

    /**
     * The cover's file, made now when it is not there yet; null when the source has no PDF or its
     * first page cannot be drawn
     *
     * @param mixed $original the page's frontmatter `original`
     */
    public function of(mixed $original): ?string
    {
        if (!self::possible($original)) {
            return null;
        }
        $sha = (string) $original['sha256'];
        $pdf = $this->storage->originalFile($sha, 'pdf');
        if ($pdf === null) {
            return null;
        }
        $dir = \dirname($pdf);
        $file = $dir . '/' . $sha . '.thumb.jpg';
        if (is_file($file)) {
            return $file;
        }
        if (is_file($dir . '/' . $sha . '.thumb.none')) {
            return null;
        }
        // pdftoppm adds `.jpg` to the prefix; a name of its own, so two requests never share a half-written file
        $prefix = $dir . '/.' . $sha . '-' . bin2hex(random_bytes(4));
        $argv = [$this->pdftoppm, '-f', '1', '-l', '1', '-singlefile', '-jpeg', '-scale-to-x', (string) self::WIDTH, '-scale-to-y', '-1', $pdf, $prefix];
        [$exit] = $this->runner !== null ? ($this->runner)($argv, self::SECONDS, []) : ProcessRun::run($argv, self::SECONDS);
        $made = $prefix . '.jpg';
        if ($exit !== 0 || !is_file($made) || filesize($made) === 0) {
            @unlink($made);
            // The program is missing (-1, 127) or was killed: try again another time; a PDF it refuses is not asked again
            if ($exit > 0 && $exit !== 127) {
                @touch($dir . '/' . $sha . '.thumb.none');
            }

            return null;
        }
        if (!@rename($made, $file)) {
            @unlink($made);

            return null;
        }

        return $file;
    }
}
