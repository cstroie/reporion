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

/**
 * Snippets (phase 11): which ones the editor offers — shared ones
 * everywhere, a modality's in its reports winning by name, only what the
 * caller can read, never deeper pages, never in the template picker.
 */
final class EditorSnippetsTest extends HttpTestCase
{
    private const MR = 'reports:mri:mioveni:260920-popescu-ana';
    private const CT = 'reports:ct:mioveni:260920-popescu-ana';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        (new FlatFileUserStore($this->dataRoot))->create('mihai', 'x', false, [
            new Grant('reports', GrantRole::Editor),
            new Grant('guides', GrantRole::Editor),
            new Grant('templates:snippets:mri', GrantRole::Viewer),
        ]);

        $s = $this->storage();
        foreach ([self::MR, self::CT] as $path) {
            $s->create($path, ['title' => 'Popescu Ana', 'visibility' => 'private'], 'Text.', 'owner');
        }
        $s->create('guides:lombar', ['title' => 'Ghid', 'visibility' => 'private'], 'Text.', 'owner');
        $s->create('templates:snippets:norm', ['title' => 'Normal (general)', 'visibility' => 'public'], "Fara modificari.\n", 'owner');
        $s->create('templates:snippets:rec', ['title' => 'Recomandare', 'visibility' => 'private'], 'Recomand $0 control.', 'owner');
        $s->create('templates:snippets:mri:norm', ['title' => 'Normal RM', 'visibility' => 'private'], 'IRM fara modificari.', 'owner');
        $s->create('templates:snippets:mri:deeper:x', ['title' => 'Deeper', 'visibility' => 'private'], 'No.', 'owner');
        $s->create('templates:mri:cerebral', ['title' => 'IRM cerebral', 'visibility' => 'private'], 'Tehnica.', 'owner');
    }

    public function testAModalitySnippetWinsInItsReportsAndSharedOnesAreEverywhere(): void
    {
        $mr = $this->config('owner', self::MR);
        self::assertSame([
            ['name' => 'norm', 'title' => 'Normal RM', 'body' => 'IRM fara modificari.', 'modality' => true],
            ['name' => 'rec', 'title' => 'Recomandare', 'body' => 'Recomand $0 control.', 'modality' => false],
        ], $mr['snippets']);

        self::assertSame(['Fara modificari.', 'Recomand $0 control.'], array_column($this->config('owner', self::CT)['snippets'], 'body'), 'no MR snippets in a CT report');
        self::assertSame(['norm', 'rec'], array_column($this->config('owner', 'guides:lombar')['snippets'], 'name'), 'shared only on other pages');
    }

    public function testOnlySnippetsTheCallerCanReadAreOffered(): void
    {
        // mihai reads the MR snippets (a grant) and the public shared one, not the private shared one
        $snippets = $this->config('mihai', self::MR)['snippets'];
        self::assertSame(['norm'], array_column($snippets, 'name'));
        self::assertSame('IRM fara modificari.', $snippets[0]['body']);
        self::assertSame(['norm'], array_column($this->config('mihai', self::CT)['snippets'], 'name'), 'the public shared one');
    }

    public function testSnippetsStayOutOfTheTemplatePickerAndShowTheButton(): void
    {
        $guide = $this->config('owner', 'guides:lombar');
        self::assertSame(['templates:mri:cerebral'], array_column($guide['templates'], 'path'));

        self::assertStringContainsString('data-tb="snippets"', $this->edit('owner', self::MR)->body);
    }

    /** @return array<string, mixed> */
    private function config(string $username, string $path): array
    {
        $response = $this->edit($username, $path);
        self::assertSame(200, $response->status, $path);
        self::assertSame(1, preg_match('#<script type="application/json" id="editor-config">(.*?)</script>#s', $response->body, $m));

        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    }

    private function edit(string $username, string $path): Response
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($username);

        return Kernel::boot($this->config)->handle(new Request('GET', '/' . $path . '/edit', cookies: ['reporion' => $cookie]));
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }
}
