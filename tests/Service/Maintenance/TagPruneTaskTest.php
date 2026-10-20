<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Maintenance;

use Reporion\Audit\AuditLog;
use Reporion\Auth\SystemActor;
use Reporion\Index\Sqlite;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Service\Maintenance\TagPruneTask;
use Reporion\Service\TagDictionary;
use Reporion\Service\Tags;
use Reporion\Storage\FileTree;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * tags:prune: a tag carried by at most max_uses reports comes off, as one
 * ordinary revision per page by system:admin; signed reports, RADS
 * categories and the dictionary's tags stay. Fixture data is fictitious
 * (invariant 10).
 */
final class TagPruneTaskTest extends StorageTestCase
{
    private FileTree $storage;
    private Sqlite $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 3) . '/migrations');
        $this->storage = new FileTree($this->dataRoot, $this->index);
        file_put_contents($this->dataRoot . '/tags.yaml', "curated:\n  synonyms: []\n");
        $this->page('uno', ['once', 'twice', 'thrice', 'curated']);
        $this->page('doi', ['twice', 'thrice', 'rads:birads-4a']);
        $this->page('trei', ['thrice']);
        $this->page('signed', ['only-signed']);
        $this->storage->sign('reports:mri:mioveni:260927-test-signed', 'owner', []);
    }

    /** @param list<string> $tags */
    private function page(string $slug, array $tags): void
    {
        $this->storage->create('reports:mri:mioveni:260927-test-' . $slug, [
            'title' => 'TEST ' . $slug, 'visibility' => 'private', 'study_date' => '2026-09-27', 'patient' => ['name' => 'TEST ' . $slug], 'tags' => $tags,
        ], "# TEST {$slug}\n\nText.\n", 'owner');
    }

    private function task(): TagPruneTask
    {
        $audit = new AuditLog($this->dataRoot . '/audit');

        return new TagPruneTask($this->index, new Tags($this->storage, $this->index, $audit), new TagDictionary($this->dataRoot, \dirname(__DIR__, 3) . '/resources/synonyms.txt'));
    }

    /** @return list<string> */
    private function tags(string $slug): array
    {
        return $this->storage->read('reports:mri:mioveni:260927-test-' . $slug)->frontmatter['tags'] ?? [];
    }

    public function testTheThresholdIsClampedToOneToFive(): void
    {
        $task = $this->task();
        self::assertSame(1, $task->options([])['max_uses']);
        self::assertSame(1, $task->options(['max_uses' => '0'])['max_uses']);
        self::assertSame(5, $task->options(['max_uses' => '9'])['max_uses']);
        self::assertSame(3, $task->options(['max_uses' => '3'])['max_uses']);
    }

    public function testACheckListsWhatWouldGoAndWritesNothing(): void
    {
        $task = $this->task();
        $check = $task->run(MaintenanceTask::CHECK, 'owner', $task->options([]));

        self::assertSame(2, $check->summary()['tags_to_remove'], 'once and only-signed: not curated, not RADS');
        self::assertSame(['once', 'twice', 'thrice', 'curated'], $this->tags('uno'));
        self::assertSame(1, $this->storage->read('reports:mri:mioveni:260927-test-uno')->rev);
    }

    public function testApplyRemovesTheRareTagsAsTheAdminPseudouser(): void
    {
        $task = $this->task();
        $run = $task->run(MaintenanceTask::APPLY, 'owner', $task->options([]));

        self::assertSame(['twice', 'thrice', 'curated'], $this->tags('uno'));
        self::assertSame(['twice', 'thrice', 'rads:birads-4a'], $this->tags('doi'), 'RADS stays');
        self::assertSame(['only-signed'], $this->tags('signed'), 'a signed report keeps its tag (D3)');
        self::assertSame(1, $run->summary()['pages_changed']);
        self::assertSame(1, $run->summary()['signed_pages_kept']);
        $revlog = $this->storage->read('reports:mri:mioveni:260927-test-uno')->revlog;
        self::assertSame(SystemActor::ADMIN, end($revlog)['by']);

        $lines = [];
        foreach (glob($this->dataRoot . '/audit/*') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $lines[] = json_decode($line, true);
            }
        }
        $saves = array_values(array_filter($lines, static fn (array $l): bool => $l['action'] === 'page.save'));
        self::assertSame(SystemActor::ADMIN, $saves[0]['actor']);
        self::assertSame('owner', $saves[0]['on_behalf_of']);
    }

    public function testAHigherThresholdTakesMore(): void
    {
        $task = $this->task();
        $task->run(MaintenanceTask::APPLY, 'owner', $task->options(['max_uses' => 2]));

        self::assertSame(['thrice', 'curated'], $this->tags('uno'));
        self::assertSame(['thrice', 'rads:birads-4a'], $this->tags('doi'));
        self::assertSame(['thrice'], $this->tags('trei'), 'three uses: stays at 2');

        // 'once' and 'twice' both went from the first page: one revision, not one per tag
        $page = $this->storage->read('reports:mri:mioveni:260927-test-uno');
        self::assertSame(2, $page->rev);
        $revlog = $page->revlog;
        self::assertSame('tag − once, twice', end($revlog)['note']);
    }
}
