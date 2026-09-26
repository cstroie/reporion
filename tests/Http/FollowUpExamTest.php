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
use Reporion\Storage\FlatFile;
use Reporion\Tests\Support\CnpTest;

/**
 * A new exam for the same patient (roadmap phase 9): the guided form,
 * prefilled from a report reached by its pid, with that report as the
 * prior. Made-up patient; the CNP is generated (invariant 10).
 */
final class FollowUpExamTest extends HttpTestCase
{
    private const PREVIOUS = 'reports:mri:mioveni:250101-test-subject';

    private string $cnp;

    protected function setUp(): void
    {
        parent::setUp();
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('owner', password_hash('x', PASSWORD_ARGON2ID), true);
        $users->create('docs-editor', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('docs', GrantRole::Editor)]);
        $this->config['sites'] = ['mioveni' => ['name' => 'Spital Test', 'devices' => ['MV-MR-01' => 'Aparat RM']]];
        $this->cnp = CnpTest::make(2, '800115');
        $this->storage()->create(self::PREVIOUS, [
            'title' => 'TEST SUBJECT', 'visibility' => 'private', 'modality' => ['MR', 'CT'], 'region' => ['neuro', 'spine'],
            'site' => 'mioveni', 'device' => 'MV-MR-01', 'study_date' => '2025-01-01', 'referrer' => 'dr. Test',
            'summary' => 'Leziuni demielinizante stabile.',
            'patient' => ['name' => 'TEST SUBJECT', 'sex' => 'F', 'born' => 1980, 'cnp' => $this->cnp],
        ], "# TEST SUBJECT\n", 'owner');
    }

    public function testTheFormIsPrefilledFromThePreviousReport(): void
    {
        $body = $this->get('owner', '/new?after=' . $this->pid(self::PREVIOUS))->body;

        self::assertStringContainsString('name="name" value="TEST SUBJECT"', $body);
        self::assertStringContainsString('name="cnp" value="' . $this->cnp . '"', $body);
        self::assertStringContainsString('name="date" value="' . date('Y-m-d') . '"', $body, 'today');
        self::assertStringContainsString('<option value="MR" data-ns="mri" selected>', $body, 'the first modality');
        self::assertStringContainsString('value="mioveni" selected', $body);
        self::assertStringContainsString('value="MV-MR-01" data-site="mioveni" selected', $body);
        self::assertStringContainsString('value="neuro" checked', $body);
        self::assertStringContainsString('value="spine" checked', $body);
        self::assertStringContainsString('name="referrer" value="dr. Test"', $body);
        self::assertStringContainsString('Leziuni demielinizante stabile.</textarea>', $body, 'the summary, as it is, into the indication');
        self::assertStringContainsString('name="priors[]" value="' . self::PREVIOUS . '" checked', $body);
    }

    public function testTheNewReportListsThePreviousOneAsItsPrior(): void
    {
        $created = $this->post('owner', $this->fields() + ['priors' => [self::PREVIOUS]]);
        self::assertSame(302, $created->status);
        $path = substr(explode('/edit', $created->headers['Location'])[0], 1);

        $page = $this->storage()->read($path);
        self::assertSame([self::PREVIOUS], $page->frontmatter['priors']);
        self::assertSame('Leziuni demielinizante stabile.', $page->frontmatter['indication']);
        self::assertStringContainsString('>' . $path . '</a>', $this->get('owner', '/' . self::PREVIOUS)->body, "in the previous report's backlinks");
    }

    public function testAnUntickedPriorIsLeftOutAndAForgedOneIsDropped(): void
    {
        $this->post('owner', $this->fields());
        $page = $this->storage()->read('reports:mri:mioveni:' . date('ymd') . '-test-subject');
        self::assertArrayNotHasKey('priors', $page->frontmatter);

        $forged = $this->post('owner', ['confirm_same_day' => '1', 'priors' => ['docs:not-a-report', 'reports:mri:mioveni:991231-nobody']] + $this->fields());
        $path = substr(explode('/edit', $forged->headers['Location'])[0], 1);
        self::assertArrayNotHasKey('priors', $this->storage()->read($path)->frontmatter);
    }

    public function testOnlyAReadableReportByPidStartsOne(): void
    {
        $this->storage()->create('docs:note', ['title' => 'Note', 'visibility' => 'private'], "x\n", 'owner');

        self::assertSame(404, $this->get('owner', '/new?after=' . $this->pid('docs:note'))->status, 'not a report');
        self::assertSame(404, $this->get('owner', '/new?after=01NOSUCHPID0000000000000000')->status);
        self::assertSame(404, $this->get('docs-editor', '/new?after=' . $this->pid(self::PREVIOUS))->status, 'cannot read it');
    }

    public function testTheActionsLinkByPidFromTheReportAndTheTimeline(): void
    {
        $pid = $this->pid(self::PREVIOUS);
        $view = $this->get('owner', '/' . self::PREVIOUS)->body;
        self::assertStringContainsString('/new?after=' . $pid . '"', $view);
        self::assertStringNotContainsString('/new?after=reports', $view, 'never the path');
        self::assertStringContainsString('/new?after=' . $pid . '"', $this->get('owner', '/' . self::PREVIOUS . '/timeline')->body);

        $this->storage()->create('docs:note', ['title' => 'Note', 'visibility' => 'private'], "x\n", 'owner');
        self::assertStringNotContainsString('/new?after=', $this->get('owner', '/docs:note')->body, 'not on other pages');
    }

    public function testAnImportedReportWithoutCnpOrBirthYearPrefillsCleanly(): void
    {
        $this->storage()->create('reports:mri:mioveni:200101-old-import', [
            'title' => 'RM', 'visibility' => 'private', 'modality' => ['MR'], 'site' => 'mioveni',
            'patient' => ['name' => 'OLD IMPORT', 'born' => null, 'sex' => null, 'cnp' => null],
        ], "Text.\n", 'owner');

        $body = $this->get('owner', '/new?after=' . $this->pid('reports:mri:mioveni:200101-old-import'))->body;

        self::assertStringContainsString('name="name" value="OLD IMPORT"', $body);
        self::assertStringContainsString('name="cnp" value=""', $body);
        self::assertStringNotContainsString('wk-field-err', $body, 'no complaints before the first submit');
    }

    /** @return array<string, mixed> */
    private function fields(): array
    {
        return ['action' => 'create', 'name' => 'TEST SUBJECT', 'cnp' => $this->cnp, 'date' => date('Y-m-d'), 'modality' => 'MR', 'site' => 'mioveni', 'indication' => 'Leziuni demielinizante stabile.'];
    }

    private function pid(string $path): string
    {
        return $this->storage()->read($path)->pid;
    }

    /** @param array<string, mixed> $fields */
    private function post(string $user, array $fields): Response
    {
        return Kernel::boot($this->config)->handle(new Request('POST', '/new', cookies: ['reporion' => $this->cookie($user)], body: http_build_query(['guided' => '1'] + $fields)));
    }

    private function get(string $user, string $uri): Response
    {
        [$path, $query] = array_pad(explode('?', $uri, 2), 2, '');
        parse_str($query, $params);

        return Kernel::boot($this->config)->handle(new Request('GET', $path, query: array_map('strval', $params), cookies: ['reporion' => $this->cookie($user)]));
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
