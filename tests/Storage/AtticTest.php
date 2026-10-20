<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Storage;

use DateTimeImmutable;
use Reporion\Exception\AtticException;
use Reporion\Storage\Attic;
use Reporion\Support\Revlog;
use Reporion\Support\Zip;

/**
 * D54 step 3: a page's history as one zip (docs/architecture-v2.md §4):
 * write-once entries, verified before the rename, invariant 3's minor-edit
 * exception, and the crash window.
 */
final class AtticTest extends StorageTestCase
{
    private const PID = '01JB8X4MT7QK2V9Z0C3R5H6ND0';

    private Attic $attic;
    private string $zip;
    private DateTimeImmutable $at;

    protected function setUp(): void
    {
        parent::setUp();
        $this->attic = new Attic();
        $this->zip = $this->dataRoot . '/attic/reports/mri/x/260922-test.zip';
        $this->at = new DateTimeImmutable('2026-10-20 09:00:00');
    }

    public function testRevisionsAndTheRecordReadBack(): void
    {
        $docs = ["---\nvisibility: private\n---\n\nunu\n", "---\nvisibility: private\n---\n\ndoi\n", "---\nvisibility: private\n---\n\ntrei\n"];
        $history = $this->appendAll($docs);

        foreach ($docs as $i => $doc) {
            self::assertSame($doc, $this->attic->revision($this->zip, $i + 1));
        }
        self::assertSame($history, $this->attic->history($this->zip));
        self::assertSame([], $this->attic->problems($this->zip));
        self::assertSame(['0001.md', '0002.md', '0003.md', 'revlog.json'], array_keys(Zip::read((string) file_get_contents($this->zip))));
    }

    public function testEarlierEntriesAreCopiedForwardByteForByte(): void
    {
        $this->appendAll(['a', 'b']);
        $before = Zip::read((string) file_get_contents($this->zip));

        $this->appendAll(['a', 'b', 'c']);
        $after = Zip::read((string) file_get_contents($this->zip));

        self::assertTrue($after['0001.md']->sameAs($before['0001.md']));
        self::assertTrue($after['0002.md']->sameAs($before['0002.md']));
        self::assertSame($before['0001.md']->dosTime, $after['0001.md']->dosTime);
    }

    public function testTheSameAppendAgainWritesNothing(): void
    {
        $history = $this->appendAll(['a', 'b']);
        $bytes = file_get_contents($this->zip);

        self::assertFalse($this->attic->append($this->zip, 'b', $history, $this->at));
        self::assertSame($bytes, file_get_contents($this->zip));
    }

    public function testAGapOrADifferentRevisionAtTheSameNumberIsRefused(): void
    {
        $history = $this->appendAll(['a', 'b']);
        $bytes = file_get_contents($this->zip);

        $this->assertRefused('gap', fn () => $this->attic->append($this->zip, 'd', $this->history(['a', 'b', 'c', 'd']), $this->at));
        $other = $this->history(['a', 'B']);
        $this->assertRefused('revision_exists', fn () => $this->attic->append($this->zip, 'B', $other, $this->at));
        self::assertSame($bytes, file_get_contents($this->zip));
        self::assertSame($history, $this->attic->history($this->zip));
    }

    public function testTheFirstRevisionMustBeOne(): void
    {
        $this->assertRefused('gap', fn () => $this->attic->append($this->zip, 'b', $this->history(['a', 'b']), $this->at));
        self::assertFileDoesNotExist($this->zip);
    }

    public function testARecordThatRewritesHistoryIsRefusedAndTheZipIsUnchanged(): void
    {
        $this->appendAll(['a', 'b'], [['rev' => 2]]);
        $bytes = file_get_contents($this->zip);
        $next = $this->history(['a', 'b', 'c'], [['rev' => 2]]);

        $edited = $next;
        $edited['revlog'][0]['note'] = 'changed afterwards';
        $this->assertRefused('history_changed', fn () => $this->attic->append($this->zip, 'c', $edited, $this->at));

        $unsigned = $next;
        $unsigned['signatures'] = [];
        $this->assertRefused('signature_removed', fn () => $this->attic->append($this->zip, 'c', $unsigned, $this->at));

        $other = $next;
        $other['pid'] = '01JB8X4MT7QK2V9Z0C3R5H6ND9';
        $this->assertRefused('pid_changed', fn () => $this->attic->append($this->zip, 'c', $other, $this->at));

        $unchained = $next;
        unset($unchained['revlog'][2]['chain']);
        $this->assertRefused('chain_missing', fn () => $this->attic->append($this->zip, 'c', $unchained, $this->at));

        // the record describes other bytes than the ones given: caught reading the temp file back
        $this->assertRefused('sha_mismatch', fn () => $this->attic->append($this->zip, 'not c', $next, $this->at));

        self::assertSame($bytes, file_get_contents($this->zip));
        self::assertSame([], glob(\dirname($this->zip) . '/*.tmp-*'));
    }

