<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Guide;

use Reporion\Exception\GuideException;
use Reporion\Index\Sqlite;
use Reporion\Service\Guide\Guide;
use Reporion\Service\Guide\GuideCsv;
use Reporion\Tests\Storage\StorageTestCase;

/** The imaging guide (D62): the CSV read, kept, indexed and searched */
final class GuideTest extends StorageTestCase
{
    private Sqlite $index;
    private Guide $guide;
    private string $csv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 3) . '/migrations');
        $this->guide = new Guide($this->index->guide(), $this->dataRoot, \dirname(__DIR__, 3) . '/resources/guide-synonyms.json');
        $this->csv = (string) file_get_contents(\dirname(__DIR__, 2) . '/fixtures/guide/guide-sample.csv');
    }

    public function testTheCsvIsReadWithStableKeysAndItsOddValuesAsWarnings(): void
    {
        $parsed = GuideCsv::parse($this->csv);
        self::assertCount(8, $parsed['rows']);
        self::assertSame(5, $parsed['situations']);
        self::assertSame($parsed['rows'][0]['situation'], $parsed['rows'][1]['situation'], 'two exams of one situation');
        self::assertSame($parsed['rows'][0]['situation'] . '.2', $parsed['rows'][1]['key']);
        self::assertSame(1, $parsed['rows'][1]['therapeutic']);
        self::assertNull($parsed['rows'][7]['therapeutic']);
        self::assertCount(3, $parsed['warnings'], 'type Q, the indication and grade Z');

        // Renumbered, a cedilla for the comma below, extra spaces: the same keys
        $renumbered = (string) preg_replace_callback('/^"(\d+)"/m', static fn (array $m): string => '"' . ((int) $m[1] + 100) . '"', $this->csv);
        $renumbered = str_replace('Invaginație intestinală', 'Invaginaţie   intestinală', $renumbered);
        self::assertSame(array_column($parsed['rows'], 'key'), array_column(GuideCsv::parse($renumbered)['rows'], 'key'));
    }

    public function testAFileThatIsNotTheGuideOrHoldsACnpIsRefused(): void
    {
        foreach (["\"a\",\"b\"\n\"1\",\"2\"\n", "\xff\xfe"] as $bytes) {
            try {
                GuideCsv::parse($bytes);
                self::fail('refused');
            } catch (GuideException $e) {
                self::assertSame(GuideException::INVALID, $e->reason);
            }
        }
        $this->expectExceptionObject(new GuideException(GuideException::PATIENT_DATA));
        // A made-up, checksum-valid CNP in a comment
        GuideCsv::parse(str_replace('Exclude hemoragia.', 'Pacient 1800101221144.', $this->csv));
    }

    public function testLoadKeepsTheFileAndSearchFindsSituationsWithoutDiacriticsAndByPrefix(): void
    {
        $result = $this->guide->load($this->csv, 'GIRI@test', 'owner');
        self::assertSame([8, 5, false], [$result['rows'], $result['situations'], $result['unchanged']]);
        self::assertFileExists($this->dataRoot . '/guide/guide.csv');
        $info = $this->guide->info();
        self::assertSame(['GIRI@test', true], [$info['source'] ?? null, $info['indexed'] ?? null]);

        self::assertSame('Durere paroxistică. Invaginație intestinală acută', $this->guide->search('invaginatie')[0]['title'] ?? null);
        self::assertSame('Apendicită', $this->guide->search('apendic')[0]['title'] ?? null, 'the last word as a prefix');
        $stroke = $this->guide->search('stroke');
        self::assertSame('Accident vascular cerebral acut', $stroke[0]['title'] ?? null, 'an IRIS synonym');
        self::assertSame(['T', 'M'], array_column($stroke[0]['exams'], 'tip'));
        self::assertSame('indicated', $stroke[0]['exams'][0]['indication_code']);
        self::assertSame('Apendicită', $this->guide->search('apendicita copil iradiere zzz')[0]['title'] ?? null, 'not every word: the most of them');

        self::assertSame(['Apendicită'], array_column($this->guide->search('', ['tip' => ['T'], 'chapter' => 'Pediatrie']), 'title'));
        self::assertSame(['Durere toracică nespecifică'], array_column($this->guide->search('', ['indication' => ['not-first']]), 'title'));
        self::assertSame(['Pediatrie', 'Cap', 'Torace'], array_column($this->guide->outline(), 'chapter'));

        $key = $stroke[0]['key'];
        self::assertSame('Accident vascular cerebral acut', $this->guide->situation($key)['title'] ?? null);
        self::assertNull($this->guide->situation('000000000000'));
        self::assertNull($this->guide->situation('../etc'));
        self::assertTrue($this->guide->load($this->csv, 'GIRI@test', 'owner')['unchanged']);
    }

    public function testARefillFromTheKeptFileGivesTheSameRowsAndADryRunKeepsNothing(): void
    {
        $dry = $this->guide->load($this->csv, '', 'owner', true);
        self::assertSame(8, $dry['rows']);
        self::assertFileDoesNotExist($this->dataRoot . '/guide/guide.csv');
        self::assertSame([], $this->guide->search('ecografie'));

        $this->guide->load($this->csv, '', 'owner');
        $pdo = new \PDO('sqlite:' . $this->dataRoot . '/index.sqlite');
        $dump = static fn (): array => [$pdo->query('SELECT * FROM guide_row ORDER BY key')->fetchAll(\PDO::FETCH_ASSOC), $pdo->query('SELECT * FROM guide_fts ORDER BY situation')->fetchAll(\PDO::FETCH_ASSOC)];
        $loaded = $dump();
        $this->index->guide()->replace([], null);
        self::assertSame([], $this->guide->search('ecografie'));
        self::assertFalse($this->guide->info()['indexed'] ?? null);
        self::assertSame(8, $this->guide->refill());
        self::assertSame($loaded, $dump(), 'rebuild = load');
        self::assertTrue($this->guide->info()['indexed'] ?? null);
    }

    public function testIndexRebuildRefillsTheGuideFromTheKeptFile(): void
    {
        $this->guide->load($this->csv, '', 'owner');
        $this->index->guide()->replace([], null);
        (new \Reporion\Service\IndexMaintenance(new \Reporion\Storage\FileTree($this->dataRoot, $this->index), $this->index, $this->dataRoot, ''))->rebuild();
        self::assertSame('Apendicită', $this->guide->search('apendicita')[0]['title'] ?? null);
    }

    public function testForAReportAChildsSituationsComeFirstAndItsExamIsMarked(): void
    {
        $this->guide->load($this->csv, '', 'owner');
        $child = $this->guide->forReport('Durere abdominală, suspiciune apendicită', ['abdomen'], ['CT'], 9);
        self::assertSame('Apendicită', $child[0]['title'] ?? null);
        self::assertSame([false, true], array_column($child[0]['exams'], 'this'), 'the CT row is this exam');

        $adult = $this->guide->forReport('cefalee brusca, suspiciune AVC', [], ['MR'], 60);
        self::assertSame('Accident vascular cerebral acut', $adult[0]['title'] ?? null);
        self::assertSame([false, true], array_column($adult[0]['exams'], 'this'));
        self::assertSame([], $this->guide->forReport('', [], ['CT'], 40));
    }

    public function testACautionOnlyOnAStrongMatchWhoseRowsForThisExamAreDiscouraged(): void
    {
        $this->guide->load($this->csv, '', 'owner');
        $caution = $this->guide->caution('Durere toracică nespecifică', [], ['DX'], 50);
        self::assertSame('Durere toracică nespecifică', $caution['situation']['title'] ?? null);
        self::assertSame('not-first', $caution['exam']['indication_code'] ?? null);
        self::assertNull($this->guide->caution('Durere toracică nespecifică', [], ['CT'], 50), 'no row of this exam type');
        self::assertNull($this->guide->caution('Accident vascular cerebral acut', [], ['CT'], 60), 'indicated: nothing to say');
        self::assertNull($this->guide->caution('durere', [], ['DX'], 50, 0.99, 0.99), 'a weak match never warns');
    }
}
