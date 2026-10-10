<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Ai;

use Reporion\Index\Sqlite;
use Reporion\Service\Ai\Action;
use Reporion\Service\Ai\Actions;
use Reporion\Service\Ai\AiConfig;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * Service\Ai\Actions::forPage() (2026-09-28): the profile's own page's first
 * table decides which actions exist and their order — Support\ProfileTable
 * does the parsing, this is what a row then does once its id has (or
 * hasn't) a prompt page of its own.
 */
final class ActionsTest extends StorageTestCase
{
    private const PATH = 'reports:mri:mioveni:260927-test-unu';

    private FlatFile $storage;
    private Sqlite $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 3) . '/migrations');
        $this->storage = new FlatFile($this->dataRoot, $this->index);
    }

    private function config(): AiConfig
    {
        return new AiConfig(true, 'http://127.0.0.1:1/v1', 'm', 0.3, 0.8, 0, 30, 'reports', ['reports'], false, '');
    }

    public function testTheTableOrderIsTheActionOrderRegardlessOfPageCreationOrder(): void
    {
        $this->storage->create('ai:profiles:reports:expand', ['visibility' => 'private'], "Expand it.\n", 'owner');
        $this->storage->create('ai:profiles:reports:summarize', ['visibility' => 'private'], "Summarize it.\n", 'owner');
        $this->storage->create(
            'ai:profiles:reports',
            ['title' => 'Reports profile', 'visibility' => 'private'],
            "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n"
            . "| summarize | Summarize | Create a summary | summary.png | show |\n"
            . "| expand | Expand | Expand the text | expand.png | replace |\n",
            'owner'
        );

        $ids = array_map(static fn (Action $a): string => $a->id, (new Actions($this->config(), $this->storage, $this->index))->forPage(self::PATH));

        self::assertSame(['summarize', 'expand'], $ids, 'table order, not creation order');
    }

    public function testARowWithNoPromptPageContributesNothing(): void
    {
        $this->storage->create('ai:profiles:reports:expand', ['visibility' => 'private'], "Expand it.\n", 'owner');
        $this->storage->create(
            'ai:profiles:reports',
            ['title' => 'Reports profile', 'visibility' => 'private'],
            "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n"
            . "| summarize | Summarize | Create a summary | summary.png | show |\n"
            . "| expand | Expand | Expand the text | expand.png | replace |\n",
            'owner'
        );

        $ids = array_map(static fn (Action $a): string => $a->id, (new Actions($this->config(), $this->storage, $this->index))->forPage(self::PATH));

        self::assertSame(['expand'], $ids, '"summarize" has no page of its own — dropped, not an error');
    }

    public function testAnInvalidResultDefaultsToShow(): void
    {
        $this->storage->create('ai:profiles:reports:custom', ['visibility' => 'private'], "{prompt}\n", 'owner');
        $this->storage->create(
            'ai:profiles:reports',
            ['title' => 'Reports profile', 'visibility' => 'private'],
            "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n| custom | Custom | Custom prompt | ✏️ | not-a-mode |\n",
            'owner'
        );

        $list = (new Actions($this->config(), $this->storage, $this->index))->forPage(self::PATH);

        self::assertSame('show', $list[0]->result);
    }

    public function testTheSystemPromptAndItsPerActionAppendageStillConcatenate(): void
    {
        $this->storage->create('ai:profiles:reports:system', ['visibility' => 'private'], "Ești radiolog.\n", 'owner');
        $this->storage->create('ai:profiles:reports:system:quality', ['visibility' => 'private'], "Ești auditor.\n", 'owner');
        $this->storage->create('ai:profiles:reports:quality', ['visibility' => 'private'], "Verifică.\n", 'owner');
        $this->storage->create(
            'ai:profiles:reports',
            ['title' => 'Reports profile', 'visibility' => 'private'],
            "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n| quality | Check | Review | ✔️ | show |\n",
            'owner'
        );

        $list = (new Actions($this->config(), $this->storage, $this->index))->forPage(self::PATH);

        self::assertStringStartsWith('Ești radiolog.', $list[0]->system);
        self::assertStringEndsWith('Ești auditor.', $list[0]->system);
    }

    public function testNoIndexPageIsNoActions(): void
    {
        $this->storage->create('ai:profiles:reports:quality', ['visibility' => 'private'], "Verifică.\n", 'owner');

        self::assertSame([], (new Actions($this->config(), $this->storage, $this->index))->forPage(self::PATH), 'no ai:profiles:reports page at all — no table to read');
    }

    public function testPagesOutsideTheNamespacesUseTheFallbackProfileWhenThereIsOne(): void
    {
        $this->storage->create('ai:profiles:default:rewrite', ['visibility' => 'private'], "Rewrite it.\n", 'owner');
        $this->storage->create('ai:profiles:default', ['visibility' => 'private'], "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n| rewrite | Rewrite | | | replace |\n", 'owner');
        $this->storage->create('ai:profiles:reports:conclusion', ['visibility' => 'private'], "Conclude.\n", 'owner');
        $this->storage->create('ai:profiles:reports', ['visibility' => 'private'], "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n| conclusion | Conclusion | | | append |\n", 'owner');
        $ids = static fn (array $actions): array => array_map(static fn (Action $a): string => $a->id, $actions);

        self::assertSame([], (new Actions($this->config(), $this->storage, $this->index))->forPage('docs:howto'), 'no fallback: no assistant there');

        $config = new AiConfig(true, 'http://127.0.0.1:1/v1', 'm', 0.3, 0.8, 0, 30, 'reports', ['reports'], false, '', fallbackProfile: 'default');
        $actions = new Actions($config, $this->storage, $this->index);
        self::assertSame(['rewrite'], $ids($actions->forPage('docs:howto')));
        self::assertSame(['conclusion'], $ids($actions->forPage(self::PATH)), 'the reports profile where it serves');
    }

    public function testThePromptPagesModelAppliesWhenTheTableCellIsBlankAndToAReservedPrompt(): void
    {
        $this->storage->create('ai:profiles:reports:expand', ['visibility' => 'private', 'model' => 'lite'], "Expand it.\n", 'owner');
        $this->storage->create('ai:profiles:reports:grammar', ['visibility' => 'private', 'model' => 'lite'], "Fix it.\n", 'owner');
        $this->storage->create('ai:profiles:reports:summary', ['visibility' => 'private', 'model' => '2:lite'], "Summarize.\n", 'owner');
        $this->storage->create(
            'ai:profiles:reports',
            ['visibility' => 'private'],
            "| ID | Label | Tooltip | Icon | Result | Model |\n|---|---|---|---|---|---|\n| expand | Expand | | | replace | |\n| grammar | Grammar | | | replace | expert |\n",
            'owner'
        );
        $actions = new Actions($this->config(), $this->storage, $this->index);
        $models = [];
        foreach ($actions->forPage(self::PATH) as $action) {
            $models[$action->id] = $action->model;
        }

        self::assertSame(['expand' => 'lite', 'grammar' => 'expert'], $models, 'the cell wins over the page');
        $summary = $actions->special(self::PATH, 'summary');
        self::assertNotNull($summary);
        self::assertSame(['lite', '2'], [$summary->model, $summary->server], 'a reserved prompt kept out of the rail');
    }

    public function testCommitRunsLiteWithAShortTimeoutUnlessItsPageSaysOtherwise(): void
    {
        $this->storage->create('ai:profiles:reports:commit', ['visibility' => 'private'], "{diff}\n", 'owner');
        $this->storage->create('ai:profiles:reports:summary', ['visibility' => 'private', 'timeout' => 30], "Summarize.\n", 'owner');
        $actions = new Actions($this->config(), $this->storage, $this->index);

        $commit = $actions->special(self::PATH, 'commit');
        self::assertNotNull($commit);
        self::assertSame(['lite', 10], [$commit->model, $commit->timeout], 'a Save waits for it');
        self::assertSame(['normal', 30], [$actions->special(self::PATH, 'summary')?->model, $actions->special(self::PATH, 'summary')?->timeout]);

        $page = $this->storage->read('ai:profiles:reports:commit');
        $this->storage->save($page->path, ['visibility' => 'private', 'model' => 'normal', 'timeout' => 4], "{diff}\n", $page->rev, 'owner');
        $own = (new Actions($this->config(), $this->storage, $this->index))->special(self::PATH, 'commit');
        self::assertSame(['normal', 4], [$own?->model, $own?->timeout], 'the page\'s own');
    }

    public function testADashRowStartsASectionNeverAtTheTopTwiceOrAtTheEnd(): void
    {
        foreach (['create', 'conclusion', 'quality', 'linter'] as $id) {
            $this->storage->create('ai:profiles:reports:' . $id, ['visibility' => 'private'], "Do it.\n", 'owner');
        }
        $this->storage->create(
            'ai:profiles:reports',
            ['visibility' => 'private'],
            "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n"
            . "| --- | | | | |\n| create | Create | | | insert |\n| conclusion | Conclusion | | | append |\n"
            . "| --- | | | | |\n| ---- |\n| nopage | No page | | | show |\n| quality | Quality | | | show |\n| linter | Linter | | | show |\n| --- | | | | |\n",
            'owner'
        );

        $sections = [];
        foreach ((new Actions($this->config(), $this->storage, $this->index))->forPage(self::PATH) as $action) {
            $sections[$action->id] = $action->forEditor()['section'];
        }

        self::assertSame(['create' => false, 'conclusion' => false, 'quality' => true, 'linter' => false], $sections);
    }

    public function testEachPromptPageIsReadOncePerObjectAcrossRepeatedLookups(): void
    {
        $this->storage->create('ai:profiles:reports:summary', ['visibility' => 'private'], "Summarize.\n", 'owner');
        $this->storage->create('ai:profiles:reports:conclusion', ['visibility' => 'private'], "Conclude.\n", 'owner');
        $this->storage->create('ai:profiles:reports', ['visibility' => 'private'], "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n| conclusion | Conclusion | | | append |\n", 'owner');
        $actions = new Actions($this->config(), $this->storage, $this->index);

        self::assertSame('Summarize.', $actions->special(self::PATH, 'summary')?->prompt);
        self::assertNull($actions->special(self::PATH, 'tags'), 'no page, no feature');

        // Edited under a live object: it keeps what it read (one request, one CLI run)…
        $page = $this->storage->read('ai:profiles:reports:summary');
        $this->storage->save($page->path, $page->frontmatter, "Summarize differently.\n", $page->rev, 'owner');
        $this->storage->create('ai:profiles:reports:tags', ['visibility' => 'private'], "Tag it.\n", 'owner');
        $again = $actions->special(self::PATH, 'summary');
        self::assertNotNull($again);
        self::assertSame('Summarize.', $again->prompt);
        self::assertNull($actions->special(self::PATH, 'tags'));
        self::assertSame(['conclusion'], array_map(static fn (Action $a): string => $a->id, $actions->forPage(self::PATH)));

        // …and the next one reads the pages as they are now
        $fresh = new Actions($this->config(), $this->storage, $this->index);
        self::assertSame('Summarize differently.', $fresh->special(self::PATH, 'summary')?->prompt);
        self::assertSame('Tag it.', $fresh->special(self::PATH, 'tags')?->prompt);
    }
}