    public function testTheArchivedMarkerIsNeverTakenAway(): void
    {
        $archived = $this->history(['a']);
        $archived['status'] = 'archived';
        $archived['archived'] = ['by' => 'importer', 'at' => '2026-10-01T10:00:00+03:00', 'rev' => 1];
        $this->attic->append($this->zip, 'a', $archived, $this->at);

        $next = $this->history(['a', 'b']);
        $this->assertRefused('archived_changed', fn () => $this->attic->append($this->zip, 'b', $next, $this->at));
    }

    public function testAMinorEditReplacesOnlyTheUnsignedNewestEntry(): void
    {
        $this->appendAll(['a', 'b']);
        $before = Zip::read((string) file_get_contents($this->zip));

        $this->attic->replaceNewest($this->zip, 'b, typo fixed', $this->history(['a', 'b, typo fixed']), $this->at);

        $after = Zip::read((string) file_get_contents($this->zip));
        self::assertTrue($after['0001.md']->sameAs($before['0001.md']));
        self::assertSame('b, typo fixed', $this->attic->revision($this->zip, 2));
        self::assertSame([], $this->attic->problems($this->zip));
    }

    public function testAMinorEditOfASignedRevisionIsRefused(): void
    {
        $this->appendAll(['a', 'b'], [['rev' => 2]]);
        $bytes = file_get_contents($this->zip);

        $this->assertRefused('signed', fn () => $this->attic->replaceNewest($this->zip, 'b2', $this->history(['a', 'b2'], [['rev' => 2]]), $this->at));
        self::assertSame($bytes, file_get_contents($this->zip));
    }

    public function testAMinorEditMayNotTouchAnOlderRevision(): void
    {
        $this->appendAll(['a', 'b']);

        $this->assertRefused('history_changed', fn () => $this->attic->replaceNewest($this->zip, 'b', $this->history(['A', 'b']), $this->at));
    }

    public function testSigningRewritesTheRecordOnly(): void
    {
        $history = $this->appendAll(['a', 'b']);
        $before = Zip::read((string) file_get_contents($this->zip));

        $signed = $history;
        $signed['status'] = 'signed';
        $signed['signatures'][] = self::signature(2);
        $this->attic->writeHistory($this->zip, $signed, $this->at);

        $after = Zip::read((string) file_get_contents($this->zip));
        self::assertTrue($after['0002.md']->sameAs($before['0002.md']));
        self::assertSame('signed', $this->attic->history($this->zip)['status']);

        $sneaky = $signed;
        $sneaky['revlog'][1]['by'] = 'someone else';
        $this->assertRefused('history_changed', fn () => $this->attic->writeHistory($this->zip, $sneaky, $this->at));
    }

    public function testACorruptEarlierEntryBlocksTheNextWriteAndIsReported(): void
    {
        $this->appendAll(['first revision text', 'b']);
        $bytes = (string) file_get_contents($this->zip);
        $entry = Zip::read($bytes)['0001.md'];
        $flipped = $entry->data;
        $flipped[0] = \chr(\ord($flipped[0]) ^ 0xFF);
        file_put_contents($this->zip, str_replace($entry->data, $flipped, $bytes));
        $corrupt = file_get_contents($this->zip);

        self::assertContains(['rev' => 1, 'problem' => 'entry_corrupt'], $this->attic->problems($this->zip));
        $this->assertRefused('entry_corrupt', fn () => $this->attic->append($this->zip, 'c', $this->history(['first revision text', 'b', 'c']), $this->at));
        self::assertSame($corrupt, file_get_contents($this->zip));
    }

    public function testATempFileLeftByAKilledWriteIsHarmless(): void
    {
        $this->appendAll(['a']);
        file_put_contents($this->zip . '.tmp-deadbeef', 'half a zip');

        self::assertSame('a', $this->attic->revision($this->zip, 1));
        self::assertTrue($this->attic->append($this->zip, 'b', $this->history(['a', 'b']), $this->at));
        self::assertSame([], $this->attic->problems($this->zip));
    }

