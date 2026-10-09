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
 * Roadmap phase 25 end to end: the report view and the editor open the
 * template's reference page in a slide-in panel; a template's Details
 * panel picks it; Admin → Settings sets where the picker looks.
 */
final class ReferencesTest extends HttpTestCase
{
    private const REPORT = 'reports:ct:mioveni:261001-test-unu';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        $storage = new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
        $storage->create('radiology:spine:tlics', ['title' => 'TLICS', 'visibility' => 'private'], "## Score\n\nThoracolumbar injury score.\n", 'owner');
        $storage->create('teaching:spine:ao', ['title' => 'AO in teaching', 'visibility' => 'private'], "x\n", 'owner');
        $storage->create('templates:ct:coloana', ['title' => 'CT coloană', 'visibility' => 'private', 'reference' => 'radiology:spine:tlics'], "x\n", 'owner');
        $storage->create(self::REPORT, ['title' => 'TEST Patient Unu', 'exam_title' => 'CT coloană', 'visibility' => 'private', 'template' => 'templates:ct:coloana'], "## CT coloană\n\nText.\n", 'owner');
    }

    public function testTheReportViewOpensItInAPanel(): void
    {
        $body = $this->get('/' . self::REPORT)->body;
        self::assertStringContainsString('href="/radiology:spine:tlics" target="_blank" rel="noopener" data-ref-open', $body);
        self::assertStringContainsString('<aside class="wk-refpanel" id="reference-panel"', $body);
        self::assertStringContainsString('Thoracolumbar injury score.', $body);
        self::assertStringContainsString('js/reference-panel.js', $body);
        self::assertStringNotContainsString('reference-panel', $this->get('/templates:ct:coloana')->body, 'only a report has one');
    }

    /**
     * In the editor the right side is the rail, where the Assistant is: the
     * reference is one of its accordion sections, never a panel over it.
     */
    public function testTheEditorShowsItAsARailSection(): void
    {
        $body = $this->get('/' . self::REPORT . '/edit')->body;
        self::assertStringContainsString('<details class="wk-rail-sec" name="editor-rail" data-rail="reference" id="editor-reference" open>', $body, 'the only section, so open');
        self::assertStringContainsString('data-rail-open="reference"', $body);
        self::assertStringContainsString('Thoracolumbar injury score.', $body);
        self::assertStringNotContainsString('id="reference-panel"', $body);
        self::assertStringContainsString('js/editor-rail.js', $body);
    }

    public function testATemplatesDetailsPanelPicksIt(): void
    {
        $body = $this->get('/templates:ct:coloana/edit')->body;
        self::assertStringContainsString('<option value="radiology:spine:tlics" selected>TLICS · radiology:spine:tlics</option>', $body);
        self::assertStringNotContainsString('<option value="teaching:spine:ao"', $body, 'outside the reference namespaces');

        $this->config['references'] = ['namespaces' => ['teaching']];
        self::assertStringContainsString('<option value="teaching:spine:ao">', $this->get('/templates:ct:coloana/edit')->body);
    }

    /** Phase 31: the checklist as a form on the template, written back as the same lines */
    public function testATemplatesChecklistIsAFormAndSavesAsLines(): void
    {
        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        $storage = new FlatFile($this->dataRoot, $index);
        $storage->save('templates:ct:coloana', ['title' => 'CT coloană', 'visibility' => 'private', 'checklist' => ['# Corpuri', 'Fractură | fractur', ['x' => 1, 'y' => 2]]], "Fără fracturi.\n", 1, 'owner');

        $body = $this->get('/templates:ct:coloana/edit')->body;
        self::assertStringContainsString('data-island="details-checklist"', $body);
        self::assertStringContainsString('name="fm[checklist][0][label]" value="Corpuri"', $body);
        self::assertStringContainsString('name="fm[checklist][1][keywords]" value="fractur"', $body);
        self::assertStringContainsString('name="fm[checklist][2][kind]" value="raw"', $body);
        self::assertStringContainsString('js/details-checklist.js', $body);
        self::assertStringNotContainsString('data-island="details-checklist"', $this->get('/' . self::REPORT . '/edit')->body, 'a report reads its template\'s, it has none');

        $saved = $this->post('/templates:ct:coloana/edit', http_build_query([
            'body' => "Fără fracturi.\n",
            'base_rev' => 2,
            'fm' => ['title' => 'CT coloană', 'checklist' => [
                'n0' => ['kind' => 'item', 'label' => 'Canal spinal', 'keywords' => 'canal'],
                '0' => ['kind' => 'section', 'label' => 'Corpuri'],
                '1' => ['kind' => 'item', 'label' => 'Fractură', 'keywords' => 'fractur, tasare'],
                '2' => ['kind' => 'raw', 'raw' => '{"x":1,"y":2}'],
                'b1' => ['kind' => 'item', 'label' => '', 'keywords' => ''],
            ]],
            'fm_shown' => ['title', 'checklist'],
        ]));
        self::assertSame(302, $saved->status);
        self::assertSame(
            ['Canal spinal | canal', '# Corpuri', 'Fractură | fractur, tasare', ['x' => 1, 'y' => 2]],
            $storage->read('templates:ct:coloana')->frontmatter['checklist'],
            'in the posted order, the raw line as it was, the blank row gone'
        );
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
