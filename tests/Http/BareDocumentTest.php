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
 * A page submitted without frontmatter (decided 2026-09-27): a new page gets
 * one from what it says (Service\FrontmatterGuess); a saved page keeps its own.
 */
final class BareDocumentTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
    }

    public function testTheNewPageFormCreatesAPageFromABareDocument(): void
    {
        $response = $this->form('POST', '/new', ['path' => 'docs:it:medima', 'document' => "# Medima\n\n## RDP\n\nSee the admin.\n"]);

        self::assertSame(302, $response->status, 'created, not refused as malformed');
        $page = $this->storage()->read('docs:it:medima');
        self::assertSame(['title' => 'Medima', 'visibility' => 'private'], $page->frontmatter);
        self::assertSame("# Medima\n\n## RDP\n\nSee the admin.\n", $page->body);
    }

    public function testTheApiCreatesAReportFromABareDocumentOrNoMeta(): void
    {
        $bare = $this->json('POST', '/api/v1/pages', ['path' => 'reports:ct:mioveni:260927-test-unu', 'document' => "# TEST Patient Unu\n\n## CT cerebral\n"]);
        self::assertSame(201, $bare->status);
        $fm = $this->storage()->read('reports:ct:mioveni:260927-test-unu')->frontmatter;
        self::assertSame(['CT'], $fm['modality']);
        self::assertSame('2026-09-27', $fm['study_date']);
        self::assertSame(['name' => 'TEST Patient Unu'], $fm['patient']);
        self::assertSame('CT cerebral', $fm['exam_title']);

        self::assertSame(201, $this->json('POST', '/api/v1/pages', ['path' => 'docs:note', 'body' => "# A note\n"])->status);
        self::assertSame('A note', $this->storage()->read('docs:note')->frontmatter['title']);
    }

    public function testSavingWithoutFrontmatterKeepsThePagesOwn(): void
    {
        $meta = ['title' => 'TEST Patient Unu', 'visibility' => 'private', 'accession' => 'MV-CT-26-0001', 'patient' => ['name' => 'TEST Patient Unu']];
        $this->storage()->create('reports:ct:mioveni:260927-test-doi', $meta, "v1\n", 'owner');

        self::assertSame(302, $this->form('POST', '/reports:ct:mioveni:260927-test-doi/edit', ['document' => "v2 from the editor\n", 'base_rev' => '1'])->status);
        self::assertSame(200, $this->json('PUT', '/api/v1/pages/reports:ct:mioveni:260927-test-doi', ['document' => "v3 from the API\n", 'base_rev' => 2])->status);

        $page = $this->storage()->read('reports:ct:mioveni:260927-test-doi');
        self::assertSame(3, $page->rev);
        self::assertSame(\Reporion\Support\Exams::normalize($meta), $page->frontmatter, 'nothing lost: not the accession, not the patient');
        self::assertSame("v3 from the API\n", $page->body);
    }

    /** @param array<string, string> $fields */
    private function form(string $method, string $path, array $fields): Response
    {
        return Kernel::boot($this->config)->handle(new Request($method, $path, cookies: ['reporion' => $this->cookie()], body: http_build_query($fields)));
    }

    /** @param array<string, mixed> $body */
    private function json(string $method, string $path, array $body): Response
    {
        return Kernel::boot($this->config)->handle(new Request($method, $path, cookies: ['reporion' => $this->cookie()], body: (string) json_encode($body)));
    }

    private function cookie(): string
    {
        return (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('owner');
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }
}