    /**
     * Crash test (CLAUDE.md "Storage"): the process is killed after the new
     * zip is durable in its temp file and before the rename. The old zip is
     * whole, only a stray temp file remains, and the next write succeeds.
     */
    public function testAProcessKilledBeforeTheRenameLeavesTheOldZip(): void
    {
        if (!\function_exists('posix_kill') || !\function_exists('posix_getpid')) {
            self::markTestSkipped('needs ext-posix');
        }
        $this->appendAll(['a']);
        $before = file_get_contents($this->zip);
        $next = $this->history(['a', 'b']);

        $script = $this->dataRoot . '/kill.php';
        file_put_contents($script, '<?php
            require ' . var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';
            // the attic write, with its check replaced by a kill: the same putChecked() call
            $zip = ' . var_export($this->zip, true) . ';
            $history = json_decode(' . var_export(json_encode($next), true) . ', true);
            $old = \Reporion\Support\Zip::read(file_get_contents($zip));
            unset($old["revlog.json"]);
            $at = new DateTimeImmutable("2026-10-20 09:00:00");
            $entries = array_values($old);
            $entries[] = \Reporion\Support\ZipEntry::deflated("0002.md", "b", $at);
            $entries[] = \Reporion\Support\ZipEntry::deflated("revlog.json", json_encode($history), $at);
            \Reporion\Storage\AtomicWriter::putChecked($zip, \Reporion\Support\Zip::assemble($entries), static function (): void {
                posix_kill(posix_getpid(), SIGKILL);
            });
            echo "not killed";
        ');
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output, $status);

        self::assertNotSame(0, $status);
        self::assertNotContains('not killed', $output);
        self::assertSame($before, file_get_contents($this->zip));
        self::assertCount(1, glob($this->zip . '.tmp-*') ?: []);
        self::assertSame([], $this->attic->problems($this->zip));
        self::assertTrue($this->attic->append($this->zip, 'b', $next, $this->at));
        self::assertSame('b', $this->attic->revision($this->zip, 2));
    }

    public function testAMissingOrForeignZipSaysSo(): void
    {
        self::assertSame([['rev' => null, 'problem' => 'missing']], $this->attic->problems($this->zip));
        mkdir(\dirname($this->zip), 0775, true);
        file_put_contents($this->zip, 'not a zip');
        self::assertSame([['rev' => null, 'problem' => 'unreadable']], $this->attic->problems($this->zip));
        $this->assertRefused('unreadable', fn () => $this->attic->history($this->zip));
    }

    /**
     * Append $docs one by one, from the first the zip does not hold yet
     *
     * @param list<string>               $docs
     * @param list<array{rev: int}>      $signatures applied once their revision exists
     *
     * @return array<string, mixed> the record after the last
     */
    private function appendAll(array $docs, array $signatures = []): array
    {
        $history = [];
        $there = is_file($this->zip) ? \count($this->attic->history($this->zip)['revlog']) : 0;
        foreach (array_keys($docs) as $i) {
            $have = array_values(array_filter($signatures, static fn (array $s): bool => $s['rev'] <= $i + 1));
            $history = $this->history(\array_slice($docs, 0, $i + 1), $have);
            if ($i >= $there) {
                $this->attic->append($this->zip, $docs[$i], $history, $this->at);
            }
        }

        return json_decode((string) json_encode($history), true);
    }

    /**
     * The record for $docs as revisions 1..N, chained as Storage writes it
     *
     * @param list<string>          $docs
     * @param list<array{rev: int}> $signatures
     *
     * @return array<string, mixed>
     */
    private function history(array $docs, array $signatures = []): array
    {
        $revlog = [];
        foreach ($docs as $i => $doc) {
            $revlog[] = Revlog::chained([
                'n' => $i + 1,
                'ts' => sprintf('2026-10-%02dT10:00:00+03:00', $i + 1),
                'by' => 'a.radiolog',
                'note' => null,
                'bytes' => \strlen($doc),
                'sha256' => hash('sha256', $doc),
                'minor' => false,
                'kind' => $i === 0 ? 'create' : 'edit',
            ], $revlog, self::PID);
        }

        return [
            'v' => Attic::FORMAT,
            'pid' => self::PID,
            'status' => $signatures === [] ? 'draft' : 'signed',
            'archived' => null,
            'revlog' => $revlog,
            'signatures' => array_map(static fn (array $s): array => self::signature($s['rev']), $signatures),
        ];
    }

    /** @return array<string, mixed> */
    private static function signature(int $rev): array
    {
        return ['rev' => $rev, 'by' => 'a.radiolog', 'ts' => '2026-10-20T10:00:00+03:00', 'alg' => 'sha256', 'digest' => str_repeat('a', 64), 'parafa' => null, 'field_order' => ['title' => []]];
    }

    private function assertRefused(string $reason, callable $write): void
    {
        try {
            $write();
            self::fail('expected the attic to refuse: ' . $reason);
        } catch (AtticException $e) {
            self::assertSame($reason, $e->reason);
        }
    }
}
