<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Maintenance;

use Reporion\Audit\AuditLog;
use Reporion\Index\Sqlite;
use Reporion\Service\Ai\Actions;
use Reporion\Service\Ai\AiConfig;
use Reporion\Service\Ai\DefaultPrompts;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Service\Maintenance\PromptRelocateTask;
use Reporion\Storage\FileTree;
use Reporion\Tests\Storage\StorageTestCase;

/** Prompt folders (2026-11-18): a reserved prompt at its old `special:` place still serves, is never installed twice, and moves to its folder */
final class PromptRelocateTaskTest extends StorageTestCase
{
    private FileTree $storage;
    private Sqlite $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 3) . '/migrations');
        $this->storage = new FileTree($this->dataRoot, $this->index);
        $this->storage->create('ai:profiles:reports:special:workup', ['visibility' => 'private'], "OLDWORKUP {blocks}\n", 'owner');
        $this->storage->create('ai:profiles:reports:special:ask-archive', ['visibility' => 'private'], "OLDASK\n", 'owner');
        $this->storage->create('ai:profiles:reports:special:summary', ['visibility' => 'private'], "OLD summary\n", 'owner');
        $this->storage->create('ai:profiles:reports:buttons:summary', ['visibility' => 'private'], "NEW summary\n", 'owner');
        $this->storage->create('ai:profiles:reports:special:mystery', ['visibility' => 'private'], "Not a reserved id\n", 'owner');
    }

    private function actions(): Actions
    {
        return new Actions(new AiConfig(true, 'http://127.0.0.1:1/v1', 'm', 0.3, 0.8, 0, 30, 'reports', ['reports'], false, ''), $this->storage, $this->index);
    }

    public function testTheOldPlaceIsReadUntilTheNewOneHasAPage(): void
    {
        $actions = $this->actions();
        self::assertStringContainsString('OLDWORKUP', $actions->special('reports:x', 'workup')->prompt);
        self::assertStringContainsString('NEW summary', $actions->special('reports:x', 'summary')->prompt, 'the place wins');
        $status = (new DefaultPrompts($this->storage, \dirname(__DIR__, 3) . '/resources/prompts'))->install('reports', 'owner', ['workup', 'ask-archive'], true, DefaultPrompts::RADIOLOGY);
        self::assertSame([], $status['created'], 'never made twice');
    }

    public function testCheckListsAndApplyMovesWithHistory(): void
    {
        $task = new PromptRelocateTask($this->storage, $this->index, new AuditLog($this->dataRoot . '/audit'));
        $check = $task->run(MaintenanceTask::CHECK, 'owner', []);
        self::assertEquals(['pages' => 4, 'move' => 2, 'taken' => 1, 'unknown' => 1], array_intersect_key($check->summary(), ['pages' => 0, 'move' => 0, 'taken' => 0, 'unknown' => 0]));
        self::assertNotNull($this->index->findByPath('ai:profiles:reports:special:workup', null) ?? $this->storage->read('ai:profiles:reports:special:workup'), 'check moves nothing');

        $apply = $task->run(MaintenanceTask::APPLY, 'owner', []);
        self::assertSame(2, $apply->summary()['moved']);
        self::assertStringContainsString('OLDWORKUP', $this->storage->read('ai:profiles:reports:panels:workup')->body);
        self::assertStringContainsString('OLDASK', $this->storage->read('ai:profiles:reports:questions:archive')->body);
        self::assertStringContainsString('OLD summary', $this->storage->read('ai:profiles:reports:special:summary')->body, 'taken: left');
        self::assertStringContainsString('OLDWORKUP', $this->actions()->special('reports:x', 'workup')->prompt);
        self::assertStringContainsString('"action":"page.move"', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'));
        self::assertSame(0, $task->run(MaintenanceTask::APPLY, 'owner', [])->summary()['moved'] ?? 0, 'twice: nothing more');
    }
}
