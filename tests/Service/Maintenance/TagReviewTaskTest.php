<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Maintenance;

use Reporion\Audit\AuditLog;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Service\Maintenance\TagReviewTask;
use Reporion\Service\TagDictionary;
use Reporion\Storage\FileTree;
use Reporion\Tests\Ai\FakeServer;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * tags:review (2026-10-15): the rule's and the assistant's proposals in the
 * run's report, nothing written; the assistant sent the tag list only,
 * never a report, and no tag holding a word of a patient's name. Fixture
 * data is fictitious (invariant 10).
 */
final class TagReviewTaskTest extends StorageTestCase
{
    private FakeServer $server;
    private FileTree $storage;
    private Sqlite $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new FakeServer();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 3) . '/migrations');
        $this->storage = new FileTree($this->dataRoot, $this->index);
        file_put_contents($this->dataRoot . '/tags.yaml', "leziune meniscală:\n  group: diagnosis\n");
        $fm = ['visibility' => 'private', 'study_date' => '2026-09-27', 'patient' => ['name' => 'TEST Popescul']];
        $this->storage->create('reports:mri:mioveni:260927-test-popescul', ['tags' => ['leziune meniscală', 'leziune corn anterior menisc', 'de urmărit', 'chist popescul', 'rads:birads-2']] + $fm, "# TEST Popescul\n\n## IRM genunchi\n\nSecret body words.\n", 'owner');
        $this->storage->create('reports:mri:mioveni:260927-test-doi', ['tags' => ['leziuni', 'leziune', 'leziune']] + $fm, "# TEST Doi\n\nText.\n", 'owner');
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        parent::tearDown();
    }

    private function task(bool $withPrompt = true): TagReviewTask
    {
        if ($withPrompt) {
            $this->storage->create('ai:profiles:reports:special:tags-review', ['title' => 'Tags review', 'visibility' => 'private'], "Tags:\n{tags}\n", 'owner');
        }
        $config = [
            'paths' => ['data' => $this->dataRoot, 'index' => $this->dataRoot . '/index.sqlite'],
            'ai' => ['enabled' => true, 'servers' => [['endpoint' => $this->server->url, 'tiers' => ['normal' => ['model' => 'tags-review']]]]],
        ];

        return Kernel::tagReviewTask($config, \dirname(__DIR__, 3), $this->storage, $this->index, new AuditLog($this->dataRoot . '/audit'));
    }

    public function testTheRulesAndTheAssistantsProposalsAreReportedAndNothingIsWritten(): void
    {
        $task = $this->task();
        $report = $task->run(MaintenanceTask::CHECK, 'owner', $task->options([]));

        $proposals = array_map(static fn (array $item): array => array_intersect_key($item['data'], array_flip(['op', 'from', 'to', 'source'])), $report->items());
        self::assertSame([
            ['op' => 'merge', 'from' => ['leziuni'], 'to' => 'leziune', 'source' => 'rule'],
            ['op' => 'generalise', 'from' => ['leziune corn anterior menisc'], 'to' => 'leziune meniscală', 'source' => 'ai'],
            ['op' => 'drop', 'from' => ['de urmărit'], 'to' => '', 'source' => 'ai'],
        ], $proposals);
        self::assertSame(1, $report->summary()['proposals_rule']);
        self::assertSame(2, $report->summary()['proposals_ai']);
        self::assertSame(1, $report->summary()['withheld'], 'the tag with the patient\'s name');
        self::assertSame(1, $this->storage->read('reports:mri:mioveni:260927-test-doi')->rev, 'a proposal writes nothing');

        $sent = json_encode($this->server->lastRequest()['body'], JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('leziune corn anterior menisc (1)', $sent);
        self::assertStringNotContainsStringIgnoringCase('popescul', $sent, 'no name, not even inside a tag');
        self::assertStringNotContainsString('Secret body words', $sent, 'no report text');
        self::assertStringNotContainsString('rads:', $sent);
    }

    public function testWithoutThePromptOrTheAssistantTheRuleStillProposes(): void
    {
        $task = $this->task(withPrompt: false);
        $report = $task->run(MaintenanceTask::CHECK, 'owner', $task->options([]));
        self::assertSame(1, $report->summary()['proposals_rule']);
        self::assertSame(0, $report->summary()['proposals_ai']);
        self::assertStringContainsString('No tags-review prompt', implode(' ', $report->notes()));

        $offline = $this->task();
        $report = $offline->run(MaintenanceTask::CHECK, 'owner', $offline->options(['ai' => '0']));
        self::assertSame(0, $report->summary()['proposals_ai']);
        self::assertNull($this->server->lastRequest()['body'], 'asked nothing');
    }

    public function testAProposalTurnedDownIsNotMadeAgain(): void
    {
        (new TagDictionary($this->dataRoot, ''))->reject('leziuni → leziune');
        (new TagDictionary($this->dataRoot, ''))->reject('de urmărit →');
        $task = $this->task();
        $report = $task->run(MaintenanceTask::CHECK, 'owner', $task->options([]));

        self::assertSame(['leziune corn anterior menisc → leziune meniscală'], array_map(static fn (array $i): string => $i['detail'], $report->items()));
        self::assertStringContainsString("_rejected:\n", (string) file_get_contents($this->dataRoot . '/tags.yaml'));
        self::assertArrayNotHasKey('_rejected', (new TagDictionary($this->dataRoot, ''))->all(), 'not a tag');
    }
}
