<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Storage\FlatFile;

/**
 * Roadmap phase 25 end to end: the report view's side column and the
 * editor's rail list the template's reference pages; a template's Details
 * panel edits the list; Admin → Settings sets where the picker looks.
 */
final class ReferencesTest extends HttpTestCase
{
    private const REPORT = 'reports:ct:mioveni:261001-test-unu';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        $storage = new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
        $storage->create('radiology:spine:tlics', ['title' => 'TLICS', 'visibility' => 'private', 'summary' => 'Thoracolumbar injury score.'], "x\n", 'owner');
        $storage->create('teaching:spine:ao', ['title' => 'AO in teaching', 'visibility' => 'private'], "x\n", 'owner');
        $storage->create('templates:ct:coloana', ['title' => 'CT coloană', 'visibility' => 'private', 'references' => ['radiology:spine:tlics', 'radiology:gone']], "x\n", 'owner');
        $storage->create(self::REPORT, ['title' => 'TEST Patient Unu', 'exam_title' => 'CT coloană', 'visibility' => 'private', 'template' => 'templates:ct:coloana'], "## CT coloană\n\nText.\n", 'owner');
    }

    public function testTheReportViewAndTheEditorListThem(): void
    {
        foreach (['/' . self::REPORT, '/' . self::REPORT . '/edit'] as $url) {
            $body = $this->get($url)->body;
            self::assertStringContainsString('class="wk-refs"', $body, $url);
            self::assertStringContainsString('href="/radiology:spine:tlics" target="_blank" rel="noopener"', $body, $url);
            self::assertStringContainsString('Thoracolumbar injury score.', $body, $url);
            self::assertStringNotContainsString('radiology:gone', $body, $url);
        }
        self::assertStringContainsString('class="wk-docside"', $this->get('/' . self::REPORT)->body);
        self::assertStringNotContainsString('wk-refs', $this->get('/templates:ct:coloana')->body, 'only a report shows them');
    }

    public function testATemplatesDetailsPanelEditsTheList(): void
    {
        $body = $this->get('/templates:ct:coloana/edit')->body;
        self::assertStringContainsString('name="fm[references][]" value="radiology:spine:tlics" checked', $body);
        self::assertStringContainsString(t('details.references_missing'), $body, 'radiology:gone is flagged');
        self::assertStringNotContainsString('<option value="teaching:spine:ao">', $body, 'outside the reference namespaces');

        $this->config['references'] = ['namespaces' => ['teaching']];
        self::assertStringContainsString('<option value="teaching:spine:ao">', $this->get('/templates:ct:coloana/edit')->body);
    }

    public function testTheNamespacesAreASetting(): void
    {
        $this->post('/admin/settings/reports', http_build_query(['reports_modality_namespaces' => "MR = mri\n", 'references_namespaces' => 'radiology, teaching']));
        self::assertStringContainsString("references:\n  namespaces:\n    - radiology\n    - teaching", (string) file_get_contents($this->dataRoot . '/settings.yaml'));
        self::assertStringContainsString('<option value="teaching:spine:ao">', $this->get('/templates:ct:coloana/edit')->body);

        self::assertSame(422, $this->post('/admin/settings/reports', http_build_query(['reports_modality_namespaces' => "MR = mri\n", 'references_namespaces' => 'Not A Namespace!']))->status);
    }

    private function get(string $path): Response
    {
        return Kernel::boot($this->config)->handle(new Request('GET', $path, cookies: ['reporion' => $this->cookie()]));
    }

    private function post(string $path, string $body): Response
    {
        return Kernel::boot($this->config)->handle(new Request('POST', $path, cookies: ['reporion' => $this->cookie()], body: $body));
    }

    private function cookie(): string
    {
        return (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('owner');
    }
}
