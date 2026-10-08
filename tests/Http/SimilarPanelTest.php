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
use Reporion\Service\Ai\Context;
use Reporion\Service\Ai\Embedder;
use Reporion\Storage\FlatFile;

/**
 * Similar reports (phase 34e) on the page and through the API: no match
 * below Admin → AI's minimum similarity, each row's status, and a note when
 * the report changed since its vector was made. Vectors are put straight
 * into the index — no embedding server is asked. Fixture data is fictitious
 * (invariant 10).
 */
final class SimilarPanelTest extends HttpTestCase
{
    private const MODEL = 'embed-test';

    private Sqlite $index;
    private FlatFile $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        $this->config['ai'] = [
            'enabled' => true,
            'servers' => [['name' => 'Local', 'endpoint' => 'http://127.0.0.1:9/v1', 'model' => 'chat']],
            'embed_server' => 1,
            'embed_model' => self::MODEL,
            'embed_min_score' => 0.5,
        ];
        $this->index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        $this->storage = new FlatFile($this->dataRoot, $this->index);
        $report = fn (string $slug, string $cnp, string $conclusion) => $this->storage->create(
            'reports:mri:mioveni:' . $slug,
            ['title' => 'TEST ' . $cnp, 'visibility' => 'private', 'study_date' => '2026-09-27', 'exam_title' => 'IRM genunchi', 'patient' => ['name' => 'TEST ' . $cnp, 'cnp' => $cnp]],
            "# TEST {$cnp}\n\n## IRM genunchi\n\n### Concluzii\n\n{$conclusion}\n",
            'owner',
        );
        $report('260927-test-unu', '1900101000001', 'Ruptură de menisc medial.');
        $report('260927-test-doi', '1900101000002', 'Ruptură de menisc medial, gradul 3.');
        $report('260927-test-trei', '1900101000003', 'Fără modificări patologice.');
        // Near the first (cos 0.8), far from it (cos 0.1)
        $this->vector('260927-test-unu', [1.0, 0.0]);
        $this->vector('260927-test-doi', [0.8, 0.6]);
        $this->vector('260927-test-trei', [0.1, sqrt(0.99)]);
    }

    public function testBelowTheMinimumIsNoMatchAndEachRowHasItsStatus(): void
    {
        $json = json_decode($this->request('GET', '/api/v1/pages/reports:mri:mioveni:260927-test-unu/similar')->body, true);
        self::assertSame(['reports:mri:mioveni:260927-test-doi'], array_column($json['data'], 'path'), 'the far one is left out');
        self::assertSame('draft', $json['data'][0]['status']);

        $this->config['ai']['embed_min_score'] = 0.0;
        $json = json_decode($this->request('GET', '/api/v1/pages/reports:mri:mioveni:260927-test-unu/similar')->body, true);
        self::assertCount(2, $json['data'], 'no minimum: both');
    }

    public function testThePanelSaysWhenTheReportChangedSinceItsVector(): void
    {
        $path = 'reports:mri:mioveni:260927-test-unu';
        $page = $this->request('GET', '/' . $path)->body;
        self::assertStringContainsString('data-island="similar"', $page);
        self::assertStringNotContainsString('changed since its vector was made', $page);
        self::assertStringContainsString('"archived":"archived"', $page, 'the status labels reach the island');

        $record = $this->storage->read($path);
        $this->storage->save($path, $record->frontmatter, str_replace('menisc medial.', 'menisc lateral.', $record->body), $record->rev, 'owner');
        $page = $this->request('GET', '/' . $path)->body;
        self::assertStringContainsString('data-island="similar"', $page, 'still listed: the old vector is all there is');
        self::assertStringContainsString('changed since its vector was made', $page);
    }

    /** @param list<float> $vector */
    private function vector(string $slug, array $vector): void
    {
        $page = $this->storage->read('reports:mri:mioveni:' . $slug);
        $this->index->putVector($page->pid, self::MODEL, Embedder::sha(self::MODEL, (string) Context::forEmbedding($page)), $vector);
    }

    private function request(string $method, string $path): Response
    {
        return Kernel::boot($this->config)->handle(new Request(
            $method,
            $path,
            cookies: ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('owner')],
        ));
    }
}
