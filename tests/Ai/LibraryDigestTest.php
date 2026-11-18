<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Ai;

use Reporion\Audit\AuditLog;
use Reporion\Auth\SystemActor;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Storage\FileTree;
use Reporion\Tests\Http\HttpTestCase;

/**
 * library:digest (2026-11-15) against the fake OpenAI-compatible server: each source that lacks
 * them gets its one-line summary and its key points, as system:librarian; what is there is not
 * made again; Check sends nothing. The articles are made up.
 */
final class LibraryDigestTest extends HttpTestCase
{
    private FakeServer $server;

    private FileTree $storage;

    private Sqlite $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new FakeServer();
        $this->config['ai'] = ['enabled' => true, 'fallback_profile' => 'wiki', 'servers' => [['endpoint' => $this->server->url, 'tiers' => ['normal' => ['model' => 'normal-model']]]], 'retry_delays' => [0, 0, 0]];
        $this->createOwner();
        $this->index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        $this->storage = new FileTree($this->dataRoot, $this->index);
        foreach (['source-points' => "Points of {title}:\n<a>{source}</a>\n", 'summary' => "Summarize:\n<a>{text}</a>\n"] as $id => $prompt) {
            $this->storage->create('ai:profiles:wiki:' . \Reporion\Service\Ai\Actions::place($id), ['title' => $id, 'format' => 'text', 'visibility' => 'private'], $prompt, 'owner');
        }
        $csl = ['type' => 'article-journal', 'title' => 'A made-up study', 'issued' => ['date-parts' => [[2024]]]];
        $this->storage->create('library:short2024', ['title' => 'A made-up study', 'visibility' => 'private', 'csl' => $csl], "Grade 3 was the most common in 128 patients.\n", 'owner');
        $this->storage->create('library:has2024', ['title' => 'Summarized already', 'visibility' => 'private', 'csl' => $csl, 'summary' => 'Written by a person.'], "Another made-up text about grades.\n", 'owner');
        $this->storage->create('library:record2024', ['title' => 'A record with no text', 'visibility' => 'private', 'csl' => $csl], '', 'owner');
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        parent::tearDown();
    }

    public function testCheckSendsNothingAndApplyMakesWhatIsMissingAsTheLibrarian(): void
    {
        $task = Kernel::libraryDigestTask($this->config, $this->storage, $this->index, new AuditLog($this->dataRoot . '/audit'));

        $check = $task->run(MaintenanceTask::CHECK, 'owner', $task->options([]))->summary();
        self::assertSame([1, 2, 1], [$check['would_summarize'], $check['would_keep_points'], $check['no_text']]);
        self::assertSame(0, $this->server->requests(), 'Check asks nothing');
        self::assertArrayNotHasKey('summary', $this->storage->read('library:short2024')->frontmatter);

        $done = $task->run(MaintenanceTask::APPLY, 'owner', $task->options([]))->summary();
        self::assertSame([1, 2, 0], [$done['summarized'], $done['points_kept'], $done['failed']]);
        self::assertSame(3, $this->server->requests());

        $short = $this->storage->read('library:short2024');
        self::assertNotSame('', trim((string) $short->frontmatter['summary']));
        self::assertLessThanOrEqual(160, mb_strlen((string) $short->frontmatter['summary']));
        self::assertSame("Grade 3 was the most common in 128 patients.\n", $short->body, 'never written into the source\'s text');
        self::assertSame('Written by a person.', $this->storage->read('library:has2024')->frontmatter['summary'], 'a summary there is kept');
        $points = $this->storage->read('library:short2024:source-points');
        self::assertSame(['source-points', $short->pid, 'private'], [$points->frontmatter['analysis']['prompt'], $points->frontmatter['analysis']['of'], $points->visibility]);
        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"actor":"' . SystemActor::LIBRARIAN . '"', $audit);
        self::assertStringContainsString('"on_behalf_of":"owner"', $audit);

        $again = $task->run(MaintenanceTask::APPLY, 'owner', $task->options([]))->summary();
        self::assertSame([0, 0, 2], [$again['summarized'], $again['points_kept'], $again['complete']], 'what is made is not made again');
        self::assertSame(3, $this->server->requests());
    }
}
