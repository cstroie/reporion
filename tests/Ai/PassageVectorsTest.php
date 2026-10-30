<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Ai;

use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Auth\User;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Service\Ai\Embedder;
use Reporion\Service\Library\PassageSearch;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Storage\FileTree;
use Reporion\Support\SourceChunks;
use Reporion\Support\SourcePassages;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * Library passages by meaning (docs/library.md, phase 7): index:vectors embeds each library
 * source's passages (the fake server's deterministic bag of words), cut as Ask the notebook cuts
 * them; unchanged sources are not asked again, a changed one is embedded again whole, a deleted
 * one dropped; PassageSearch finds a passage that shares no word with the question when its
 * vector is near. The articles are made up.
 */
final class PassageVectorsTest extends StorageTestCase
{
    private FakeServer $server;
    private FileTree $storage;
    private Sqlite $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new FakeServer();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $this->storage = new FileTree($this->dataRoot, $this->index);
        $csl = ['type' => 'article-journal', 'title' => 'A made-up study'];
        $this->storage->create('library:abel2020', ['title' => 'A made-up study', 'visibility' => 'private', 'csl' => $csl], self::article(), 'owner');
        $this->storage->create('library:record2019', ['title' => 'A record', 'visibility' => 'private', 'csl' => $csl], '', 'owner');
        $this->storage->create('wiki:not-a-source', ['title' => 'Notes', 'visibility' => 'private'], "Some notes.\n", 'owner');
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        parent::tearDown();
    }

    private static function article(string $extra = ''): string
    {
        $text = '';
        foreach (range(1, 4) as $page) {
            $text .= "*— p. {$page} —*\n\n" . str_repeat("Page {$page} sentence about the cohort and its imaging. ", 25) . "\n\n";
        }

        return $text . $extra . "## References\n\n```text\n1. Cited elsewhere.\n```\n";
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return [
            'paths' => ['data' => $this->dataRoot, 'index' => $this->dataRoot . '/index.sqlite'],
            'ai' => ['enabled' => true, 'servers' => [['name' => 'Local', 'endpoint' => $this->server->url, 'model' => 'chat']], 'embed_server' => 1, 'embed_model' => 'embed-test'],
        ];
    }

    /** The fake server's embedding of $text (tests/fixtures/ai/fake-openai.php), unit length */
    private static function bag(string $text): array
    {
        $vector = array_fill(0, 16, 0.0);
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $vector[crc32($word) % 16] += 1.0;
        }

        return Embedder::unit($vector);
    }

    public function testTheIndexerAndTheQueryCutTheSamePassages(): void
    {
        $body = $this->storage->read('library:abel2020')->body;
        $passages = SourcePassages::ofSource($body);

        self::assertGreaterThan(1, \count($passages));
        self::assertSame($passages, SourcePassages::cut(SourceChunks::withoutReferences($body)), 'what Ask the notebook cuts from a source\'s text');
        self::assertSame(range(0, \count($passages) - 1), array_column($passages, 'n'));
        self::assertSame(1, $passages[0]['page']);
        self::assertStringNotContainsString('Cited elsewhere', implode("\n", array_column($passages, 'text')));
    }

    public function testSourcesArePassagesEmbeddedOnceAndAgainWhenChanged(): void
    {
        $task = Kernel::vectorsTask($this->config(), $this->storage, $this->index);
        $check = $task->run(MaintenanceTask::CHECK, 'owner', $task->options([]))->summary();
        self::assertSame(1, $check['sources_would_embed']);
        self::assertSame(1, $check['sources_no_text'], 'a record with no file has nothing to embed');

        $apply = $task->run(MaintenanceTask::APPLY, 'owner', $task->options([]))->summary();
        $page = $this->storage->read('library:abel2020');
        $passages = SourcePassages::ofSource($page->body);
        self::assertSame(1, $apply['sources_embedded']);
        self::assertSame(\count($passages), $apply['passages_embedded']);
        $state = $this->index->passageStates()[$page->pid];
        self::assertSame('embed-test', $state['model']);
        self::assertSame(array_map(static fn (array $p): string => Embedder::sha('embed-test', $p['text']), $passages), $state['shas'], 'passage n is the query\'s passage n');

        self::assertSame(1, $task->run(MaintenanceTask::APPLY, 'owner', $task->options([]))->summary()['sources_current']);

        $this->storage->save('library:abel2020', $page->frontmatter, self::article("A new closing paragraph.\n\n"), $page->rev, 'owner');
        self::assertSame(1, $task->run(MaintenanceTask::APPLY, 'owner', $task->options([]))->summary()['sources_embedded'], 'a changed source is embedded again');

        $this->storage->delete('library:abel2020', 'owner');
        self::assertSame([], $this->index->passageStates(), 'a deleted page takes its passages along');
    }

    public function testLimitCountsWholeSourcesAfterReports(): void
    {
        $this->storage->create('library:cole2021', ['title' => 'Another', 'visibility' => 'private', 'csl' => ['title' => 'Another']], self::article(), 'owner');
        $task = Kernel::vectorsTask($this->config(), $this->storage, $this->index);

        $run = $task->run(MaintenanceTask::APPLY, 'owner', $task->options(['limit' => 1]))->summary();

        self::assertSame(1, $run['sources_embedded']);
        self::assertSame(1, $run['remaining']);
        self::assertCount(1, $this->index->passageStates(), 'no source half embedded');
    }

    public function testAPassageWithNoWordOfTheQuestionIsFoundByMeaning(): void
    {
        $page = $this->storage->read('library:abel2020');
        $passages = SourcePassages::ofSource($page->body);
        $question = 'Cum arată leziunile?';
        // Passage 2's vector is the question's: near in meaning, though it shares no word with it
        $rows = [];
        foreach ($passages as $p) {
            $rows[] = ['n' => $p['n'], 'sha' => Embedder::sha('embed-test', $p['text']), 'page' => $p['page'], 'vec' => $p['n'] === 2 ? self::bag($question) : self::bag('unrelated words entirely')];
        }
        $this->index->putPassages($page->pid, 'embed-test', $rows);
        $texts = [['key' => 'abel2020', 'path' => 'library:abel2020', 'title' => 'A made-up study', 'text' => SourceChunks::withoutReferences($page->body), 'record' => $page]];
        $reader = new User('reader', '', false, [new Grant('library', GrantRole::Viewer)], true, '', '');

        $words = (new PassageSearch())->choose($texts, ['leziun'], 100000, $question, $reader);
        self::assertSame([], $words['passages'], 'by words alone: nothing');

        $search = new PassageSearch(Embedder::fromConfig($this->config()), $this->index, 0.9);
        $found = $search->choose($texts, ['leziun'], 100000, $question, $reader);
        self::assertSame([$passages[2]['text']], array_column($found['passages'], 'text'));
        self::assertSame(1, $found['semantic']);
        self::assertSame($passages[2]['page'], $found['passages'][0]['page']);

        // A vector made from another text is not used for this one
        $this->storage->save('library:abel2020', $page->frontmatter, str_replace('Page ' . $passages[2]['page'] . ' sentence', 'Page ' . $passages[2]['page'] . ' phrase', $page->body), $page->rev, 'owner');
        $changed = $this->storage->read('library:abel2020');
        $texts[0]['text'] = SourceChunks::withoutReferences($changed->body);
        $texts[0]['record'] = $changed;
        self::assertSame([], $search->choose($texts, ['leziun'], 100000, $question, $reader)['passages']);

        // Not one the caller cannot read (invariant 6)
        $stranger = new User('stranger', '', false, [new Grant('wiki', GrantRole::Viewer)], true, '', '');
        $this->index->putPassages($changed->pid, 'embed-test', $rows);
        self::assertSame([], $this->index->passageCandidates(self::bag($question), 'embed-test', [$changed->pid], $stranger));
    }
}
