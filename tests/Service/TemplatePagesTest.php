<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service;

use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Auth\User;
use Reporion\Index\Sqlite;
use Reporion\Service\Checklists;
use Reporion\Service\References;
use Reporion\Service\TemplatePages;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * Service\TemplatePages: an exam template as Checklists and References read
 * it — under `templates:`, reachable by the caller (invariant 6), and read
 * once per caller however many exams and services ask.
 */
final class TemplatePagesTest extends StorageTestCase
{
    private const TEMPLATE = 'templates:mri:genunchi';

    private FlatFile $storage;
    private Sqlite $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $this->storage = new FlatFile($this->dataRoot, $this->index);
        $this->storage->create('kb:mri:genunchi', ['title' => 'Genunchi', 'visibility' => 'private'], "Ligamente.\n", 'owner');
        $this->storage->create(self::TEMPLATE, [
            'title' => 'IRM Genunchi', 'visibility' => 'private', 'checklist' => ['Menisc medial'], 'reference' => 'kb:mri:genunchi',
        ], "Genunchi.\n", 'owner');
    }

    public function testOnlyATemplateTheCallerCanReach(): void
    {
        $templates = new TemplatePages($this->storage, $this->index);
        $elsewhere = new User('v', '', false, [new Grant('reports:ct', GrantRole::Viewer)], true, '', '');

        self::assertSame('IRM Genunchi', $templates->read(self::TEMPLATE, $this->owner())?->frontmatter['title']);
        self::assertNull($templates->read(self::TEMPLATE, $elsewhere), 'remembered per caller: the owner\'s read is not theirs');
        self::assertNull($templates->read('kb:mri:genunchi', $this->owner()), 'not under templates:');
        self::assertNull($templates->read('templates:mri:none', $this->owner()));
        self::assertNull($templates->read('', $this->owner()));
    }

    public function testEveryExamAndBothServicesShareOneRead(): void
    {
        $templates = new TemplatePages($this->storage, $this->index);
        $checklists = new Checklists($this->storage, $this->index, $templates);
        $references = new References($this->storage, $this->index, null, $templates);
        $report = ['exams' => [['title' => 'Stâng', 'template' => self::TEMPLATE], ['title' => 'Drept', 'template' => self::TEMPLATE]]];

        self::assertCount(2, $checklists->forReport($report, $this->owner()));

        // Changed after the first read: the rest of this request keeps what it read
        $page = $this->storage->read(self::TEMPLATE);
        $this->storage->save(self::TEMPLATE, ['reference' => 'kb:mri:other'] + $page->frontmatter, $page->body, $page->rev, 'owner');
        $panel = $references->forReport($report, $this->owner());
        self::assertSame(['kb:mri:genunchi'], array_column($panel, 'path'));
        self::assertSame(['Stâng', 'Drept'], $panel[0]['exams']);

        self::assertSame([], array_column((new References($this->storage, $this->index))->forReport($report, $this->owner()), 'path'), 'a new request reads it as it is now: no such page');
    }

    private function owner(): User
    {
        return new User('owner', 'x', true, [], true, 'now', 'now');
    }
}
