<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Library;

use PHPUnit\Framework\TestCase;
use Reporion\Service\Library\Thumbnails;
use Reporion\Storage\FileTree;
use Reporion\Tests\Storage\RecordingIndex;

/**
 * A source's cover (docs/library.md, *The reader*), pdftoppm stubbed: drawn once and kept beside
 * the PDF in data/sources, only for a PDF, a PDF that cannot be drawn not tried at every view.
 */
final class ThumbnailsTest extends TestCase
{
    private string $dataRoot;

    private FileTree $storage;

    /** @var list<list<string>> */
    private array $ran = [];

    protected function setUp(): void
    {
        $this->dataRoot = sys_get_temp_dir() . '/reporion-thumbs-' . bin2hex(random_bytes(4));
        mkdir($this->dataRoot);
        $this->storage = new FileTree($this->dataRoot, new RecordingIndex());
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dataRoot));
    }

    public function testTheCoverIsDrawnOnceFromThePdfsFirstPageAndKept(): void
    {
        $original = $this->storage->storeOriginal('%PDF-1.4 made up', 'pdf');
        $thumbs = $this->thumbnails(0, 'JPEG');

        $file = $thumbs->of($original);

        self::assertSame($this->dataRoot . '/sources/' . substr($original['sha256'], 0, 2) . '/' . $original['sha256'] . '.thumb.jpg', $file);
        self::assertSame('JPEG', file_get_contents((string) $file));
        $argv = $this->ran[0];
        self::assertSame(['pdftoppm', '-f', '1', '-l', '1', '-singlefile', '-jpeg', '-scale-to-x', '300', '-scale-to-y', '-1'], \array_slice($argv, 0, 11));
        self::assertSame($this->storage->originalFile($original['sha256'], 'pdf'), $argv[11], 'the original itself, never a copy');

        self::assertSame($file, $thumbs->of($original));
        self::assertCount(1, $this->ran, 'the second view reads the kept file');
        self::assertSame([$original['sha256'] . '.pdf', $original['sha256'] . '.thumb.jpg'], array_values(array_diff(scandir(\dirname((string) $file)) ?: [], ['.', '..'])), 'nothing half-written left behind');
    }

    public function testOnlyAPdfThatIsThereHasACover(): void
    {
        $thumbs = $this->thumbnails(0, 'JPEG');
        $text = $this->storage->storeOriginal('plain text', 'txt');

        self::assertFalse(Thumbnails::possible($text));
        self::assertFalse(Thumbnails::possible(null));
        self::assertTrue(Thumbnails::possible(['sha256' => str_repeat('a', 64), 'ext' => 'pdf']));
        self::assertNull($thumbs->of($text));
        self::assertNull($thumbs->of(null));
        self::assertNull($thumbs->of(['sha256' => str_repeat('a', 64), 'ext' => 'pdf']), 'no such original');
        self::assertNull($thumbs->of(['sha256' => '../../etc/passwd', 'ext' => 'pdf']));
        self::assertSame([], $this->ran);
    }

    public function testAPdfThatCannotBeDrawnIsNotTriedAgainButAMissingProgramIs(): void
    {
        $original = $this->storage->storeOriginal('%PDF-1.4 broken', 'pdf');

        $missing = $this->thumbnails(127, null);
        self::assertNull($missing->of($original));
        self::assertNull($missing->of($original));
        self::assertCount(2, $this->ran, 'pdftoppm not installed: asked again, it may be installed later');

        $broken = $this->thumbnails(1, null);
        self::assertNull($broken->of($original));
        self::assertNull($broken->of($original));
        self::assertCount(3, $this->ran, 'a PDF pdftoppm refuses is remembered');
        self::assertNull($this->thumbnails(0, 'JPEG')->of($original));
    }

    private function thumbnails(int $exit, ?string $writes): Thumbnails
    {
        return new Thumbnails($this->storage, 'pdftoppm', function (array $argv, int $timeout, array $env) use ($exit, $writes): array {
            $this->ran[] = $argv;
            if ($writes !== null) {
                file_put_contents(end($argv) . '.jpg', $writes);
            }

            return [$exit, ''];
        });
    }
}
