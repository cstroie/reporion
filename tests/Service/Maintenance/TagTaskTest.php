<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Maintenance;

use Reporion\Audit\AuditLog;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Service\Maintenance\TagTask;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Ai\FakeServer;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * pages:tag (2026-10-08): the reserved `tags` prompt over a namespace, the
 * answer through Support\TagList, one revision per report with no tags;
 * signed and already tagged reports left alone. Fixture data is fictitious
 * (invariant 10).
 */
final class TagTaskTest extends StorageTestCase
{
    private FakeServer $server;
    private FlatFile $storage;
    private Sqlite $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new FakeServer();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 3) . '/migrations');
        $this->storage = new FlatFile($this->dataRoot, $this->index);
        file_put_contents($this->dataRoot . '/tags.yaml', "fractura:\n  synonyms: [fracturi]\n");
        $fm = ['title' => 'TEST Unu', 'visibility' => 'private', 'study_date' => '2026-09-27', 'patient' => ['name' => 'TEST Unu']];
        $this->storage->create('reports:mri:mioveni:260927-test-unu', $fm, "# TEST Unu\n\n## IRM genunchi\n\nFractură.\n", 'owner');
        $this->storage->create('reports:mri:mioveni:260927-test-doi', ['title' => 'TEST Doi', 'patient' => ['name' => 'TEST Doi'], 'tags' => ['manual']] + $fm, "# TEST Doi\n\nText.\n", 'owner');
        $this->storage->create('reports:mri:mioveni:260927-test-trei', ['title' => 'TEST Trei', 'patient' => ['name' => 'TEST Trei']] + $fm, "# TEST Trei\n\nText.\n", 'owner');
        $this->storage->sign('reports:mri:mioveni:260927-test-trei', 'owner', []);
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        parent::tearDown();
    }

    private function task(bool $withPrompt = true): TagTask
    {
        if ($withPrompt) {
            $this->storage->create('ai:profiles:reports:tags', ['title' => 'Tags', 'visibility' => 'private'], "{text}\n", 'owner');
        }
        $config = [
            'paths' => ['data' => $this->dataRoot, 'index' => $this->dataRoot . '/index.sqlite'],
            'ai' => ['enabled' => true, 'endpoint' => $this->server->url, 'model' => 'tags', 'profiles' => ['reports' => 'reports']],
        ];

        return Kernel::tagTask($config, \dirname(__DIR__, 3), $this->storage, $this->index, new AuditLog($this->dataRoot . '/audit'));
    }

    public function testACheckSendsNothingAndApplyTagsTheUntaggedUnsignedReports(): void
    {
        $task = $this->task();
        $check = $task->run(MaintenanceTask::CHECK, 'owner', $task->options(['namespace' => 'reports']));
        self::assertSame(1, $check->summary()['would_tag']);
        self::assertSame(1, $check->summary()['has_tags']);
        self::assertSame(1, $check->summary()['signed']);
        self::assertNull($this->server->lastRequest()['body'], 'a check sends nothing');

        $apply = $task->run(MaintenanceTask::APPLY, 'owner', $task->options(['namespace' => 'reports']));
        self::assertSame(1, $apply->summary()['tagged']);
        self::assertSame(['irm', 'genunchi', 'fractura', 'menisc'], $this->storage->read('reports:mri:mioveni:260927-test-unu')->frontmatter['tags']);
        self::assertSame(['manual'], $this->storage->read('reports:mri:mioveni:260927-test-doi')->frontmatter['tags'], 'tags already there stay');
        self::assertSame(30, $this->server->lastRequest()['body']['max_tokens']);
    }

    public function testOverwriteRetagsAndNoPromptAsksNothing(): void
    {
        $none = $this->task(false);
        $report = $none->run(MaintenanceTask::APPLY, 'owner', $none->options([]));
        self::assertSame(1, $report->summary()['no_prompt'], 'the untagged one');
        self::assertNull($this->server->lastRequest()['body']);

        $task = $this->task();
        $task->run(MaintenanceTask::APPLY, 'owner', $task->options(['overwrite' => '1']));
        self::assertSame(['irm', 'genunchi', 'fractura', 'menisc'], $this->storage->read('reports:mri:mioveni:260927-test-doi')->frontmatter['tags']);
    }
}
