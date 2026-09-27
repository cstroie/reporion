<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Ai;

use Reporion\Cli\AiImportPromptsCommand;
use Reporion\Cli\Output;
use Reporion\Index\Sqlite;
use Reporion\Service\Ai\Actions;
use Reporion\Service\Ai\AiConfig;
use Reporion\Service\Ai\PromptImport;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * DokuLLM's prompt profile imported as assistant pages (phase 15b), and the
 * actions a report then offers.
 */
final class PromptImportTest extends StorageTestCase
{
    private FlatFile $storage;
    private Sqlite $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $this->storage = new FlatFile($this->dataRoot, $this->index);
        $p = static fn (string $title): array => ['title' => $title, 'visibility' => 'private'];
        // As the page import left it: the profile table collided with its namespace (-2), so did system
        $this->storage->create('dokullm:profiles:reports:conclusion', $p('c'), "<report>\n{text}\n</report>\nStructura: titlu „===== Concluzii =====\".\n<exemplu>\n## Concluzii\nNormal.\n</exemplu>\nReturnează în format DokuWiki.\n", 'owner');
        $this->storage->create('dokullm:profiles:reports:create', $p('cr'), "1. Titlu (======): numele complet al pacientului, din date.\n2. Corpul raportului din {text}.\n", 'owner');
        $this->storage->create('dokullm:profiles:reports:custom', $p('cu'), "{prompt}\n{text}\n", 'owner');
        $this->storage->create('dokullm:profiles:reports:quality', $p('q'), "Verifică {text}.\n", 'owner');
        $this->storage->create('dokullm:profiles:reports:system:quality', $p('sq'), "Ești auditor.\n", 'owner');
        $this->storage->create('dokullm:profiles:reports', $p('Radiology Reports Profile'), "# Radiology Reports Profile\n\n| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n| [[.:reports:create]] | Create | Create report | ✨ | insert |\n| [[.:reports:conclusion]] | Conclusion | Create conclusion | 🏁 | append |\n| [[.:reports:quality]] | Check | Review | ✔️ | show |\n\n## Disabled Actions\n\n| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n| [[.:reports:custom]] | Custom | Custom prompt | ✏️ | replace |\n\n## Recommended Actions (not implemented)\n\n| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n| [[.:reports:question]] | Question | Q | ❓ | show |\n", 'owner');
        $this->storage->create('dokullm:profiles:reports:system-2', $p('s'), "Ești radiolog. Markup DokuWiki permis exclusiv pentru titluri (nivelurile ====== și =====).\n", 'owner');
    }

    public function testTheProfileBecomesAssistantPagesWithTheirRailDetails(): void
    {
        $report = (new PromptImport($this->storage))->run('dokullm:profiles:reports', 'ai:profiles:reports', 'owner', false);

        self::assertSame([
            'ai:profiles:reports:create', 'ai:profiles:reports:conclusion', 'ai:profiles:reports:quality', 'ai:profiles:reports:custom',
            'ai:profiles:reports:system', 'ai:profiles:reports:system:quality',
        ], $report['created']);

        $conclusion = $this->storage->read('ai:profiles:reports:conclusion');
        self::assertSame(['label' => 'Conclusion', 'tooltip' => 'Create conclusion', 'icon' => '🏁', 'result' => 'append', 'order' => 20, 'enabled' => true], array_intersect_key($conclusion->frontmatter, array_flip(['label', 'tooltip', 'icon', 'result', 'order', 'enabled'])));
        self::assertStringContainsString('titlu „### Concluzii".', $conclusion->body, 'a DokuWiki heading instruction becomes Markdown');
        self::assertStringContainsString("<exemplu>\n### Concluzii\n", $conclusion->body, 'an example section is a ### section here');
        self::assertStringContainsString('în format Markdown.', $conclusion->body);
        self::assertStringNotContainsString('numele complet al pacientului', $this->storage->read('ai:profiles:reports:create')->body, 'never ask the model for the name');
        self::assertFalse($this->storage->read('ai:profiles:reports:custom')->frontmatter['enabled'], 'under "Disabled"');

        self::assertSame([['page' => 'ai:profiles:reports:system', 'line' => 1, 'text' => 'Ești radiolog. Markup DokuWiki permis exclusiv pentru titluri (nivelurile ====== și =====).']], $report['review']);

        $again = (new PromptImport($this->storage))->run('dokullm:profiles:reports', 'ai:profiles:reports', 'owner', false);
        self::assertSame([], $again['created'], 'nothing is overwritten');
        self::assertCount(6, $again['skipped']);
    }

    public function testTheReportOffersTheEnabledActionsInOrderWithTheirSystemPrompts(): void
    {
        (new PromptImport($this->storage))->run('dokullm:profiles:reports', 'ai:profiles:reports', 'owner', false);
        $config = new AiConfig(true, 'http://127.0.0.1:1/v1', 'm', 0.3, 0.8, 0, 30, ['reports' => 'reports', '*' => 'default'], [], false, '');
        $actions = new Actions($config, $this->storage, $this->index);

        $list = $actions->forPage('reports:mri:mioveni:260927-test');
        self::assertSame(['create', 'conclusion', 'quality'], array_map(static fn ($a): string => $a->id, $list), 'custom is disabled; system is not an action');
        self::assertSame('insert', $list[0]->result);
        self::assertStringStartsWith('Ești radiolog.', $list[2]->system);
        self::assertStringEndsWith('Ești auditor.', $list[2]->system, 'the action\'s own system appendage');
        self::assertSame('Ești radiolog. Markup DokuWiki permis exclusiv pentru titluri (nivelurile ====== și =====).', $list[1]->system);
        self::assertNull($actions->find('reports:mri:mioveni:260927-test', 'custom'));

        self::assertSame([], $actions->forPage('docs:note'), 'the default profile has no pages yet');
        $off = new Actions(new AiConfig(false, 'http://127.0.0.1:1/v1', 'm', 0.3, 0.8, 0, 30, ['reports' => 'reports'], [], false, ''), $this->storage, $this->index);
        self::assertSame([], $off->forPage('reports:mri:mioveni:260927-test'), 'no actions while the assistant is off');
    }

    public function testADryRunWritesNothing(): void
    {
        $report = (new PromptImport($this->storage))->run('dokullm:profiles:reports', 'ai:profiles:reports', 'owner', true);

        self::assertCount(6, $report['created']);
        self::assertNull($this->index->findByPath('ai:profiles:reports:conclusion', null));
    }

    public function testTheCommandTakesItsOptionsWithOrWithoutEquals(): void
    {
        $command = new AiImportPromptsCommand(new PromptImport($this->storage));
        foreach ([
            ['--from', 'dokullm:profiles:reports', '--to', 'ai:profiles:reports', '--dry-run'],
            ['--from=dokullm:profiles:reports', '--to=ai:profiles:reports', '--dry-run'],
        ] as $args) {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            self::assertNotFalse($stdout);
            self::assertNotFalse($stderr);
            self::assertSame(0, $command->run($args, new Output($stdout, $stderr)), implode(' ', $args));
            rewind($stdout);
            self::assertStringContainsString('would create ai:profiles:reports:conclusion', (string) stream_get_contents($stdout));
        }
        self::assertNull($this->index->findByPath('ai:profiles:reports:conclusion', null), 'a dry run');
    }
}
