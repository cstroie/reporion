<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Storage;

use Reporion\Storage\AtomicWriter;

final class AtomicWriterTest extends StorageTestCase
{
    public function testPutWritesBytesAndLeavesNoTempFile(): void
    {
        $target = $this->dataRoot . '/current.md';

        AtomicWriter::put($target, 'first');

        self::assertSame('first', file_get_contents($target));
        self::assertSame([], glob($this->dataRoot . '/*.tmp-*'));
    }

    public function testPutOverwritesExistingFileAtomically(): void
    {
        $target = $this->dataRoot . '/current.md';
        AtomicWriter::put($target, 'first');
        AtomicWriter::put($target, 'second');

        self::assertSame('second', file_get_contents($target));
    }

    public function testPutOnceWritesOnFirstCall(): void
    {
        $target = $this->dataRoot . '/rev/0001.md.gz';
        mkdir(\dirname($target), 0775, true);

        $wrote = AtomicWriter::putOnce($target, 'bytes-1');

        self::assertTrue($wrote);
        self::assertSame('bytes-1', file_get_contents($target));
    }

    /**
     * The revision file is the append-only history (CLAUDE.md invariant 3):
     * a second write to the same rev number must never clobber it.
     */
    public function testPutOnceRefusesToOverwrite(): void
    {
        $target = $this->dataRoot . '/rev/0001.md.gz';
        mkdir(\dirname($target), 0775, true);

        AtomicWriter::putOnce($target, 'original');
        $wroteAgain = AtomicWriter::putOnce($target, 'different-bytes');

        self::assertFalse($wroteAgain);
        self::assertSame('original', file_get_contents($target));
    }
}
