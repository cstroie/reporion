<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use Reporion\Audit\AuditLog;
use Reporion\Cli\Output;
use Reporion\Cli\TemplatesImportCommand;
use Reporion\Index\Sqlite;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Storage\StorageTestCase;

final class TemplatesImportCommandTest extends StorageTestCase
{
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = $this->dataRoot . '/source';
        mkdir($this->source . '/mri', 0775, true);
        mkdir($this->source . '/other', 0775, true);
        file_put_contents($this->source . '/mri/cerebral.txt', "====== Cap: Cerebral ======\n\n===== IRM Cerebral =====\n\nSistem ventricular normal.\n");
        file_put_contents($this->source . '/mri/sidebar.txt', "====== Templates ======\n  * [[cerebral]]\n");
        file_put_contents($this->source . '/other/x.txt', "====== X ======\n");
    }

    public function testDryRunThenImportThenRunAgain(): void
    {
        [$storage, $command] = $this->wiring();

        $dry = $this->run2($command, ['--from', $this->source, '--dry-run']);
        self::assertStringContainsString('would create templates:mri:cerebral — IRM Cerebral [neuro]', $dry);
        self::assertStringContainsString('skipped mri/sidebar.txt', $dry);
        self::assertStringContainsString("skipped other/ — no modality maps to namespace 'other'", $dry);
        self::assertSame([], iterator_to_array($storage->allPaths(), false), 'a dry run writes nothing');

        self::assertStringContainsString('1 template(s) created', $this->run2($command, ['--from=' . $this->source, '--actor=owner']));
        $page = $storage->read('templates:mri:cerebral');
        self::assertSame(['title' => 'IRM Cerebral', 'visibility' => 'private', 'modality' => ['MR'], 'region' => ['neuro'], 'template_label' => 'Cap: Cerebral', 'tags' => ['templates']], array_diff_key($page->frontmatter, ['imported_from' => 1]));
        self::assertStringStartsWith('templates/mri/cerebral.txt sha256:', $page->frontmatter['imported_from']);
        self::assertSame("Sistem ventricular normal.\n", $page->body);
        self::assertSame('draft', $page->status);
        self::assertStringContainsString('"reason":"template-import"', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'));

        self::assertStringContainsString('0 template(s) created, 1 already there', $this->run2($command, ['--from', $this->source]), 'never overwritten');
        self::assertSame(1, $storage->read('templates:mri:cerebral')->rev);
    }

    /** @return array{FlatFile, TemplatesImportCommand} */
    private function wiring(): array
    {
        $storage = new FlatFile($this->dataRoot, new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations'));

        return [$storage, new TemplatesImportCommand($storage, new AuditLog($this->dataRoot . '/audit'), ['MR' => 'mri', 'CT' => 'ct'], ['cap' => ['neuro']])];
    }

    /** @param list<string> $args */
    private function run2(TemplatesImportCommand $command, array $args): string
    {
        $stdout = fopen('php://memory', 'w+');
        $command->run($args, new Output($stdout, fopen('php://memory', 'w+')));
        rewind($stdout);

        return (string) stream_get_contents($stdout);
    }
}
