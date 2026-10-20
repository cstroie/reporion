<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Storage\FileTree;

/**
 * POST /api/v1/recall (D50): passages of finished reports for the line just
 * written, the caller's own first, never this patient's, never a page
 * path; and what several of them say that the text in front does not.
 * Fixture data is fictitious (invariant 10).
 */
final class RecallTest extends HttpTestCase
{
    private const PATH = 'reports:mri:mioveni:261018-test-curent';
    private const LINE = 'Multiple leziuni de substanță albă';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner(radiologist: false);
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('mihai', 'x', false, [new Grant('reports:mri', GrantRole::Editor)]);
        $this->makeRadiologist('mihai');
        $users->create('dan', 'x', false, [new Grant('reports:mri', GrantRole::Editor)]);
        $this->makeRadiologist('dan');
        $users->create('ana', 'x', false, [new Grant('reports:mri:mioveni', GrantRole::Editor)]);
        $this->makeRadiologist('ana');

        $storage = $this->storage();
        $report = static fn (string $path, string $cnp, string $status, string $findings, string $by) => $storage->create(
            $path,
            ['visibility' => 'private', 'status' => $status, 'modality' => ['MR'], 'study_date' => '2026-01-10', 'patient' => ['name' => 'TEST ' . $cnp, 'cnp' => $cnp]],
            "# TEST {$cnp}\n\n## IRM cerebral\n\n### Descriere\n\n{$findings}\n\n### Concluzii\n\nConcluzie.\n",
            $by,
        );
        $report('reports:mri:mioveni:260110-test-dan', '1900101000002', 'archived', 'Leziuni de substanță albă periventriculare, hiperintense FLAIR, fără efect de masă, fără restricție de difuzie.', 'dan');
        $report('reports:mri:mioveni:260111-test-mihai', '1900101000003', 'archived', 'Leziuni multiple de substanță albă subcorticale, hiperintense FLAIR, fără efect de masă.', 'mihai');
        $report('reports:mri:campulung:260112-test-alt', '1900101000004', 'archived', 'Leziuni de substanță albă la Câmpulung, fără efect de masă.', 'dan');
        $report('reports:mri:mioveni:260113-test-ciorna', '1900101000005', 'draft', 'Leziuni de substanță albă într-o ciornă, fără efect de masă.', 'dan');
        $report('reports:mri:mioveni:250101-test-curent', '1900101000001', 'archived', 'Leziuni de substanță albă la același pacient.', 'mihai');
        $storage->create(self::PATH, ['visibility' => 'private', 'modality' => ['MR'], 'patient' => ['name' => 'TEST 1900101000001', 'cnp' => '1900101000001']], "# TEST 1900101000001\n\n## IRM cerebral\n\n" . self::LINE . "\n", 'mihai');
    }

    public function testOwnFirstFinishedReadableOtherPatientsOnly(): void
    {
        $response = $this->call('mihai', ['path' => self::PATH, 'text' => self::LINE, 'written' => self::LINE]);

        self::assertSame(200, $response->status);
        $data = json_decode($response->body, true)['data'];
        $texts = array_column($data['passages'], 'text');
        self::assertCount(3, $texts, 'one passage from each finished report of another patient, Câmpulung included for this grant');
        self::assertStringContainsString('subcorticale', $texts[0], 'the caller\'s own first');
        self::assertTrue($data['passages'][0]['mine']);
        self::assertFalse($data['passages'][1]['mine']);
        $all = implode("\n", $texts);
        self::assertStringNotContainsString('același pacient', $all, 'never this patient\'s');
        self::assertStringNotContainsString('ciornă', $all, 'finished reports only');
        self::assertStringContainsString('<mark>Leziuni</mark>', $data['passages'][0]['html']);
        self::assertMatchesRegularExpression('#^/r/[0-9A-Z]{26}/1$#', $data['passages'][0]['href']);
        self::assertSame('Descriere', $data['passages'][0]['heading']);
        self::assertStringNotContainsString('reports:', $response->body, 'never a page path (invariant 8)');
        self::assertStringNotContainsString('TEST', $response->body, 'never the name heading');
        self::assertSame('efect de masă', $data['missing'][0]);
        self::assertContains('hiperintense FLAIR', $data['missing'], 'a phrase over its words');
    }

    public function testOnlyWhatTheCallerMayReadAndAlreadyWrittenIsNotMissing(): void
    {
        $data = json_decode($this->call('ana', ['path' => self::PATH, 'text' => self::LINE, 'written' => self::LINE . ', fără efect de masă'])->body, true)['data'];

        self::assertStringNotContainsString('Câmpulung', implode("\n", array_column($data['passages'], 'text')), 'invariant 6');
        self::assertFalse($data['passages'][0]['mine'] && $data['passages'][1]['mine']);
        self::assertNotContains('efect de masă', $data['missing'], 'written already');
        self::assertContains('hiperintense FLAIR', $data['missing'], 'a phrase over its words');
    }

    public function testATooShortLineRecallsNothing(): void
    {
        $data = json_decode($this->call('mihai', ['path' => self::PATH, 'text' => 'albă'])->body, true)['data'];

        self::assertSame(['passages' => [], 'missing' => []], $data);
    }

    public function testOnlyARadiologistWhoMayWriteTheReport(): void
    {
        $body = ['path' => self::PATH, 'text' => self::LINE];
        self::assertSame(404, $this->call(null, $body)->status);
        self::assertSame(403, $this->call('owner', $body)->status, 'reads it, no radiologist');
        self::assertSame('not_radiologist', json_decode($this->call('owner', $body)->body, true)['error']['code']);
        self::assertSame(404, $this->call('mihai', ['path' => 'reports:mri:mioveni:nope'] + $body)->status);
        self::assertSame(404, $this->call('mihai', ['path' => 'site:home'] + $body)->status, 'reports only');
        self::assertSame(413, $this->call('mihai', ['text' => str_repeat('x', 1001)] + $body)->status);
    }

    public function testTheEditorShowsRecallOnAReportItsRadiologistMayWrite(): void
    {
        $edit = fn (string $user, string $path): string => Kernel::boot($this->config)->handle(new Request('GET', '/' . $path . '/edit', cookies: ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user)]))->body;

        $page = $edit('mihai', self::PATH);
        self::assertStringContainsString('data-rail="recall"', $page);
        self::assertStringContainsString('js/editor-recall.js', $page);
        self::assertStringContainsString('"recall":{"expand":false,"descriptors":false,', $page, 'no reserved prompt pages: no AI buttons');
        self::assertStringNotContainsString('data-recall-ai=', $page);

        $this->storage()->create('users:mihai:notes', ['visibility' => 'private'], "# Note\n\nText.\n", 'mihai');
        self::assertStringNotContainsString('data-rail="recall"', $edit('mihai', 'users:mihai:notes'), 'reports only');
    }

    /** @param array<string, mixed> $body */
    private function call(?string $user, array $body): Response
    {
        $cookies = $user !== null ? ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user)] : [];

        return Kernel::boot($this->config)->handle(new Request('POST', '/api/v1/recall', cookies: $cookies, body: (string) json_encode($body)));
    }

    private function storage(): FileTree
    {
        return new FileTree($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }
}
