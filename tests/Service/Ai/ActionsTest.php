<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Ai;

use Reporion\Index\Sqlite;
use Reporion\Service\Ai\Action;
use Reporion\Service\Ai\Actions;
use Reporion\Service\Ai\AiConfig;
use Reporion\Service\Ai\DefaultPrompts;
use Reporion\Storage\FileTree;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * Service\Ai\Actions::forPage() (2026-09-28): the profile's own page's first
 * table decides which actions exist and their order. Support\ProfileTable
 * does the parsing, this is what a row then does once its id has (or
 * hasn't) a prompt page of its own.
 */
final class ActionsTest extends StorageTestCase
{
    private const PATH = 'reports:mri:mioveni:260927-test-unu';

    private FileTree $storage;
    private Sqlite $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 3) . '/migrations');
        $this->storage = new FileTree($this->dataRoot, $this->index);
    }

    private function config(): AiConfig
    {
        return new AiConfig(true, 'http://127.0.0.1:1/v1', 'm', 0.3, 0.8, 0, 30, 'reports', ['reports'], false, '');
    }

    public function testTheTableOrderIsTheActionOrderRegardlessOfPageCreationOrder(): void
    {
        $this->storage->create('ai:profiles:reports:expand', ['visibility' => 'private'], "Expand it.\n", 'owner');
        $this->storage->create('ai:profiles:reports:summarize', ['visibility' => 'private'], "Summarize it.\n", 'owner');
        $this->storage->create(
            'ai:profiles:reports',
            ['title' => 'Reports profile', 'visibility' => 'private'],
            "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n"
            . "| summarize | Summarize | Create a summary | summary.png | show |\n"
            . "| expand | Expand | Expand the text | expand.png | replace |\n",
            'owner'
        );

        $ids = array_map(static fn (Action $a): string => $a->id, (new Actions($this->config(), $this->storage, $this->index))->forPage(self::PATH));

        self::assertSame(['summarize', 'expand'], $ids, 'table order, not creation order');
    }

    public function testTheNamespacesServerServesActionsThatNameNoneOfTheirOwn(): void
    {
        $this->storage->create('ai:profiles:reports:expand', ['visibility' => 'private'], "Expand it.\n", 'owner');
        $this->storage->create('ai:profiles:reports:shorten', ['visibility' => 'private'], "Shorten it.\n", 'owner');
        $this->storage->create('ai:profiles:reports:special:summary', ['visibility' => 'private'], "Summarize.\n", 'owner');
        $this->storage->create(
            'ai:profiles:reports',
            ['visibility' => 'private'],
            "| ID | Label | Tooltip | Icon | Result | Model |\n|---|---|---|---|---|---|\n"
            . "| expand | Expand | | | replace | |\n"
            . "| shorten | Shorten | | | replace | Local:lite |\n",
            'owner'
        );
        $config = AiConfig::fromConfig(['ai' => [
            'enabled' => true,
            'server' => 1,
            'namespaces' => ['reports'],
            'servers' => [['name' => 'Local', 'endpoint' => 'http://127.0.0.1:1/v1', 'tiers' => ['normal' => ['model' => 'm']]], ['name' => 'Cloud', 'endpoint' => 'http://127.0.0.1:2/v1', 'tiers' => ['normal' => ['model' => 'm']]]],
            'server_map' => ['reports:mri' => 2],
        ]]);
        $actions = new Actions($config, $this->storage, $this->index);

        $servers = static fn (array $list): array => array_map(static fn (Action $a): ?string => $a->server, $list);
        self::assertSame(['#2', 'Local'], $servers($actions->forPage(self::PATH)), 'the namespace\'s server, unless the Model cell names one');
        self::assertSame([null, 'Local'], $servers($actions->forPage('reports:ct:x')), 'elsewhere the server in use');
        self::assertSame('#2', $actions->special(self::PATH, 'summary')?->server, 'a reserved prompt too');
        self::assertNull($actions->special('reports:ct:x', 'summary')?->server);
    }

    public function testARowWithNoPromptPageContributesNothing(): void
    {
        $this->storage->create('ai:profiles:reports:expand', ['visibility' => 'private'], "Expand it.\n", 'owner');
        $this->storage->create(
            'ai:profiles:reports',
            ['title' => 'Reports profile', 'visibility' => 'private'],
            "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n"
            . "| summarize | Summarize | Create a summary | summary.png | show |\n"
            . "| expand | Expand | Expand the text | expand.png | replace |\n",
            'owner'
        );

        $ids = array_map(static fn (Action $a): string => $a->id, (new Actions($this->config(), $this->storage, $this->index))->forPage(self::PATH));

        self::assertSame(['expand'], $ids, '"summarize" has no page of its own: dropped, not an error');
    }

    public function testAnInvalidResultDefaultsToShow(): void
    {
        $this->storage->create('ai:profiles:reports:custom', ['visibility' => 'private'], "{prompt}\n", 'owner');
        $this->storage->create(
            'ai:profiles:reports',
            ['title' => 'Reports profile', 'visibility' => 'private'],
            "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n| custom | Custom | Custom prompt | ✏️ | not-a-mode |\n",
            'owner'
        );

        $list = (new Actions($this->config(), $this->storage, $this->index))->forPage(self::PATH);

        self::assertSame('show', $list[0]->result);
    }

    public function testTheSystemPromptAndItsPerActionAppendageStillConcatenate(): void
    {
        $this->storage->create('ai:profiles:reports:system', ['visibility' => 'private'], "Ești radiolog.\n", 'owner');
        $this->storage->create('ai:profiles:reports:system:quality', ['visibility' => 'private'], "Ești auditor.\n", 'owner');
        $this->storage->create('ai:profiles:reports:quality', ['visibility' => 'private'], "Verifică.\n", 'owner');
        $this->storage->create(
            'ai:profiles:reports',
            ['title' => 'Reports profile', 'visibility' => 'private'],
            "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n| quality | Check | Review | ✔️ | show |\n",
            'owner'
        );

        $list = (new Actions($this->config(), $this->storage, $this->index))->forPage(self::PATH);

        self::assertStringStartsWith('Ești radiolog.', $list[0]->system);
        self::assertStringEndsWith('Ești auditor.', $list[0]->system);
    }

    public function testNoIndexPageIsNoActions(): void
    {
        $this->storage->create('ai:profiles:reports:quality', ['visibility' => 'private'], "Verifică.\n", 'owner');

        self::assertSame([], (new Actions($this->config(), $this->storage, $this->index))->forPage(self::PATH), 'no ai:profiles:reports page at all: no table to read');
    }

    public function testPagesOutsideTheNamespacesUseTheFallbackProfileWhenThereIsOne(): void
    {
        $this->storage->create('ai:profiles:default:rewrite', ['visibility' => 'private'], "Rewrite it.\n", 'owner');
        $this->storage->create('ai:profiles:default', ['visibility' => 'private'], "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n| rewrite | Rewrite | | | replace |\n", 'owner');
        $this->storage->create('ai:profiles:reports:conclusion', ['visibility' => 'private'], "Conclude.\n", 'owner');
        $this->storage->create('ai:profiles:reports', ['visibility' => 'private'], "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n| conclusion | Conclusion | | | append |\n", 'owner');
        $ids = static fn (array $actions): array => array_map(static fn (Action $a): string => $a->id, $actions);

        self::assertSame([], (new Actions($this->config(), $this->storage, $this->index))->forPage('docs:howto'), 'no fallback: no assistant there');

        $config = new AiConfig(true, 'http://127.0.0.1:1/v1', 'm', 0.3, 0.8, 0, 30, 'reports', ['reports'], false, '', fallbackProfile: 'default');
        $actions = new Actions($config, $this->storage, $this->index);
        self::assertSame(['rewrite'], $ids($actions->forPage('docs:howto')));
        self::assertSame(['conclusion'], $ids($actions->forPage(self::PATH)), 'the reports profile where it serves');
    }

    public function testThePromptPagesModelAppliesWhenTheTableCellIsBlankAndToAReservedPrompt(): void
    {
        $this->storage->create('ai:profiles:reports:expand', ['visibility' => 'private', 'model' => 'lite'], "Expand it.\n", 'owner');
        $this->storage->create('ai:profiles:reports:grammar', ['visibility' => 'private', 'model' => 'lite'], "Fix it.\n", 'owner');
        $this->storage->create('ai:profiles:reports:special:summary', ['visibility' => 'private', 'model' => '2:lite'], "Summarize.\n", 'owner');
        $this->storage->create(
            'ai:profiles:reports',
            ['visibility' => 'private'],
            "| ID | Label | Tooltip | Icon | Result | Model |\n|---|---|---|---|---|---|\n| expand | Expand | | | replace | |\n| grammar | Grammar | | | replace | expert |\n",
            'owner'
        );
        $actions = new Actions($this->config(), $this->storage, $this->index);
        $models = [];
        foreach ($actions->forPage(self::PATH) as $action) {
            $models[$action->id] = $action->model;
        }

        self::assertSame(['expand' => 'lite', 'grammar' => 'expert'], $models, 'the cell wins over the page');
        $summary = $actions->special(self::PATH, 'summary');
        self::assertNotNull($summary);
        self::assertSame(['lite', '2'], [$summary->model, $summary->server], 'a reserved prompt kept out of the rail');
    }

    public function testAReservedPromptHasNoSystemPromptAndIsNeverARailAction(): void
    {
        $this->storage->create('ai:profiles:reports:system', ['visibility' => 'private'], "You are a radiologist.\n", 'owner');
        $this->storage->create('ai:profiles:reports:special:summary', ['visibility' => 'private'], "Summarize.\n", 'owner');
        $this->storage->create('ai:profiles:reports:summary', ['visibility' => 'private'], "Old place.\n", 'owner');
        $this->storage->create('ai:profiles:reports:conclusion', ['visibility' => 'private'], "Conclude.\n", 'owner');
        $this->storage->create(
            'ai:profiles:reports',
            ['visibility' => 'private'],
            "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n| summary | Summary | | | show |\n| special | Special | | | show |\n| conclusion | Conclusion | | | append |\n",
            'owner'
        );
        $actions = new Actions($this->config(), $this->storage, $this->index);

        self::assertSame(['conclusion'], array_map(static fn (Action $a): string => $a->id, $actions->forPage(self::PATH)));
        self::assertSame('You are a radiologist.', $actions->find(self::PATH, 'conclusion')?->system);
        self::assertSame('Summarize.', $actions->find(self::PATH, 'summary')?->prompt, 'the special page, not ns:summary');
        self::assertSame('', $actions->special(self::PATH, 'summary')?->system);
    }

    public function testCommitRunsLiteWithAShortTimeoutUnlessItsPageSaysOtherwise(): void
    {
        $this->storage->create('ai:profiles:reports:special:commit', ['visibility' => 'private'], "{diff}\n", 'owner');
        $this->storage->create('ai:profiles:reports:special:summary', ['visibility' => 'private', 'timeout' => 30], "Summarize.\n", 'owner');
        $actions = new Actions($this->config(), $this->storage, $this->index);

        $commit = $actions->special(self::PATH, 'commit');
        self::assertNotNull($commit);
        self::assertSame(['lite', 10], [$commit->model, $commit->timeout], 'a Save waits for it');
        self::assertSame(['normal', 30], [$actions->special(self::PATH, 'summary')?->model, $actions->special(self::PATH, 'summary')?->timeout]);

        $page = $this->storage->read('ai:profiles:reports:special:commit');
        $this->storage->save($page->path, ['visibility' => 'private', 'model' => 'normal', 'timeout' => 4], "{diff}\n", $page->rev, 'owner');
        $own = (new Actions($this->config(), $this->storage, $this->index))->special(self::PATH, 'commit');
        self::assertSame(['normal', 4], [$own?->model, $own?->timeout], 'the page\'s own');
    }

    public function testADashRowStartsASectionNeverAtTheTopTwiceOrAtTheEnd(): void
    {
        foreach (['create', 'conclusion', 'quality', 'linter'] as $id) {
            $this->storage->create('ai:profiles:reports:' . $id, ['visibility' => 'private'], "Do it.\n", 'owner');
        }
        $this->storage->create(
            'ai:profiles:reports',
            ['visibility' => 'private'],
            "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n"
            . "| --- | | | | |\n| create | Create | | | insert |\n| conclusion | Conclusion | | | append |\n"
            . "| --- | | | | |\n| ---- |\n| nopage | No page | | | show |\n| quality | Quality | | | show |\n| linter | Linter | | | show |\n| --- | | | | |\n",
            'owner'
        );

        $sections = [];
        foreach ((new Actions($this->config(), $this->storage, $this->index))->forPage(self::PATH) as $action) {
            $sections[$action->id] = $action->forEditor()['section'];
        }

        self::assertSame(['create' => false, 'conclusion' => false, 'quality' => true, 'linter' => false], $sections);
    }

    public function testABreakRowWithALabelNamesItsSectionAtTheTopToo(): void
    {
        foreach (['create', 'quality', 'linter'] as $id) {
            $this->storage->create('ai:profiles:reports:' . $id, ['visibility' => 'private'], "Do it.\n", 'owner');
        }
        $this->storage->create(
            'ai:profiles:reports',
            ['visibility' => 'private'],
            "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n"
            . "| --- | Write | | pencil | |\n| create | Create | | | insert |\n"
            . "| --- | | | | |\n| --- | Check | | seal-check | |\n| quality | Quality | | | show |\n| linter | Linter | | | show |\n",
            'owner'
        );

        $editor = [];
        foreach ((new Actions($this->config(), $this->storage, $this->index))->forPage(self::PATH) as $action) {
            $editor[$action->id] = [$action->forEditor()['sectionLabel'], $action->forEditor()['sectionIcon']];
        }

        self::assertSame(['create' => ['Write', 'pencil'], 'quality' => ['Check', 'seal-check'], 'linter' => ['', '']], $editor, 'the last label wins; a plain line stays a line');
    }

    public function testEachPromptPageIsReadOncePerObjectAcrossRepeatedLookups(): void
    {
        $this->storage->create('ai:profiles:reports:special:summary', ['visibility' => 'private'], "Summarize.\n", 'owner');
        $this->storage->create('ai:profiles:reports:conclusion', ['visibility' => 'private'], "Conclude.\n", 'owner');
        $this->storage->create('ai:profiles:reports', ['visibility' => 'private'], "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n| conclusion | Conclusion | | | append |\n", 'owner');
        $actions = new Actions($this->config(), $this->storage, $this->index);

        self::assertSame('Summarize.', $actions->special(self::PATH, 'summary')?->prompt);
        self::assertNull($actions->special(self::PATH, 'tags'), 'no page, no feature');

        // Edited under a live object: it keeps what it read (one request, one CLI run)…
        $page = $this->storage->read('ai:profiles:reports:special:summary');
        $this->storage->save($page->path, $page->frontmatter, "Summarize differently.\n", $page->rev, 'owner');
        $this->storage->create('ai:profiles:reports:special:tags', ['visibility' => 'private'], "Tag it.\n", 'owner');
        $again = $actions->special(self::PATH, 'summary');
        self::assertNotNull($again);
        self::assertSame('Summarize.', $again->prompt);
        self::assertNull($actions->special(self::PATH, 'tags'));
        self::assertSame(['conclusion'], array_map(static fn (Action $a): string => $a->id, $actions->forPage(self::PATH)));

        // …and the next one reads the pages as they are now
        $fresh = new Actions($this->config(), $this->storage, $this->index);
        self::assertSame('Summarize differently.', $fresh->special(self::PATH, 'summary')?->prompt);
        self::assertSame('Tag it.', $fresh->special(self::PATH, 'tags')?->prompt);
    }

    /** @param array<string, mixed> $ai */
    private function routed(array $ai): AiConfig
    {
        return AiConfig::fromConfig(['ai' => $ai + [
            'enabled' => true,
            'server' => 1,
            'servers' => [['name' => 'Local', 'endpoint' => 'http://127.0.0.1:1/v1', 'tiers' => ['normal' => ['model' => 'm']]], ['name' => 'Cloud', 'endpoint' => 'http://127.0.0.1:2/v1', 'tiers' => ['normal' => ['model' => 'm']]]],
        ]]);
    }

    private function table(string $profile, string ...$ids): void
    {
        $rows = implode('', array_map(static fn (string $id): string => '| ' . $id . ' | ' . ucfirst($id) . " | | | show |\n", $ids));
        $this->storage->create('ai:profiles:' . $profile, ['visibility' => 'private'], "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n" . $rows, 'owner');
    }

    public function testTheLibrarysFeaturesFollowTheLibraryNamespaceWhereverTheNotebookIs(): void
    {
        $this->storage->create('ai:profiles:research:special:ask-notebook', ['visibility' => 'private'], "Answer from the sources.\n", 'owner');
        $this->storage->create('ai:profiles:teaching:special:ask-notebook', ['visibility' => 'private'], "Not this one.\n", 'owner');
        $this->storage->create('ai:profiles:teaching:special:summary', ['visibility' => 'private'], "Summarize.\n", 'owner');
        $this->storage->create('ai:profiles:teaching:rewrite', ['visibility' => 'private'], "Rewrite.\n", 'owner');
        $this->table('teaching', 'rewrite');
        $actions = new Actions($this->routed([
            'profile_map' => ['library' => 'research', 'teaching' => 'teaching'],
            'server_map' => ['library' => 2],
        ]), $this->storage, $this->index);

        $ask = $actions->special('teaching:notebook', 'ask-notebook');
        self::assertSame('Answer from the sources.', $ask?->prompt, 'the library\'s profile, not teaching\'s');
        self::assertSame('#2', $ask->server, 'and the library\'s server');
        self::assertSame('Summarize.', $actions->special('teaching:notebook', 'summary')?->prompt, 'its ordinary assistant stays its namespace\'s');
        self::assertNull($actions->special('teaching:notebook', 'summary')->server);
        self::assertSame(['rewrite'], array_map(static fn (Action $a): string => $a->id, $actions->forPage('teaching:notebook')));
    }

    public function testAPageNamesItsPromptsProfileButNeverItsServer(): void
    {
        $this->storage->create('ai:profiles:research:grammar', ['visibility' => 'private'], "Fix the grammar.\n", 'owner');
        $this->storage->create('ai:profiles:research:special:summary', ['visibility' => 'private'], "Research summary.\n", 'owner');
        $this->table('research', 'grammar');
        $this->storage->create('ai:profiles:teaching:rewrite', ['visibility' => 'private'], "Rewrite.\n", 'owner');
        $this->table('teaching', 'rewrite');
        $this->storage->create('teaching:notes', ['visibility' => 'private', 'prompts' => 'research'], "Notes.\n", 'owner');
        $this->storage->create('teaching:unknown', ['visibility' => 'private', 'prompts' => 'nowhere'], "Notes.\n", 'owner');
        $this->storage->create('teaching:invalid', ['visibility' => 'private', 'prompts' => 'Bad Name'], "Notes.\n", 'owner');
        $this->storage->create('elsewhere:notes', ['visibility' => 'private', 'prompts' => 'research'], "Notes.\n", 'owner');
        $actions = new Actions($this->routed(['profile_map' => ['teaching' => 'teaching'], 'server_map' => ['teaching' => 2]]), $this->storage, $this->index);
        $ids = static fn (array $list): array => array_map(static fn (Action $a): string => $a->id, $list);

        self::assertSame(['grammar'], $ids($actions->forPage('teaching:notes')));
        self::assertSame('#2', $actions->forPage('teaching:notes')[0]->server, 'the namespace\'s server');
        self::assertSame('Research summary.', $actions->special('teaching:notes', 'summary')?->prompt);
        self::assertSame(['rewrite'], $ids($actions->forPage('teaching:unknown')), 'a profile there is not: ignored');
        self::assertSame(['rewrite'], $ids($actions->forPage('teaching:invalid')), 'not a profile name: ignored');
        self::assertSame(['grammar'], $ids($actions->forPage('elsewhere:notes')), 'also where no profile serves');
    }

    public function testAMissingPromptPageIsTheFallbackProfilesButNeverTheTable(): void
    {
        $this->storage->create('ai:profiles:wiki:ask', ['visibility' => 'private', 'model' => 'lite'], "Ask {prompt}.\n", 'owner');
        $this->storage->create('ai:profiles:wiki:system:ask', ['visibility' => 'private'], "Wiki's appendage.\n", 'owner');
        $this->storage->create('ai:profiles:wiki:special:commit', ['visibility' => 'private'], "Note it.\n", 'owner');
        $this->storage->create('ai:profiles:wiki:rewrite', ['visibility' => 'private'], "Rewrite.\n", 'owner');
        $this->table('wiki', 'rewrite');
        $this->table('research', 'ask', 'grammar');
        $this->storage->create('ai:profiles:research:special:ask-notebook', ['visibility' => 'private'], "Answer.\n", 'owner');
        $map = ['profile_map' => ['library' => 'research']];
        $ids = static fn (array $list): array => array_map(static fn (Action $a): string => $a->id, $list);

        $none = new Actions($this->routed($map), $this->storage, $this->index);
        self::assertSame([], $none->forPage('library:smith2020'), 'no fallback set: missing');
        self::assertNull($none->special('library:smith2020', 'commit'));

        $actions = new Actions($this->routed($map + ['fallback_profile' => 'wiki']), $this->storage, $this->index);
        $rail = $actions->forPage('library:smith2020');
        self::assertSame(['ask'], $ids($rail), 'research\'s own table, wiki\'s ask page; grammar is nowhere');
        self::assertSame('lite', $rail[0]->model, 'the settings of the page found');
        self::assertStringContainsString("Wiki's appendage.", $rail[0]->system);
        self::assertSame('Note it.', $actions->special('library:smith2020', 'commit')?->prompt);
        self::assertSame(['rewrite'], $ids($actions->forPage('docs:howto')), 'the fallback\'s own rail elsewhere');

        $this->table('empty');
        $this->storage->create('ai:profiles:empty:special:summary', ['visibility' => 'private'], "S.\n", 'owner');
        $bare = new Actions($this->routed(['profile_map' => ['bare' => 'nothing'], 'fallback_profile' => 'wiki']), $this->storage, $this->index);
        self::assertSame([], $bare->forPage('bare:x'), 'a profile with no table has no rail, whatever the fallback has');

        $overview = $actions->overview('research');
        $rows = array_column(array_filter($overview['rail'], static fn (array $r): bool => !$r['break']), null, 'id');
        self::assertSame(['wiki', true, 'ai:profiles:wiki:ask'], [$rows['ask']['from'], $rows['ask']['present'], $rows['ask']['path']]);
        self::assertSame(['', false], [$rows['grammar']['from'], $rows['grammar']['present']]);
        $reserved = array_column($overview['reserved'], null, 'id');
        self::assertSame(['wiki', true], [$reserved['commit']['from'], $reserved['commit']['present']]);
        self::assertSame(['', true], [$reserved['ask-notebook']['from'], $reserved['ask-notebook']['present']]);
    }

    public function testABorrowedPromptNeverRunsOnTheServerItNames(): void
    {
        $this->storage->create('ai:profiles:wiki:special:commit', ['visibility' => 'private', 'model' => '#2:lite'], "Note it.\n", 'owner');
        $this->storage->create('ai:profiles:wiki:ask', ['visibility' => 'private', 'model' => '#2:lite'], "Ask.\n", 'owner');
        $this->table('wiki', 'ask');
        $this->table('reports', 'ask');
        $this->storage->create('ai:profiles:other:grammar', ['visibility' => 'private'], "Fix.\n", 'owner');
        $this->storage->create('ai:profiles:other', ['visibility' => 'private'], "| ID | Label | Tooltip | Icon | Result | Model |\n|---|---|---|---|---|---|\n| grammar | Grammar | | | replace | #2:lite |\n", 'owner');
        $this->storage->create('reports:mri:x', ['visibility' => 'private', 'prompts' => 'other'], "Raport.\n", 'owner');
        $actions = new Actions($this->routed(['profile_map' => ['reports' => 'reports'], 'fallback_profile' => 'wiki']), $this->storage, $this->index);

        self::assertNull($actions->special('reports:ct:y', 'commit')?->server, 'the fallback\'s commit on reports\' own server');
        self::assertSame('lite', $actions->special('reports:ct:y', 'commit')->model);
        self::assertNull($actions->forPage('reports:ct:y')[0]->server, 'the fallback\'s ask page likewise');
        self::assertNull($actions->forPage('reports:mri:x')[0]->server, 'nor a table a page chose with prompts:');
        self::assertSame('#2', $actions->special('docs:howto', 'commit')?->server, 'where the fallback serves, its own page names its server');
    }

    public function testAPromptAboutNoPageTakesASubnamespacesOwnBeforeTheFallbacks(): void
    {
        $this->storage->create('ai:profiles:wiki:special:tags-review', ['visibility' => 'private'], "Generic review.\n", 'owner');
        $this->storage->create('ai:profiles:mri:special:tags-review', ['visibility' => 'private'], "Revizuire.\n", 'owner');
        $this->storage->create('reports:mri:x', ['visibility' => 'private'], "Raport.\n", 'owner');
        $actions = new Actions($this->routed(['profile_map' => ['reports' => 'reports', 'reports:mri' => 'mri'], 'fallback_profile' => 'wiki']), $this->storage, $this->index);

        self::assertSame('Revizuire.', $actions->specialUnder('reports', 'tags-review')?->prompt);

        $alone = new Actions($this->routed(['profile_map' => ['reports' => 'reports'], 'fallback_profile' => 'wiki']), $this->storage, $this->index);
        self::assertSame('Generic review.', $alone->specialUnder('reports', 'tags-review')?->prompt, 'the fallback\'s when no profile has one');
    }

    public function testTheProposedPromptsMakeARailWithAQuestionBoxForAsk(): void
    {
        (new DefaultPrompts($this->storage, \dirname(__DIR__, 3) . '/resources/prompts'))->install('reports', 'owner', target: DefaultPrompts::RADIOLOGY);
        $list = (new Actions($this->config(), $this->storage, $this->index))->forPage(self::PATH);

        self::assertSame(['ask', 'create', 'conclusion', 'diagnostic', 'gaps-traps', 'urgent', 'quality', 'rapno'], array_map(static fn (Action $a): string => $a->id, $list));
        self::assertSame(['show', 'replace', 'append', 'append', 'show', 'show', 'show', 'append'], array_map(static fn (Action $a): string => $a->result, $list));
        self::assertStringContainsString('{prompt}', $list[0]->prompt, 'Ask: the rail gives it a question box');
    }
}
