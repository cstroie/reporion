<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service;

use Reporion\Audit\AuditLog;
use Reporion\Exception\RevisionConflictException;
use Reporion\Index\Sqlite;
use Reporion\Service\Maintenance\MaintenanceReport;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\TagCuration;
use Reporion\Service\TagDictionary;
use Reporion\Service\Tags;
use Reporion\Storage\FileTree;
use Reporion\Storage\StorageInterface;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * Applying a tags:review run's ticked proposals: drop, merge, a merge turned
 * the other way round, a proposal whose tags are no longer in use. Fixture
 * data is fictitious (invariant 10).
 */
final class TagCurationTest extends StorageTestCase
{
    private FileTree $storage;
    private TagCuration $curation;
    private TagDictionary $dictionary;

    protected function setUp(): void
    {
        parent::setUp();
        $index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $this->storage = new FileTree($this->dataRoot, $index);
        file_put_contents($this->dataRoot . '/tags.yaml', "keep:\n  synonyms: []\n");
        $audit = new AuditLog($this->dataRoot . '/audit');
        $this->dictionary = $dictionary = new TagDictionary($this->dataRoot, \dirname(__DIR__, 2) . '/resources/synonyms.txt');
        $this->curation = new TagCuration($index, new Tags($this->storage, $index, $audit), $dictionary, $audit, new MaintenanceRunner([], $this->dataRoot, $audit));
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

    /**
     * @param list<array<string, mixed>> $proposals
     * @param list<int>                  $pick
     * @param array<int, string>         $typed  the target typed in a row, by index
     * @param list<int>                  $reject
     *
     * @return array{applied: int, rejected: int, changed: int, skippedSigned: int, conflicts: int}
     */
    private function applyTicked(array $proposals, array $pick, array $typed = [], array $reject = []): array
    {
        $all = TagCuration::proposals($this->report($proposals));

        return $this->curation->apply(
            array_map(static fn (int $i): array => ['proposal' => $all[$i], 'to' => $typed[$i] ?? null], $pick),
            array_map(static fn (int $i): array => $all[$i], $reject),
            'owner',
        );
    }

    public function testADropTakesTheTagOff(): void
    {
        $done = $this->applyTicked([['op' => 'drop', 'from' => ['noise']]], [0]);

        self::assertSame(1, $done['applied']);
        self::assertSame(1, $done['changed']);
        self::assertSame(['alpha', 'beta'], $this->tags('uno'));
    }

    public function testAMergeUsesTheTargetTheOwnerTypedInTheRow(): void
    {
        $done = $this->applyTicked([['op' => 'merge', 'from' => ['alpha'], 'to' => 'beta']], [0], [0 => 'gamma']);

        self::assertSame(1, $done['applied']);
        self::assertContains('gamma', $this->tags('uno'));
        self::assertNotContains('alpha', $this->tags('uno'));
    }

    public function testAMergeTurnedRoundAlsoMergesTheProposedTargetIn(): void
    {
        // proposal: alpha → beta; the owner turned it into beta → alpha's side: the target is one of its own tags
        $this->applyTicked([['op' => 'merge', 'from' => ['alpha', 'beta'], 'to' => 'beta']], [0], [0 => 'alpha']);

        self::assertSame(['alpha', 'noise'], $this->tags('uno'));
        self::assertSame(['alpha'], $this->tags('doi'));
    }

    public function testAProposalWhoseTagsAreGoneIsSkipped(): void
    {
        $done = $this->applyTicked([['op' => 'drop', 'from' => ['vanished']]], [0]);

        self::assertSame(0, $done['applied']);
        self::assertSame(0, $done['changed']);
    }

    public function testARejectedProposalIsCounted(): void
    {
        $done = $this->applyTicked([['op' => 'drop', 'from' => ['noise']]], [], [], [0]);

        self::assertSame(1, $done['rejected']);
        self::assertSame(['alpha', 'beta', 'noise'], $this->tags('uno'));
    }

    public function testProposalsReadsOnlyWellFormedProposalItemsByTheirIndex(): void
    {
        $report = new MaintenanceReport('tags:review', 'apply', 'owner', [], '2026-10-23T10:00:00Z');
        $report->item(null, null, 'note', '', ['op' => 'drop', 'from' => ['x']]);
        $report->item(null, null, 'proposal', '', ['op' => 'bogus', 'from' => ['x']]);
        $report->item(null, null, 'proposal', '', ['op' => 'drop', 'from' => ['noise'], 'reason' => 'r']);

        self::assertSame([2 => ['op' => 'drop', 'from' => ['noise'], 'to' => '', 'reason' => 'r', 'source' => '']], TagCuration::proposals($report->finish()));
    }

    public function testTheWriteLockIsReleasedWhenTheWorkThrows(): void
    {
        $audit = new AuditLog($this->dataRoot . '/audit');
        $runner = new MaintenanceRunner([], $this->dataRoot, $audit);
        try {
            $runner->withWriteLock(static fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }

        self::assertFalse($runner->isLocked());
    }

    public function testMergeRenamesOnPagesThenFoldsTheDictionary(): void
    {
        $done = $this->curation->merge(['alpha'], 'beta', 'owner', keepNames: true);

        self::assertSame(1, $done['changed']);
        self::assertSame(['beta', 'noise'], $this->tags('uno'));
        self::assertSame(['alpha'], $this->dictionary->get('beta')['synonyms'] ?? []);
    }

    public function testMergeWithoutKeepNamesLeavesNoSynonymForAnUnlistedTag(): void
    {
        $this->curation->merge(['alpha'], 'beta', 'owner');

        self::assertSame([], $this->dictionary->get('beta')['synonyms'] ?? []);
    }

    public function testAConflictedMergeLeavesTheDictionaryForTheRerun(): void
    {
        file_put_contents($this->dataRoot . '/tags.yaml', "alpha:\n  group: g\n");
        $index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('read')->willReturnCallback(fn (string $path) => $this->storage->read($path));
        $storage->method('save')->willReturnCallback(fn (string $path) => throw new RevisionConflictException($this->storage->read($path), 1));
        $audit = new AuditLog($this->dataRoot . '/audit');
        $dictionary = new TagDictionary($this->dataRoot, \dirname(__DIR__, 2) . '/resources/synonyms.txt');
        $curation = new TagCuration($index, new Tags($storage, $index, $audit), $dictionary, $audit, new MaintenanceRunner([], $this->dataRoot, $audit));

        $done = $curation->merge(['alpha'], 'beta', 'owner', keepNames: true);

        self::assertSame(1, $done['conflicts']);
        self::assertSame(0, $done['changed']);
        self::assertSame('g', $dictionary->get('alpha')['group'], 'alpha is still in use: its entry stays');
        self::assertSame([], $dictionary->get('beta')['synonyms'] ?? []);
    }
}
