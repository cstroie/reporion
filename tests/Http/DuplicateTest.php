<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Audit\AuditLog;
use Reporion\Auth\FlatFileUserStore;
use Reporion\Cli\Output;
use Reporion\Cli\PageNewCommand;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Storage\FlatFile;

/**
 * "A new report like this one": /new?from=, POST /api/v1/pages/{path}/duplicate,
 * bin/reporion page:new --template= — exam fields cross, patient fields never.
 */
final class DuplicateTest extends HttpTestCase
{
    private const SOURCE = [
        'title' => 'RM cerebral nativ', 'visibility' => 'public', 'modality' => ['MR'], 'region' => ['neuro'],
        'site' => 'mioveni', 'device' => 'MV-MR-01', 'protocol' => 'neuro-std', 'accession' => 'MV-MR-26-0007',
        'study_date' => '2026-09-01', 'summary' => 'Normal.', 'patient' => ['name' => 'TEST PATIENT', 'born' => 1970],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        $this->storage()->create('reports:mri:mioveni:source', self::SOURCE, "## Descriere\n\nTemplate text.\n", 'owner');
    }

    public function testTheNewPageFormStartsFromACopyWithoutPatientFields(): void
    {
        $response = $this->as('GET', '/new?from=reports:mri:mioveni:source', query: ['from' => 'reports:mri:mioveni:source']);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('name="modality" value="mri"', $response->body);
        self::assertStringContainsString('Template text.', $response->body);
        self::assertStringContainsString('neuro-std', $response->body);
        foreach (['TEST PATIENT', 'MV-MR-26-0007', 'study_date', 'Normal.'] as $patientField) {
            self::assertStringNotContainsString($patientField, $response->body);
        }
        self::assertStringContainsString('visibility: private', $response->body);
    }

    public function testTheApiDuplicatesIntoAPrivateDraft(): void
    {
        $response = $this->as('POST', '/api/v1/pages/reports:mri:mioveni:source/duplicate', body: (string) json_encode(['to' => 'reports:mri:mioveni:copy']));

        self::assertSame(201, $response->status);
        $copy = $this->storage()->read('reports:mri:mioveni:copy');
        self::assertSame('draft', $copy->status);
        self::assertSame('private', $copy->visibility);
        self::assertSame(['MR'], $copy->frontmatter['modality']);
        self::assertArrayNotHasKey('patient', $copy->frontmatter);
        self::assertArrayNotHasKey('accession', $copy->frontmatter);
        self::assertStringContainsString('Template text.', $copy->body);
    }

    public function testKeepMetaCanNeverCarryThePatient(): void
    {
        $this->as('POST', '/api/v1/pages/reports:mri:mioveni:source/duplicate', body: (string) json_encode(['to' => 'reports:mri:mioveni:copy', 'keep_meta' => ['title', 'patient', 'accession']]));

        $copy = $this->storage()->read('reports:mri:mioveni:copy');
        self::assertSame(['title', 'visibility'], array_keys($copy->frontmatter));
    }

    public function testPageNewCopiesATemplatePage(): void
    {
        $this->storage()->create('templates:mri:neuro', ['title' => 'RM cerebral', 'visibility' => 'private', 'modality' => ['MR']], "## Tehnică\n\n", 'owner');
        $out = fopen('php://memory', 'w+');

        $code = (new PageNewCommand($this->storage(), new AuditLog($this->dataRoot . '/audit')))
            ->run(['reports:mri:mioveni:new-one', '--template=templates:mri:neuro'], new Output($out, fopen('php://memory', 'w')));

        self::assertSame(0, $code);
        $page = $this->storage()->read('reports:mri:mioveni:new-one');
        self::assertSame('RM cerebral', $page->frontmatter['title']);
        self::assertStringContainsString('## Tehnică', $page->body);
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }

    /** @param array<string, string> $query */
    private function as(string $method, string $path, array $query = [], string $body = ''): Response
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('owner');

        return Kernel::boot($this->config)->handle(new Request($method, strtok($path, '?'), query: $query, cookies: ['reporion' => $cookie], body: $body));
    }
}
