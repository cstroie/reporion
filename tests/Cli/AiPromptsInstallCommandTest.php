<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use Reporion\Audit\AuditLog;
use Reporion\Cli\AiPromptsInstallCommand;
use Reporion\Cli\Output;
use Reporion\Index\Sqlite;
use Reporion\Service\Ai\DefaultPrompts;
use Reporion\Storage\FileTree;
use Reporion\Tests\Storage\StorageTestCase;

final class AiPromptsInstallCommandTest extends StorageTestCase
{
    private FileTree $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new FileTree($this->dataRoot, new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations'));
    }

    /** @param list<string> $args @return array{int, string, string} */
    private function exec(array $args): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $command = new AiPromptsInstallCommand(
            new DefaultPrompts($this->storage, \dirname(__DIR__, 2) . '/resources/prompts'),
            new AuditLog($this->dataRoot . '/audit'),
        );
        $code = $command->run($args, new Output($out, $err));
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testItSaysWhatItDoesFirstAndMakesTheOnlyIdsAsked(): void
    {
        [$code, $out] = $this->exec(['--profile=reports', '--only=tags,commit']);

        self::assertSame(0, $code);
        self::assertStringStartsWith('ai:prompts-install reports generic (writes)', $out);
        self::assertStringContainsString('2 made, 0 already there', $out);
        self::assertNotSame('', $this->storage->read('ai:profiles:reports:special:commit')->body);
        self::assertSame('missing', (new DefaultPrompts($this->storage, '/nonexistent'))->status('reports')['summary']);
        self::assertStringContainsString('"action":"settings.change"', (string) file_get_contents(glob($this->dataRoot . '/audit/*')[0]));
    }

    public function testDryRunAndListWriteNothing(): void
    {
        [, $dry] = $this->exec(['--profile=reports', '--dry-run']);
        self::assertStringContainsString('would make ai:profiles:reports:special:summary', $dry);
        [, $list] = $this->exec(['--profile=reports', '--list']);
        self::assertStringContainsString('summary', $list);
        self::assertStringContainsString('missing', $list);
        self::assertSame([], glob($this->dataRoot . '/audit/*') ?: [], 'nothing written, nothing audited');
    }

    public function testASecondRunLeavesEverythingAndJsonIsADocument(): void
    {
        $this->exec(['--profile=other']);
        [$code, $out] = $this->exec(['--profile=other', '--json']);

        self::assertSame(0, $code);
        $doc = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], $doc['created']);
        self::assertCount(\count(array_filter(DefaultPrompts::CATALOGUE, static fn (array $e): bool => \in_array('generic', $e['targets'], true))), $doc['skipped']);
    }

    public function testABadProfileOrUnknownIdIsRefused(): void
    {
        self::assertSame(1, $this->exec(['--profile=Bad Name'])[0]);
        [$bad, , $badErr] = $this->exec(['--profile=reports', '--target=nope']);
        self::assertSame(1, $bad);
        self::assertStringContainsString('Unknown target', $badErr);
        [$code, , $err] = $this->exec(['--profile=reports', '--only=nope']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Unknown id(s): nope', $err);
    }
}
