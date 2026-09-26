<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use Reporion\Audit\AuditLog;
use Reporion\Cli\Output;
use Reporion\Cli\PagesCheckFrontmatterCommand;
use Reporion\Service\FrontmatterRepair;
use Reporion\Storage\FlatFile;
use Reporion\Support\DocumentFormat;
use Reporion\Tests\Storage\RecordingIndex;
use Reporion\Tests\Storage\StorageTestCase;
use Reporion\Service\Maintenance\FrontmatterCheckTask;
use Reporion\Service\Maintenance\MaintenanceRunner;

/**
 * The editor autosave before 2026-09-26 flattened frontmatter; this finds
 * and repairs what it wrote, from the last intact revision.
 */
final class PagesCheckFrontmatterTest extends StorageTestCase
{
    private const PATH = 'reports:mri:mioveni:260101-test-subject';
    private const INTACT = [
        'title' => 'RM coloană lombară', 'visibility' => 'private', 'modality' => ['MR'], 'region' => ['spine'],
        'site' => 'mioveni', 'study_date' => '2026-09-15T09:30:00+03:00', 'accession' => 'MV-MR-26-0001',
        'patient' => ['name' => 'TEST PATIENT', 'born' => 1981, 'sex' => 'M'], 'sequences' => ['T1', 'T2'],
        'review' => ['age' => 45, 'reason' => 'derived'],
    ];

    public function testItFindsTheDamageAndRepairsFromTheLastIntactRevision(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $storage->create(self::PATH, self::INTACT, "v1\n", 'owner');
        // What the editor showed, with the title retyped by the user before autosave
        $shown = str_replace("title: 'RM coloană lombară'", 'title: RM coloană lombară L4', DocumentFormat::encode(self::INTACT, "v1\n"));
        $storage->save(self::PATH, self::oldAutosaveMeta($shown), "v2 body\n", 1, 'owner');
        // Reopened in the editor and autosaved again: quoted once more
        $reopened = DocumentFormat::encode($storage->read(self::PATH)->frontmatter, "v2 body\n");
        $storage->save(self::PATH, self::oldAutosaveMeta($reopened), "v3 body\n", 2, 'owner');
        self::assertNotSame([], FrontmatterRepair::damage($storage->read(self::PATH)->frontmatter), 'the reproduction is damaged');

        $command = $this->command($storage);
        $dry = $this->run2($command, []);
        self::assertStringContainsString('rev 3', $dry);
        self::assertStringContainsString('patient emptied', $dry);
        self::assertStringContainsString('last intact rev 1', $dry);
        self::assertStringNotContainsString('test-subject', $dry, 'pages are named by pid');
        self::assertSame(3, $storage->read(self::PATH)->rev, 'a check writes nothing');

        $out = $this->run2($command, ['--repair', '--actor=owner']);
        self::assertStringContainsString('repaired as rev 4', $out);

        $page = $storage->read(self::PATH);
        self::assertSame(['title' => 'RM coloană lombară L4'] + self::INTACT, $page->frontmatter);
        self::assertSame("v3 body\n", $page->body, 'the body as last saved');
        self::assertStringContainsString('"reason":"frontmatter-repair"', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'));
        self::assertStringContainsString('0 damaged page(s)', $this->run2($command, []));
    }

    public function testASignedPageIsListedButNotRepaired(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $storage->create(self::PATH, self::INTACT, "v1\n", 'owner');
        $storage->save(self::PATH, self::oldAutosaveMeta(DocumentFormat::encode(self::INTACT, '')), "v2\n", 1, 'owner');
        $storage->sign(self::PATH, 'owner', []);

        $out = $this->run2($this->command($storage), ['--repair', '--actor=owner']);

        self::assertStringContainsString('(signed)', $out);
        self::assertStringContainsString('0 repaired; 1 signed', $out);
    }

    /**
     * The bug this guards against: the first version counted any '' as
     * damage, so every imported page (the importer writes `summary: ''`)
     * was listed — 980 on live — and an imported page the autosave really
     * damaged had no "intact" revision to repair from.
     */
    public function testAnImportedEmptySummaryIsNotDamageAndDoesNotBlockARepair(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $imported = ['summary' => ''] + self::INTACT;
        $storage->create('reports:mri:mioveni:imported-a', $imported, "v1\n", 'owner');
        $storage->create(self::PATH, $imported, "v1\n", 'owner');
        $storage->save(self::PATH, self::oldAutosaveMeta(DocumentFormat::encode($imported, '')), "v2\n", 1, 'owner');
        $command = $this->command($storage);

        $out = $this->run2($command, ['--repair', '--actor=owner']);

        self::assertStringContainsString('1 damaged page(s); 1 repaired', $out, 'the untouched import is not listed');
        self::assertStringContainsString('last intact rev 1', $out);
        self::assertSame($imported, $storage->read(self::PATH)->frontmatter);
    }

    public function testRepairNeedsAnActor(): void
    {
        $command = $this->command(new FlatFile($this->dataRoot, new RecordingIndex()));

        self::assertSame(1, $command->run(['--repair'], new Output(fopen('php://memory', 'w+'), fopen('php://memory', 'w+'))));
    }

    /**
     * Exactly what assets/js/editor.js did before the fix: every
     * "key: value" line of the frontmatter, split at the first colon.
     *
     * @return array<string, string>
     */
    private static function oldAutosaveMeta(string $document): array
    {
        $end = strpos($document, "\n---\n");
        $meta = [];
        foreach (explode("\n", substr($document, 4, $end - 4)) as $line) {
            $idx = strpos($line, ':');
            if ($idx !== false && $idx > 0) {
                $meta[trim(substr($line, 0, $idx))] = trim(substr($line, $idx + 1));
            }
        }

        return $meta;
    }

    private function command(FlatFile $storage): PagesCheckFrontmatterCommand
    {
        $audit = new AuditLog($this->dataRoot . '/audit');

        return new PagesCheckFrontmatterCommand(new MaintenanceRunner([new FrontmatterCheckTask($storage, $audit)], $this->dataRoot, $audit));
    }

    /** @param list<string> $args */
    private function run2(PagesCheckFrontmatterCommand $command, array $args): string
    {
        $stdout = fopen('php://memory', 'w+');
        $command->run($args, new Output($stdout, fopen('php://memory', 'w+')));
        rewind($stdout);

        return (string) stream_get_contents($stdout);
    }
}
