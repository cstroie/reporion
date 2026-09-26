<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Http\Request;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Storage\FlatFile;

/**
 * The editor toolbar's server half (phase 10): what Insert prior study and
 * Insert template are offered, through the island config — the same
 * visibility predicate as the timeline, never a new listing.
 */
final class EditorToolbarTest extends HttpTestCase
{
    private const PAGE = 'reports:mri:mioveni:260920-ionescu-maria';
    private const PRIOR = 'reports:mri:mioveni:250312-ionescu-maria';
    private const HIDDEN = 'reports:ct:mioveni:240101-ionescu-maria';
    private const PATIENT = ['name' => 'Ionescu Maria', 'born' => 1974, 'sex' => 'F', 'cnp' => '2740101123456'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        (new FlatFileUserStore($this->dataRoot))->create('mihai', 'x', false, [new Grant('reports:mri', GrantRole::Editor), new Grant('templates:mri', GrantRole::Viewer)]);

        $storage = $this->storage();
        $storage->create(self::PAGE, ['title' => 'Ionescu Maria', 'exam_title' => 'RM cerebral', 'visibility' => 'private', 'modality' => ['MR'], 'study_date' => '2026-09-20', 'template' => 'templates:mri:lombar', 'patient' => self::PATIENT], "# Ionescu Maria\n\nText.", 'owner');
        $storage->create(self::PRIOR, ['title' => 'Ionescu Maria', 'exam_title' => 'RM lombar', 'visibility' => 'private', 'modality' => ['MR'], 'study_date' => '2025-03-12', 'patient' => self::PATIENT], 'Text.', 'owner');
        $storage->create(self::HIDDEN, ['title' => 'Ionescu Maria', 'exam_title' => 'CT torace', 'visibility' => 'private', 'modality' => ['CT'], 'study_date' => '2024-01-01', 'patient' => self::PATIENT], 'Text.', 'owner');
        // Not a report, even with a patient block
        $storage->create('reports:mri:mioveni:despre', ['title' => 'Mioveni', 'visibility' => 'private', 'patient' => self::PATIENT], 'Site.', 'owner');
        $storage->create('templates:mri:lombar', ['title' => 'IRM coloana lombara', 'visibility' => 'private'], 'Tehnica.', 'owner');
        $storage->create('templates:ct:torace', ['title' => 'CT torace', 'visibility' => 'private'], 'Tehnica.', 'owner');
    }

    public function testPriorsAreThePatientsOtherReportsTheCallerCanRead(): void
    {
        $config = $this->editorConfig('mihai', self::PAGE);

        self::assertSame([[
            'path' => self::PRIOR,
            'label' => 'RM lombar',
            'date' => '12.03.2025',
            'modality' => 'MR',
        ]], $config['priors'], 'not the page itself, not a CT report without a grant, not a site page');

        $owner = $this->editorConfig('owner', self::PAGE);
        self::assertSame([self::PRIOR, self::HIDDEN], array_column($owner['priors'], 'path'));
    }

    public function testTemplatesAreTheReportsModalityNamespace(): void
    {
        $config = $this->editorConfig('owner', self::PAGE);

        self::assertSame([['path' => 'templates:mri:lombar', 'title' => 'IRM coloana lombara']], $config['templates']);
        self::assertSame('templates:mri:lombar', $config['template']);
    }

    public function testTheToolbarShowsItsButtonsAndHidesEmptyPickers(): void
    {
        $body = $this->edit('owner', 'reports:mri:mioveni:despre')->body;

        foreach (['heading', 'bold', 'italic', 'bullets', 'numbers', 'table', 'code', 'link', 'image', 'template', 'copy'] as $action) {
            self::assertStringContainsString('data-tb="' . $action . '"', $body, $action);
        }
        self::assertStringNotContainsString('data-tb="prior"', $body, 'a page that is not a report has no priors');
        self::assertStringContainsString('js/editor-format.js', $body);
    }

    /** @return array<string, mixed> */
    private function editorConfig(string $username, string $path): array
    {
        $response = $this->edit($username, $path);
        self::assertSame(200, $response->status);
        self::assertSame(1, preg_match('#<script type="application/json" id="editor-config">(.*?)</script>#s', $response->body, $m));

        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    }

    private function edit(string $username, string $path): \Reporion\Http\Response
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($username);

        return Kernel::boot($this->config)->handle(new Request('GET', '/' . $path . '/edit', cookies: ['reporion' => $cookie]));
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }
}
