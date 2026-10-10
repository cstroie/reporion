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
use Reporion\Storage\FlatFile;
use Reporion\Tests\Http\HttpTestCase;

/**
 * The revision note a Save writes when *What changed?* is left empty
 * (2026-10-10): the `commit` prompt on the lite alias, given the change as
 * {diff}, against the fake OpenAI-compatible server.
 */
final class CommitNoteTest extends HttpTestCase
{
    private const PATH = 'reports:mri:mioveni:260927-popescu-ana';

    private const BODY = "# POPESCU Ana\n\n## IRM genunchi\n\n### Descriere\n\nMenisc medial fisurat. Ligamente încrucișate intacte.\n\n### Concluzii\n\nFisură de menisc medial, grad 2.\n";

    private FakeServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new FakeServer();
        // Retries configured, so a test can tell that the note's call makes none
        $this->config['ai'] = ['enabled' => true, 'servers' => [['endpoint' => $this->server->url, 'tiers' => ['normal' => ['model' => 'normal-model'], 'lite' => ['model' => 'test-model']]]], 'retry_delays' => [0, 0, 0]];
        $this->createOwner();
        (new FlatFileUserStore($this->dataRoot))->create('mihai', 'x', false, [new Grant('reports:mri', GrantRole::Editor)]);
        $storage = $this->storage();
        $storage->create('ai:profiles:reports', ['title' => 'Reports profile', 'visibility' => 'private'], "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n| conclusion | Conclusion | Write the conclusion | file-text | append |\n", 'owner');
        $storage->create('ai:profiles:reports:system', ['title' => 'System', 'visibility' => 'private'], "Ești radiolog.\n", 'owner');
        $storage->create('ai:profiles:reports:conclusion', ['title' => 'Conclusion', 'visibility' => 'private'], "{text}\n", 'owner');
        $storage->create('ai:profiles:reports:commit', ['title' => 'Commit', 'visibility' => 'private'], "Summarize the following diff as a single short commit message.\n\nDIFF:\n{diff}\n", 'owner');
        $storage->create(self::PATH, ['title' => 'POPESCU Ana', 'visibility' => 'private', 'patient' => ['name' => 'POPESCU Ana', 'cnp' => '2800115123458']], self::BODY, 'owner');
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        parent::tearDown();
    }

    public function testAnEmptyNoteIsWrittenFromTheDiffAndSaidAsAssisted(): void
    {
        $response = $this->save(['body' => str_replace('grad 2', 'grad 3', self::BODY)]);

        self::assertSame(302, $response->status);
        self::assertSame('Concluzie: fără leziuni. · assisted: commit', $this->lastNote());
        self::assertStringContainsString('"assisted":["commit"]', $this->audit());
        self::assertStringContainsString('"ai_action":"commit"', $this->audit());

        $sent = $this->server->lastRequest()['body'];
        self::assertSame('test-model', $sent['model'], 'the lite alias when the page names none');
        $user = $sent['messages'][0]['content'];
        self::assertStringContainsString("@@ ### Concluzii\n Fisură de menisc medial, grad [-2.-]{+3.+}", $user);
        self::assertStringNotContainsString('Ligamente', $user, 'no unchanged paragraph');
        self::assertStringNotContainsString('patient:', $user, 'no patient header for a note');
    }

    public function testANameCorrectedInTheSameSaveGoesOutUnderNeitherSpelling(): void
    {
        $document = "---\ntitle: 'POPESCU Ioana'\nvisibility: private\npatient:\n  name: 'POPESCU Ioana'\n  cnp: '2800115123458'\n---\n\n"
            . str_replace(['POPESCU Ana', 'Menisc medial fisurat.'], ['POPESCU Ioana', 'Menisc medial fisurat la Ana.'], self::BODY);
        self::assertSame(302, $this->save(['document' => $document])->status);

        $sent = json_encode($this->server->lastRequest()['body'], JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('[pacient]', $sent);
        foreach (['Popescu', 'POPESCU', 'Ioana', 'Ana', '2800115123458', 'reports:'] as $identifier) {
            self::assertStringNotContainsString($identifier, $sent, $identifier);
        }
        self::assertStringEndsWith('· assisted: commit', (string) $this->lastNote());
    }

    public function testNothingIsAskedWhenThereIsNoNoteToWrite(): void
    {
        $changed = str_replace('grad 2', 'grad 3', self::BODY);
        // The note expected on the newest revision; null: not checked
        $cases = [
            'a minor edit' => [['body' => $changed, 'minor' => '1'], null],
            'a typed note' => [['body' => self::BODY, 'note' => 'gradul'], 'gradul'],
            'the body unchanged' => [['body' => self::BODY], null],
        ];
        foreach ($cases as $case => [$fields, $note]) {
            $this->server->stop();
            $this->server = new FakeServer();
            $this->config['ai']['servers'][0]['endpoint'] = $this->server->url;
            self::assertSame(302, $this->save($fields + ['base_rev' => (string) $this->storage()->read(self::PATH)->rev])->status, $case);
            self::assertNull($this->server->lastRequest()['body'], $case . ': nothing sent');
            if ($note !== null) {
                self::assertSame($note, $this->lastNote(), $case);
            }
        }
    }

    public function testASaveAboutToConflictSendsNothing(): void
    {
        $this->storage()->save(self::PATH, $this->storage()->read(self::PATH)->frontmatter, self::BODY . "\nAltceva.\n", 1, 'owner');
        $response = $this->save(['body' => str_replace('grad 2', 'grad 3', self::BODY), 'base_rev' => '1']);

        self::assertSame(200, $response->status, 'the conflict screen');
        self::assertNull($this->server->lastRequest()['body']);
    }

    public function testWithNoCommitPageTheNoteStaysEmpty(): void
    {
        $this->storage()->save('ai:profiles:reports:commit', ['title' => 'Commit', 'visibility' => 'private'], '', 1, 'owner');
        self::assertSame(302, $this->save(['body' => str_replace('grad 2', 'grad 3', self::BODY)])->status);
        self::assertNull($this->server->lastRequest()['body']);
        self::assertNull($this->lastNote());
    }

    public function testAFailingServerStillSavesOnceAskedWithoutANote(): void
    {
        $this->config['ai']['servers'][0]['tiers']['lite']['model'] = 'fail-401';
        self::assertSame(302, $this->save(['body' => str_replace('grad 2', 'grad 3', self::BODY)])->status);
        self::assertNull($this->lastNote());
        self::assertSame(2, $this->storage()->read(self::PATH)->rev, 'the revision is written');
        self::assertSame(1, substr_count($this->audit(), '"reason":"unauthorized"'), 'one try, no retries');
    }

    public function testThePromptPagesTimeoutBoundsTheWait(): void
    {
        $this->storage()->save('ai:profiles:reports:commit', ['title' => 'Commit', 'visibility' => 'private', 'timeout' => 1], "{diff}\n", 1, 'owner');
        $this->config['ai']['servers'][0]['tiers']['lite']['model'] = 'slow';
        $start = microtime(true);
        self::assertSame(302, $this->save(['body' => str_replace('grad 2', 'grad 3', self::BODY)])->status);
        self::assertLessThan(2.5, microtime(true) - $start, 'not the 3 s the server takes');
        self::assertNull($this->lastNote());
        // Cut off before the server's headers came: PHP's wrapper says unreachable
        self::assertMatchesRegularExpression('/"outcome":"error","ai_action":"commit".*"reason":"(timeout|unreachable)"/', $this->audit());
    }

    public function testRailActionsAndTheNoteAreSaidTogether(): void
    {
        $this->save(['body' => str_replace('grad 2', 'grad 3', self::BODY), 'ai_assisted' => 'conclusion']);
        self::assertSame('Concluzie: fără leziuni. · assisted: conclusion, commit', $this->lastNote());
    }

    /** @param array<string, mixed> $fields */
    private function save(array $fields): Response
    {
        $fields += ['base_rev' => '1', 'note' => ''];

        return Kernel::boot($this->config)->handle(new Request('POST', '/' . self::PATH . '/edit', query: isset($fields['document']) ? ['raw' => '1'] : [], cookies: ['reporion' => $this->cookie('mihai')], body: http_build_query($fields)));
    }

    private function lastNote(): ?string
    {
        $revlog = $this->storage()->read(self::PATH)->meta['revlog'];

        return $revlog[array_key_last($revlog)]['note'];
    }

    private function audit(): string
    {
        return (string) @file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
    }

    private function cookie(string $user): string
    {
        return (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user);
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }
}
