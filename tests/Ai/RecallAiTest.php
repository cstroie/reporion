<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Ai;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Storage\FileTree;
use Reporion\Tests\Http\HttpTestCase;

/**
 * Recall's assistant buttons (D50): the reserved `recall-expand` and
 * `recall-missing` prompts get one line as {text} and its recalled passages as {recall},
 * de-identified, against the fake OpenAI-compatible server. Fixture data
 * is fictitious (invariant 10).
 */
final class RecallAiTest extends HttpTestCase
{
    private const PATH = 'reports:mri:mioveni:261018-test-curent';
    private const LINE = 'Multiple leziuni de substanță albă';

    private FakeServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new FakeServer();
        $this->config['ai'] = ['enabled' => true, 'servers' => [['endpoint' => $this->server->url, 'tiers' => ['normal' => ['model' => 'normal-model'], 'lite' => ['model' => 'lite-model']]]], 'retry_delays' => [0, 0, 0]];
        $this->createOwner();
        (new FlatFileUserStore($this->dataRoot))->create('mihai', 'x', false, [new Grant('reports:mri', GrantRole::Editor)]);
        $this->makeRadiologist('mihai');
        $storage = $this->storage();
        $storage->create('ai:profiles:reports', ['visibility' => 'private'], "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n| conclusion | Conclusion | Write the conclusion | file-text | append |\n", 'owner');
        $storage->create('ai:profiles:reports:conclusion', ['visibility' => 'private'], "{text}\n", 'owner');
        $storage->create('ai:profiles:reports:special:recall-expand', ['visibility' => 'private'], "<pasaje>\n{recall}\n</pasaje>\n<rand>{text}</rand>\nDezvoltă rândul.\n", 'owner');
        $storage->create('ai:profiles:reports:special:recall-missing', ['visibility' => 'private'], "{recall}\n{text}\nCe lipsește?\n", 'owner');
        $storage->create(
            'reports:mri:mioveni:260110-ionescu-vasile',
            ['visibility' => 'private', 'status' => 'archived', 'modality' => ['MR'], 'patient' => ['name' => 'IONESCU Vasile', 'cnp' => '1900101000002']],
            "# IONESCU Vasile\n\n## IRM cerebral\n\n### Descriere\n\nLeziuni de substanță albă periventriculare la pacientul Ionescu, hiperintense FLAIR, fără efect de masă.\n",
            'mihai',
        );
        $storage->create(self::PATH, ['visibility' => 'private', 'modality' => ['MR'], 'patient' => ['name' => 'POPESCU Ana', 'cnp' => '2800115123458']], "# POPESCU Ana\n\n## IRM cerebral\n\n" . self::LINE . "\n", 'mihai');
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        parent::tearDown();
    }

    public function testExpandGetsTheLineAndItsRecalledPassagesDeIdentified(): void
    {
        $response = $this->complete('recall-expand');

        self::assertSame(200, $response->status);
        $json = json_decode($response->body, true);
        self::assertContains('1 recalled', $json['context']);
        $sent = $this->server->lastRequest()['body'];
        self::assertSame('normal-model', $sent['model']);
        $user = $sent['messages'][1]['content'];
        self::assertStringContainsString('<exemplu id="1">', $user);
        self::assertStringContainsString('hiperintense FLAIR, fără efect de masă', $user);
        self::assertStringContainsString('<rand>' . self::LINE . '</rand>', $user);
        foreach (['Ionescu', 'IONESCU', 'Vasile', 'POPESCU', 'reports:'] as $identifier) {
            self::assertStringNotContainsString($identifier, $user, $identifier);
        }
    }

    public function testDescriptorsRunsOnTheLiteAlias(): void
    {
        self::assertSame(200, $this->complete('recall-missing')->status);
        self::assertSame('lite-model', $this->server->lastRequest()['body']['model']);
    }

    public function testTheEditorShowsTheButtonsWhenThePromptPagesExist(): void
    {
        $page = Kernel::boot($this->config)->handle(new Request('GET', '/' . self::PATH . '/edit', cookies: ['reporion' => $this->cookie()]))->body;

        self::assertStringContainsString('data-recall-ai="recall-expand"', $page);
        self::assertStringContainsString('data-recall-ai="recall-missing"', $page);
        self::assertStringContainsString('id="editor-ai-assisted"', $page, 'Replace line goes into the save\'s note');
    }

    private function complete(string $action): Response
    {
        return Kernel::boot($this->config)->handle(new Request('POST', '/api/v1/ai/complete', cookies: ['reporion' => $this->cookie()], body: (string) json_encode(['path' => self::PATH, 'action' => $action, 'text' => self::LINE, 'label' => 'line'])));
    }

    private function cookie(): string
    {
        return (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('mihai');
    }

    private function storage(): FileTree
    {
        return new FileTree($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }
}
