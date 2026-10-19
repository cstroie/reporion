<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Ai;

use Reporion\Index\Sqlite;
use Reporion\Service\Ai\Actions;
use Reporion\Service\Ai\DefaultPrompts;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * Service\Ai\DefaultPrompts (2026-10-19): the shipped prompts made pages of
 * any profile, any time, never over a page that is there.
 */
final class DefaultPromptsTest extends StorageTestCase
{
    private FlatFile $storage;
    private DefaultPrompts $prompts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new FlatFile($this->dataRoot, new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 3) . '/migrations'));
        $this->prompts = new DefaultPrompts($this->storage, \dirname(__DIR__, 3) . '/resources/prompts');
    }

    public function testEveryReservedPromptHasAShippedExampleAndEveryShippedFileIsListed(): void
    {
        $special = array_keys(array_filter(DefaultPrompts::CATALOGUE, static fn (array $e): bool => $e['kind'] === DefaultPrompts::SPECIAL));
        self::assertEqualsCanonicalizing(Actions::SPECIAL, $special, 'a feature without a shipped prompt stays off for ever');

        $dir = \dirname(__DIR__, 3) . '/resources/prompts';
        $listed = array_column(DefaultPrompts::CATALOGUE, 'file');
        foreach ($listed as $file) {
            self::assertNotSame('', trim((string) @file_get_contents($dir . '/' . $file)), $file . ' is shipped and not empty');
        }
        $onDisk = array_map(static fn (string $f): string => substr($f, \strlen($dir) + 1), array_merge(glob($dir . '/*.txt') ?: [], glob($dir . '/actions/*') ?: []));
        self::assertEqualsCanonicalizing($listed, $onDisk, 'no file shipped but not offered');
    }

    public function testTheCoreGroupIsTheRailTheSummaryAndTheTags(): void
    {
        self::assertSame([], array_diff(DefaultPrompts::CORE, array_keys(DefaultPrompts::CATALOGUE)));
        $result = $this->prompts->install('reports', 'owner', DefaultPrompts::CORE);

        self::assertEqualsCanonicalizing(DefaultPrompts::CORE, $result['created']);
        self::assertSame('missing', $this->prompts->status('reports')['ask-archive']);
    }

    public function testInstallMakesEveryPageOfAProfileAndAskingTwiceMakesNone(): void
    {
        $first = $this->prompts->install('reports-short', 'owner');
        self::assertCount(\count(DefaultPrompts::CATALOGUE), $first['created']);
        self::assertSame([], $first['skipped']);
        self::assertSame('Summary', $this->storage->read('ai:profiles:reports-short:special:summary')->frontmatter['title']);
        self::assertSame('private', $this->storage->read('ai:profiles:reports-short:grammar')->frontmatter['visibility']);
        self::assertSame(['present'], array_values(array_unique($this->prompts->status('reports-short'))));

        $again = $this->prompts->install('reports-short', 'owner');
        self::assertSame([], $again['created']);
        self::assertCount(\count(DefaultPrompts::CATALOGUE), $again['skipped']);
    }

    public function testAnEditedPageAndAnExistingTableAreNeverTouched(): void
    {
        $this->storage->create('ai:profiles:reports:special:summary', ['visibility' => 'private'], "My summary prompt.\n", 'owner');
        $this->storage->create('ai:profiles:reports', ['visibility' => 'private'], "| ID | Label |\n|---|---|\n| mine | Mine |\n", 'owner');

        $result = $this->prompts->install('reports', 'owner');

        self::assertNotContains('summary', $result['created']);
        self::assertNotContains('table', $result['created']);
        self::assertSame("My summary prompt.\n", $this->storage->read('ai:profiles:reports:special:summary')->body);
        self::assertStringContainsString('| mine |', $this->storage->read('ai:profiles:reports')->body);
    }

    public function testOnlyAndDryRun(): void
    {
        $dry = $this->prompts->install('reports', 'owner', ['tags', 'commit'], true);
        self::assertSame(['tags', 'commit'], $dry['created']);
        self::assertSame('missing', $this->prompts->status('reports')['tags'], 'a dry run writes nothing');

        $this->prompts->install('reports', 'owner', ['tags']);
        $status = $this->prompts->status('reports');
        self::assertSame('present', $status['tags']);
        self::assertSame('missing', $status['commit']);
    }

    public function testAnEmptyPageIsReportedAndLeftAlone(): void
    {
        $this->storage->create('ai:profiles:reports:special:tags', ['visibility' => 'private'], '', 'owner');

        self::assertSame('empty', $this->prompts->status('reports')['tags']);
        self::assertNotContains('tags', $this->prompts->install('reports', 'owner')['created']);
    }
}
