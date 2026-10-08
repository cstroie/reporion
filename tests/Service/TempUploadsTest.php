<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service;

use InvalidArgumentException;
use Reporion\Service\TempUploads;
use Reporion\Tests\Storage\StorageTestCase;

final class TempUploadsTest extends StorageTestCase
{
    public function testAFileIsTakenOnceAndRemoved(): void
    {
        $uploads = new TempUploads($this->dataRoot);
        $token = $uploads->put('dicom', 'bytes');

        self::assertFileExists($this->dataRoot . '/tmp/dicom/' . $token);
        self::assertSame('bytes', $uploads->take('dicom', $token));
        self::assertNull($uploads->take('dicom', $token), 'once');
        self::assertFileDoesNotExist($this->dataRoot . '/tmp/dicom/' . $token);
        self::assertSame([], glob($this->dataRoot . '/tmp/dicom/*.part') ?: [], 'no half-written file is left');
    }

    public function testAnOldFileIsNotGivenAndIsSweptOnTheNextPut(): void
    {
        $uploads = new TempUploads($this->dataRoot);
        $old = $uploads->put('dicom', 'old');
        touch($this->dataRoot . '/tmp/dicom/' . $old, time() - TempUploads::LIFETIME - 5);
        $stale = $uploads->put('dicom', 'also old');
        touch($this->dataRoot . '/tmp/dicom/' . $stale, time() - TempUploads::LIFETIME - 5);

        self::assertNull($uploads->take('dicom', $old), 'expired');
        $fresh = $uploads->put('dicom', 'new');
        self::assertFileDoesNotExist($this->dataRoot . '/tmp/dicom/' . $stale, 'swept');
        self::assertSame('new', $uploads->take('dicom', $fresh));
    }

    public function testATokenThatIsNotOneAndABadBucketAreRefused(): void
    {
        $uploads = new TempUploads($this->dataRoot);

        self::assertNull($uploads->take('dicom', '../../etc/passwd'));
        self::assertNull($uploads->take('dicom', str_repeat('a', 32)));
        $this->expectException(InvalidArgumentException::class);
        $uploads->put('../x', 'bytes');
    }
}
