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
}
