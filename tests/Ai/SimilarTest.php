<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Ai;

use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Service\Ai\Context;
use Reporion\Service\Ai\Embedder;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Storage\FlatFile;
use Reporion\Auth\User;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * Similar reports (roadmap phase 34e): index:vectors embeds each report's
 * de-identified conclusion with the one embedding model (the fake server's
 * deterministic bag of words), unchanged ones are not asked again, and the
 * nearest reports are other patients' — a deleted index loses nothing.
 * Fixture data is fictitious (invariant 10).
 */
final class SimilarTest extends StorageTestCase
{
    private FakeServer $server;
    private FlatFile $storage;
    private Sqlite $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new FakeServer();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $this->storage = new FlatFile($this->dataRoot, $this->index);
        $report = fn (string $slug, string $name, string $cnp, string $conclusion) => $this->storage->create(
            'reports:mri:mioveni:' . $slug,
            ['title' => $name, 'visibility' => 'private', 'study_date' => '2026-09-27', 'exam_title' => 'IRM genunchi', 'patient' => ['name' => $name, 'cnp' => $cnp]],
            "# {$name}\n\n## IRM genunchi\n\nDescriere.\n\n### Concluzii\n\n{$conclusion}\n",
            'owner',
        );
        $report('260927-test-unu', 'TEST Unu', '1900101000001', 'Ruptură de menisc medial, corn posterior.');
        $report('260927-test-doi', 'TEST Doi', '1900101000002', 'Ruptură de menisc medial, corn posterior, gradul 3.');
        $report('260927-test-trei', 'TEST Trei', '1900101000003', 'Fără modificări patologice.');
        // The same patient as Unu, another day: the timeline's, not Similar's
        $report('260928-test-unu', 'TEST Unu', '1900101000001', 'Ruptură de menisc medial, corn posterior, neschimbată.');
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function config(string $model = 'embed-test'): array
    {
        return [
            'paths' => ['data' => $this->dataRoot, 'index' => $this->dataRoot . '/index.sqlite'],
            'ai' => ['enabled' => true, 'servers' => [['name' => 'Local', 'endpoint' => $this->server->url, 'model' => 'chat']], 'embed_server' => 1, 'embed_model' => $model],
        ];
    }

    public function testTheEmbeddingTextIsTheConclusionWithNoName(): void
    {
        $text = Context::forEmbedding($this->storage->read('reports:mri:mioveni:260927-test-unu'));
        self::assertSame("IRM genunchi\n\nRuptură de menisc medial, corn posterior.", $text);
        self::assertNull(Embedder::fromConfig(['ai' => ['enabled' => true, 'embed_server' => 1, 'embed_model' => '']]), 'no model, no embedder');
    }

    public function testCheckSendsNothingApplyEmbedsAndARerunAsksNothing(): void
    {
        $task = Kernel::vectorsTask($this->config(), $this->storage, $this->index);
        $check = $task->run(MaintenanceTask::CHECK, 'owner', $task->options([]));
        self::assertSame(4, $check->summary()['would_embed']);
        self::assertNull($this->server->lastRequest()['body'], 'a check sends nothing');

        $apply = $task->run(MaintenanceTask::APPLY, 'owner', $task->options([]));
        self::assertSame(4, $apply->summary()['embedded']);
        self::assertSame('/v1/embeddings', $this->server->lastRequest()['path']);
        self::assertSame('embed-test', $this->server->lastRequest()['body']['model']);
        self::assertStringNotContainsString('TEST', (string) json_encode($this->server->lastRequest()['body']), 'no name leaves');

        $again = $task->run(MaintenanceTask::APPLY, 'owner', $task->options([]));
        self::assertSame(0, $again->summary()['embedded']);
        self::assertSame(4, $again->summary()['current']);
    }

    public function testTheNearestAreOtherPatientsAndADeletedIndexLosesNothing(): void
    {
        $task = Kernel::vectorsTask($this->config(), $this->storage, $this->index);
        $task->run(MaintenanceTask::APPLY, 'owner', $task->options([]));
        $owner = new User('owner', 'x', true, [], true, 'now', 'now');
        $pid = $this->storage->read('reports:mri:mioveni:260927-test-unu')->pid;
        $paths = array_column($this->index->similar($pid, 'embed-test', $owner), 'path');
        self::assertSame(['reports:mri:mioveni:260927-test-doi', 'reports:mri:mioveni:260927-test-trei'], $paths, 'nearest first; never the same patient');
        $before = $this->index->vectorStates();

        unset($this->storage, $this->index);
        unlink($this->dataRoot . '/index.sqlite');
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $this->storage = new FlatFile($this->dataRoot, $this->index);
        $this->index->rebuild(array_map(fn (string $p) => $this->storage->snapshotOf($p), iterator_to_array($this->storage->allPaths(), false)));
        $task = Kernel::vectorsTask($this->config(), $this->storage, $this->index);
        $task->run(MaintenanceTask::APPLY, 'owner', $task->options([]));
        self::assertEquals($before, $this->index->vectorStates(), 'the same vectors, from disk');
        self::assertSame($paths, array_column($this->index->similar($pid, 'embed-test', $owner), 'path'));
    }

