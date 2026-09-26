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
 * The page's Sign button: GET/POST /{path}/sign (Controller\SignController)
 * over the same Service\Signing as the API — a draft report the caller
 * may write, the reviewed revision only, required fields first.
 */
final class SignButtonTest extends HttpTestCase
{
    private const PATH = 'reports:mri:mioveni:260923-popescu-ana';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('mihai', 'x', false, [new Grant('reports:mri', GrantRole::Editor)]);
        $users->create('ana', 'x', false, [new Grant('reports:mri', GrantRole::Viewer)]);
    }

    public function testADraftReportShowsTheButtonAndASignedOneDoesNot(): void
    {
        $this->storage()->create(self::PATH, self::completeMeta(), 'Text.', 'owner');

        self::assertStringContainsString('href="/' . self::PATH . '/sign"', $this->as('mihai', 'GET', '/' . self::PATH)->body);
        self::assertStringNotContainsString('/sign"', $this->as('ana', 'GET', '/' . self::PATH)->body, 'a viewer cannot sign');

        $this->storage()->sign(self::PATH, 'owner', []);
        self::assertStringNotContainsString('href="/' . self::PATH . '/sign"', $this->as('mihai', 'GET', '/' . self::PATH)->body);
    }

    public function testAnEditorSignsTheReviewedRevisionAsThemselves(): void
    {
        $this->storage()->create(self::PATH, self::completeMeta(), 'Text.', 'owner');

        $form = $this->as('mihai', 'GET', '/' . self::PATH . '/sign');
        self::assertSame(200, $form->status);
        self::assertStringContainsString('name="base_rev" value="1"', $form->body);

        $signed = $this->as('mihai', 'POST', '/' . self::PATH . '/sign', 'base_rev=1&parafa=+X123+');
        self::assertSame(302, $signed->status);
        self::assertSame('/' . self::PATH, $signed->headers['Location']);

        $record = $this->storage()->read(self::PATH);
        self::assertSame('signed', $record->status);
        self::assertSame(1, $record->rev, 'signing writes no revision');
        self::assertSame('mihai', $record->meta['signatures'][0]['by']);
        self::assertSame('X123', $record->meta['signatures'][0]['parafa']);

        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"page.sign"', $audit);
        self::assertStringNotContainsString('popescu', $audit, 'invariant 8: no patient path in the audit');
    }

    public function testARevisionSavedMeanwhileIsNotSigned(): void
    {
        $this->storage()->create(self::PATH, self::completeMeta(), 'Text.', 'owner');
        $this->storage()->save(self::PATH, self::completeMeta(), 'Text, amended.', 1, 'owner');

        $response = $this->as('mihai', 'POST', '/' . self::PATH . '/sign', 'base_rev=1');

        self::assertSame(409, $response->status);
        self::assertStringContainsString('name="base_rev" value="2"', $response->body);
        self::assertSame('draft', $this->storage()->read(self::PATH)->status);
    }

    public function testMissingRequiredFieldsAreListedAndNothingIsSigned(): void
    {
        $meta = self::completeMeta();
        unset($meta['indication']);
        $this->storage()->create(self::PATH, $meta, 'Text.', 'owner');

        $form = $this->as('owner', 'GET', '/' . self::PATH . '/sign');
        self::assertSame(200, $form->status);
        self::assertStringContainsString('Indication', $form->body);
        self::assertStringNotContainsString('name="base_rev"', $form->body);

        self::assertSame(422, $this->as('owner', 'POST', '/' . self::PATH . '/sign', 'base_rev=1')->status);
        self::assertSame('draft', $this->storage()->read(self::PATH)->status);
    }

    public function testOnlyWritersAndOnlyReportsGet404Otherwise(): void
    {
        $this->storage()->create(self::PATH, self::completeMeta(), 'Text.', 'owner');
        $this->storage()->create('reports:mri:mioveni', ['title' => 'Mioveni', 'visibility' => 'private'], 'Site page.', 'owner');

        self::assertSame(404, $this->as('ana', 'GET', '/' . self::PATH . '/sign')->status);
        self::assertSame(404, $this->as('ana', 'POST', '/' . self::PATH . '/sign', 'base_rev=1')->status);
        self::assertSame(404, Kernel::boot($this->config)->handle(new Request('GET', '/' . self::PATH . '/sign'))->status);
        self::assertSame(404, $this->as('owner', 'GET', '/reports:mri:mioveni/sign')->status);
        self::assertSame('draft', $this->storage()->read(self::PATH)->status);
    }

    /** @return array<string, mixed> */
    private static function completeMeta(): array
    {
        return [
            'title' => 'Popescu Ana',
            'visibility' => 'private',
            'modality' => ['MR'],
            'region' => ['neuro'],
            'site' => 'mioveni',
            'study_date' => '2026-09-23',
            'summary' => 'Fara leziuni.',
            'indication' => 'Cefalee.',
            'patient' => ['name' => 'Popescu Ana'],
        ];
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
