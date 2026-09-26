<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Storage;

use Reporion\Index\Sqlite;
use Reporion\Service\Accessions;
use Reporion\Storage\FlatFile;

/**
 * D20 for reports created here: per site + modality + year, continuing
 * the numbers already issued (imported ones included), never reissued.
 */
final class AccessionsTest extends StorageTestCase
{
    public function testAFirstUseContinuesAboveTheNumbersOnDisk(): void
    {
        [$storage, $index] = $this->wiring();
        $storage->create('reports:mri:scuc:a', ['title' => 'A', 'visibility' => 'private', 'accession' => 'SCUC-MR-26-0041'], "x\n", 'owner');
        $storage->create('reports:mri:scuc:b', ['title' => 'B', 'visibility' => 'private', 'accession' => 'SCUC-MR-25-0900'], "x\n", 'owner');
        $accessions = new Accessions($this->dataRoot, $index);

        self::assertSame('SCUC-MR-26-0042', $accessions->peek('scuc', 'MR', '26'));
        self::assertSame('SCUC-MR-26-0042', $accessions->peek('scuc', 'MR', '26'), 'a peek allocates nothing');
        self::assertSame('SCUC-MR-26-0042', $accessions->allocate('scuc', 'MR', '26'));
        self::assertSame('SCUC-MR-26-0043', $accessions->allocate('scuc', 'MR', '26'));
        self::assertSame('SCUC-CT-26-0001', $accessions->allocate('scuc', 'CT', '26'), 'each modality its own sequence');
        self::assertSame('SCUC-MR-25-0901', $accessions->allocate('scuc', 'MR', '25'), 'and each year');
        self::assertSame(['scuc:MR:26' => 43, 'scuc:CT:26' => 1, 'scuc:MR:25' => 901], json_decode((string) file_get_contents($this->dataRoot . '/counters.json'), true));
    }

    public function testNumbersImportedSinceAreNeverCollidedWith(): void
    {
        [$storage, $index] = $this->wiring();
        $accessions = new Accessions($this->dataRoot, $index);
        self::assertSame('MV-MR-26-0001', $accessions->allocate('mv', 'MR', '26'));

        // An import batch lands later with higher numbers
        $storage->create('reports:mri:mioveni:c', ['title' => 'C', 'visibility' => 'private', 'accession' => 'MV-MR-26-0100'], "x\n", 'owner');

        self::assertSame('MV-MR-26-0101', $accessions->allocate('mv', 'MR', '26'));
    }

    public function testAnotherPatternAndPadding(): void
    {
        [, $index] = $this->wiring();

        self::assertSame('26/MR/SCUC/000001', (new Accessions($this->dataRoot, $index, '{yy}/{MOD}/{SITE}/{seq}', 6))->allocate('scuc', 'MR', '26'));
    }

    /** @return array{FlatFile, Sqlite} */
    private function wiring(): array
    {
        $index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');

        return [new FlatFile($this->dataRoot, $index), $index];
    }
}
