<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service;

use Reporion\Audit\AuditLog;
use Reporion\Index\Sqlite;
use Reporion\Service\Maintenance\MaintenanceReport;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\TagDictionary;
use Reporion\Service\TagReviewApply;
use Reporion\Service\Tags;
use Reporion\Storage\FileTree;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * Applying a tags:review run's ticked proposals: drop, merge, a merge turned
 * the other way round, a proposal whose tags are no longer in use. Fixture
 * data is fictitious (invariant 10).
 */
final class TagReviewApplyTest extends StorageTestCase
{
    private FileTree $storage;
    private TagReviewApply $apply;

    protected function setUp(): void
    {
        parent::setUp();
        $index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $this->storage = new FileTree($this->dataRoot, $index);
        file_put_contents($this->dataRoot . '/tags.yaml', "keep:\n  synonyms: []\n");
        $audit = new AuditLog($this->dataRoot . '/audit');
        $dictionary = new TagDictionary($this->dataRoot, \dirname(__DIR__, 2) . '/resources/synonyms.txt');
        $this->apply = new TagReviewApply($index, new Tags($this->storage, $index, $audit), $dictionary, $audit, new MaintenanceRunner([], $this->dataRoot, $audit));
        $this->page('uno', ['alpha', 'beta', 'noise']);
        $this->page('doi', ['beta']);
    }

    /** @param list<string> $tags */
    private function page(string $slug, array $tags): void
    {
        $this->storage->create('reports:mri:mioveni:260927-test-' . $slug, [
            'title' => 'TEST ' . $slug, 'visibility' => 'private', 'study_date' => '2026-09-27', 'patient' => ['name' => 'TEST ' . $slug], 'tags' => $tags,
        ], "# TEST {$slug}\n\nText.\n", 'owner');
    }

    /** @return list<string> */
    private function tags(string $slug): array
    {
        return $this->storage->read('reports:mri:mioveni:260927-test-' . $slug)->frontmatter['tags'] ?? [];
    }

    /** @param list<array<string, mixed>> $proposals */
    private function report(array $proposals): MaintenanceReport
    {
        $report = new MaintenanceReport('tags:review', 'apply', 'owner', [], '2026-10-23T10:00:00Z');
        foreach ($proposals as $p) {
            $report->item(null, null, 'proposal', '', $p);
        }

        return $report->finish();
    }

    public function testADropTakesTheTagOff(): void
    {
        $done = $this->apply->apply($this->report([['op' => 'drop', 'from' => ['noise']]]), [0], [], [], 'owner');

        self::assertSame(1, $done['applied']);
        self::assertSame(1, $done['changed']);
        self::assertSame(['alpha', 'beta'], $this->tags('uno'));
    }

    public function testAMergeUsesTheTargetTheOwnerTypedInTheRow(): void
    {
        $done = $this->apply->apply($this->report([['op' => 'merge', 'from' => ['alpha'], 'to' => 'beta']]), [0], [0 => 'gamma'], [], 'owner');

        self::assertSame(1, $done['applied']);
        self::assertContains('gamma', $this->tags('uno'));
        self::assertNotContains('alpha', $this->tags('uno'));
    }

    public function testAMergeTurnedRoundAlsoMergesTheProposedTargetIn(): void
    {
        // proposal: alpha → beta; the owner turned it into beta → alpha's side: the target is one of its own tags
        $this->apply->apply($this->report([['op' => 'merge', 'from' => ['alpha', 'beta'], 'to' => 'beta']]), [0], [0 => 'alpha'], [], 'owner');

        self::assertSame(['alpha', 'noise'], $this->tags('uno'));
        self::assertSame(['alpha'], $this->tags('doi'));
    }

    public function testAProposalWhoseTagsAreGoneIsSkipped(): void
    {
        $done = $this->apply->apply($this->report([['op' => 'drop', 'from' => ['vanished']]]), [0], [], [], 'owner');

        self::assertSame(0, $done['applied']);
        self::assertSame(0, $done['changed']);
    }

    public function testARejectedProposalIsCounted(): void
    {
        $done = $this->apply->apply($this->report([['op' => 'drop', 'from' => ['noise']]]), [], [], [0], 'owner');

        self::assertSame(1, $done['rejected']);
        self::assertSame(['alpha', 'beta', 'noise'], $this->tags('uno'));
    }
}