    public function testAReportBelowTheMinimumScoreIsNoMatch(): void
    {
        $task = Kernel::vectorsTask($this->config(), $this->storage, $this->index);
        $task->run(MaintenanceTask::APPLY, 'owner', $task->options([]));
        $owner = new User('owner', 'x', true, [], true, 'now', 'now');
        $pid = $this->storage->read('reports:mri:mioveni:260927-test-unu')->pid;
        $all = $this->index->similar($pid, 'embed-test', $owner);
        self::assertCount(2, $all);
        [$near, $far] = array_column($all, 'score');
        self::assertGreaterThan($far, $near);

        $cut = ($near + $far) / 2;
        self::assertSame(['reports:mri:mioveni:260927-test-doi'], array_column($this->index->similar($pid, 'embed-test', $owner, 10, $cut), 'path'), 'the unrelated one is left out, not used as filler');
        self::assertSame([], $this->index->similar($pid, 'embed-test', $owner, 10, $near + 0.001), 'nothing near enough: none');

        self::assertSame(Embedder::DEFAULT_MIN_SCORE, Embedder::minScore([]), 'unset: the default');
        self::assertSame(0.72, Embedder::minScore(['ai' => ['embed_min_score' => 0.72]]));
        self::assertSame(Embedder::DEFAULT_MIN_SCORE, Embedder::minScore(['ai' => ['embed_min_score' => 3]]), 'out of range: the default');
    }

    public function testAVectorFromAnOlderTextIsStale(): void
    {
        $path = 'reports:mri:mioveni:260927-test-unu';
        self::assertNull(Embedder::vectorState($this->index, 'embed-test', $this->storage->read($path)), 'no vector yet');
        $task = Kernel::vectorsTask($this->config(), $this->storage, $this->index);
        $task->run(MaintenanceTask::APPLY, 'owner', $task->options([]));
        self::assertTrue(Embedder::vectorState($this->index, 'embed-test', $this->storage->read($path)));

        $page = $this->storage->read($path);
        $this->storage->save($path, $page->frontmatter, str_replace('corn posterior.', 'corn anterior.', $page->body), $page->rev, 'owner');
        self::assertFalse(Embedder::vectorState($this->index, 'embed-test', $this->storage->read($path)), 'the conclusion changed');
        self::assertNull(Embedder::vectorState($this->index, 'other-model', $this->storage->read($path)), 'another model: none');

        $task->run(MaintenanceTask::APPLY, 'owner', $task->options([]));
        self::assertTrue(Embedder::vectorState($this->index, 'embed-test', $this->storage->read($path)), 'current again');
    }

    public function testATextTheServerRejectsFailsAloneAndTheRunCarriesOn(): void
    {
        $task = Kernel::vectorsTask($this->config('picky-embed'), $this->storage, $this->index);
        $report = $task->run(MaintenanceTask::APPLY, 'owner', $task->options([]));
        self::assertSame(3, $report->summary()['embedded'], 'the batch again, a text at a time');
        self::assertSame(1, $report->summary()['failed']);
        self::assertCount(3, $this->index->vectorStates());
        self::assertStringContainsString('input too long', implode(' ', $report->notes()));

        $again = $task->run(MaintenanceTask::APPLY, 'owner', $task->options([]));
        self::assertSame(['embedded' => 0, 'current' => 3, 'failed' => 1], array_intersect_key($again->summary(), ['embedded' => 0, 'current' => 0, 'failed' => 0]), 'asked again, the rest left alone');
    }

    public function testADeadServerStopsTheRun(): void
    {
        $task = Kernel::vectorsTask($this->config('fail-embed'), $this->storage, $this->index);
        $report = $task->run(MaintenanceTask::APPLY, 'owner', $task->options([]));
        self::assertSame(0, $report->summary()['embedded']);
        self::assertSame(4, $report->summary()['failed']);
        self::assertSame([], $this->index->vectorStates());
    }
}
