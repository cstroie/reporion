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
 * POST /{path}/patient-merge (Controller\PatientMergeController) —
 * confirming a patient-tab "possible match" (TODO 13). Writes patient.key
 * on the target page to the source page's own key, a new revision.
 */
final class PatientMergeTest extends HttpTestCase
{
    private const SOURCE = 'reports:mri:mioveni:260101-ionescu-maria';
    private const TARGET = 'reports:ct:mioveni:260102-maria-ionescu';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        (new FlatFileUserStore($this->dataRoot))->create('viewer', 'x', false, [new Grant('reports', GrantRole::Viewer)]);
        $this->storage()->create(self::SOURCE, [
            'title' => 'Ionescu Maria', 'visibility' => 'public',
            'patient' => ['name' => 'Ionescu Maria', 'born' => 1974, 'sex' => 'F', 'cnp' => '2740101123456'],
        ], 'body', 'owner');
        $this->storage()->create(self::TARGET, [
            'title' => 'Maria Ionescu', 'visibility' => 'public',
            'patient' => ['name' => 'Maria Ionescu', 'born' => 1975, 'sex' => 'F'],
        ], 'body', 'owner');
    }

    public function testConfirmingWritesTheSourceKeyOntoTheTargetAsANewRevision(): void
    {
        $response = $this->as('owner', 'POST', '/' . self::SOURCE . '/patient-merge', 'target=' . rawurlencode(self::TARGET));

        self::assertSame(302, $response->status);
        self::assertSame('/' . self::SOURCE . '/timeline?merge=ok', $response->headers['Location']);

        $target = $this->storage()->read(self::TARGET);
        self::assertSame(2, $target->rev);
        self::assertSame(hash('sha256', '2740101123456'), $target->frontmatter['patient']['key']);
        // Everything else on the target's own patient block is untouched
        self::assertSame('Maria Ionescu', $target->frontmatter['patient']['name']);
        self::assertSame(1975, $target->frontmatter['patient']['born']);

        self::assertStringContainsString('"action":"patient.merge"', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'));
    }

    public function testAViewerWithoutAWriteGrantGets404(): void
    {
        $response = $this->as('viewer', 'POST', '/' . self::SOURCE . '/patient-merge', 'target=' . rawurlencode(self::TARGET));

        self::assertSame(404, $response->status);
        self::assertSame(1, $this->storage()->read(self::TARGET)->rev);
    }

    public function testASourceWithNoPatientKeyRedirectsWithNokey(): void
    {
        $this->storage()->create('reports:mri:mioveni:no-patient', ['title' => 'X', 'visibility' => 'public'], 'body', 'owner');

        $response = $this->as('owner', 'POST', '/reports:mri:mioveni:no-patient/patient-merge', 'target=' . rawurlencode(self::TARGET));

        self::assertSame(302, $response->status);
        self::assertSame('/reports:mri:mioveni:no-patient/timeline?merge=nokey', $response->headers['Location']);
        self::assertSame(1, $this->storage()->read(self::TARGET)->rev);
    }

    public function testAMissingOrUnwritableTargetGets404(): void
    {
        $response = $this->as('owner', 'POST', '/' . self::SOURCE . '/patient-merge', 'target=reports:ct:mioveni:does-not-exist');

        self::assertSame(404, $response->status);
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }

    private function as(string $username, string $method, string $path, string $body = ''): Response
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($username);

        return Kernel::boot($this->config)->handle(new Request($method, $path, cookies: ['reporion' => $cookie], body: $body));
    }
}
