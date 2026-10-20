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
        foreach (DefaultPrompts::TARGETS as $target) {
            $listed = [];
            foreach (DefaultPrompts::CATALOGUE as $entry) {
                if (\in_array($target, $entry['targets'], true)) {
                    $listed[] = $entry['file'];
                    self::assertNotSame('', trim((string) @file_get_contents($dir . '/' . $target . '/' . $entry['file'])), $target . '/' . $entry['file'] . ' is shipped and not empty');
                }
            }
            $onDisk = array_map('basename', glob($dir . '/' . $target . '/*') ?: []);
            self::assertEqualsCanonicalizing($listed, $onDisk, 'no ' . $target . ' file shipped but not offered');
        }
    }

    public function testTheRadiologyPromptsAreEveryReservedOneAndTheGenericOnesTheFewThatFitAnyPage(): void
    {
        $of = static fn (string $target): array => array_keys(array_filter(DefaultPrompts::CATALOGUE, static fn (array $e): bool => \in_array($target, $e['targets'], true)));

        self::assertEqualsCanonicalizing(array_diff(array_keys(DefaultPrompts::CATALOGUE), ['grammar']), $of('radiology'));
        self::assertEqualsCanonicalizing(['summary', 'tags', 'tags-review', 'commit', 'ask', 'grammar', 'table'], $of('generic'));
        self::assertNotContains('grammar', $of('radiology'), 'grammar is for ordinary pages');
        foreach (['shorten', 'patient', 'english'] as $gone) {
            self::assertArrayNotHasKey($gone, DefaultPrompts::CATALOGUE);
        }
    }

    public function testEachTargetMakesItsOwnTextAndRefusesAnUnknownOne(): void
    {
        $made = $this->prompts->install('wiki', 'owner');
        self::assertEqualsCanonicalizing(['summary', 'tags', 'tags-review', 'commit', 'ask', 'grammar', 'table'], $made['created']);
        self::assertStringContainsString('Summarize the text', $this->storage->read('ai:profiles:wiki:special:summary')->body);
        self::assertSame('missing', $this->prompts->status('wiki', DefaultPrompts::RADIOLOGY)['presign']);
        self::assertArrayNotHasKey('presign', $this->prompts->status('wiki', DefaultPrompts::GENERIC));

        $this->prompts->install('reports', 'owner', target: DefaultPrompts::RADIOLOGY);
        self::assertStringContainsString('Ești radiolog', $this->storage->read('ai:profiles:reports:special:summary')->body);

        $this->expectException(\InvalidArgumentException::class);
        $this->prompts->install('x', 'owner', target: 'nope');
    }

    public function testTheCoreGroupIsTheAskActionTheSummaryAndTheTags(): void
    {
        self::assertSame([], array_diff(DefaultPrompts::CORE, array_keys(DefaultPrompts::CATALOGUE)));
        $result = $this->prompts->install('reports', 'owner', DefaultPrompts::CORE, target: DefaultPrompts::RADIOLOGY);

        self::assertEqualsCanonicalizing(array_diff(DefaultPrompts::CORE, ['grammar']), $result['created'], 'grammar is generic only');
        self::assertSame('missing', $this->prompts->status('reports', DefaultPrompts::RADIOLOGY)['ask-archive']);
    }

    public function testInstallMakesEveryPageOfAProfileAndAskingTwiceMakesNone(): void
    {
        $first = $this->prompts->install('reports-short', 'owner', target: DefaultPrompts::RADIOLOGY);
        self::assertCount(\count(DefaultPrompts::CATALOGUE) - 1, $first['created']);
        self::assertSame([], $first['skipped']);
        self::assertSame('Summary', $this->storage->read('ai:profiles:reports-short:special:summary')->frontmatter['title']);
        self::assertSame('private', $this->storage->read('ai:profiles:reports-short:ask')->frontmatter['visibility']);
        self::assertSame(['present'], array_values(array_unique($this->prompts->status('reports-short', DefaultPrompts::RADIOLOGY))));

        $again = $this->prompts->install('reports-short', 'owner', target: DefaultPrompts::RADIOLOGY);
        self::assertSame([], $again['created']);
        self::assertCount(\count(DefaultPrompts::CATALOGUE) - 1, $again['skipped']);
    }

    public function testEveryPromptPageIsPlainTextAndTheLightOnesRunOnLite(): void
    {
        foreach (DefaultPrompts::TARGETS as $target) {
            $this->prompts->install($target, 'owner', target: $target);
            foreach (DefaultPrompts::CATALOGUE as $id => $entry) {
                if (!\in_array($target, $entry['targets'], true) || $entry['kind'] === DefaultPrompts::TABLE) {
                    continue;
                }
                $fm = $this->storage->read(DefaultPrompts::pathOf($target, $id))->frontmatter;
                self::assertSame('text', $fm['format'] ?? null, $target . ':' . $id . ' is shown as typed');
                self::assertSame($entry['model'] ?? null, $fm['model'] ?? null, $target . ':' . $id);
            }
            self::assertArrayNotHasKey('format', $this->storage->read(DefaultPrompts::pathOf($target, 'table'))->frontmatter, 'the table stays markdown');
        }
        self::assertSame('lite', $this->storage->read('ai:profiles:generic:special:summary')->frontmatter['model']);
        self::assertArrayNotHasKey('model', $this->storage->read('ai:profiles:radiology:special:evolution')->frontmatter);
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
