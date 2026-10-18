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
 * The editor's writing aids (D51): POST /api/v1/checks and
 * GET /api/v1/pages/{path}/prior. Fixture data is fictitious (invariant 10).
 */
final class WritingAidsTest extends HttpTestCase
{
    private const PATH = 'reports:mri:mioveni:261018-test-curent';
    private const OLDER = 'reports:mri:mioveni:250310-test-curent';
    private const OLDEST = 'reports:mri:mioveni:240105-test-curent';
    private const CNP = '1900101000001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner(radiologist: false);
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('mihai', 'x', false, [new Grant('reports:mri', GrantRole::Editor)]);
        $this->makeRadiologist('mihai');
        $users->create('ct', 'x', false, [new Grant('reports:ct', GrantRole::Editor)]);
        $this->makeRadiologist('ct');

        $storage = $this->storage();
        $meta = static fn (string $date, string $cnp = self::CNP): array => ['visibility' => 'private', 'modality' => ['MR'], 'region' => ['cerebral'], 'study_date' => $date, 'exam_title' => 'IRM cerebral', 'patient' => ['name' => 'TEST Pacient', 'cnp' => $cnp, 'sex' => 'M']];
        $storage->create(self::OLDEST, $meta('2024-01-05'), "# TEST Pacient\n\n## IRM cerebral\n\n### Descriere\n\nNodul de 5 mm.\n\n### Concluzii\n\nNodul.\n", 'mihai');
        $storage->create(self::OLDER, $meta('2025-03-10'), "# TEST Pacient\n\n## IRM cerebral\n\n### Descriere\n\nLeziune frontală dreaptă de 8 mm, hiperintensă T2.\n\nVentricule normale.\n\n### Concluzii\n\nLeziune frontală dreaptă.\n", 'mihai');
        $storage->create('reports:mri:mioveni:250901-test-altul', $meta('2025-09-01', '1900101000002'), "# TEST Altul\n\n## IRM cerebral\n\n### Descriere\n\nAlt pacient.\n\n### Concluzii\n\nNormal.\n", 'mihai');
        $storage->create(self::PATH, $meta('2026-10-18'), "# TEST Pacient\n\n## IRM cerebral\n\n### Descriere\n\nLeziune frontală dreaptă de 10 mm.\n\n### Concluzii\n\nAspect normal.\n", 'mihai');
    }

    public function testChecksSayWhatTheTextInFrontLacks(): void
    {
        $text = "# TEST Pacient\n\n## IRM cerebral\n\n### Descriere\n\nChist de [dimensiune]. Prostată normală.\n\n### Tehnică\n\n### Concluzii\n\nAspect normal.\n";
        $response = $this->call('POST', '/api/v1/checks', 'mihai', ['path' => self::PATH, 'text' => $text]);

        self::assertSame(200, $response->status);
        $data = json_decode($response->body, true)['data'];
        $byCode = array_column($data, null, 'code');
        self::assertSame('A blank is still to be filled: [dimensiune]', $byCode['blank']['message']);
        self::assertSame('[dimensiune]', $byCode['blank']['quote']);
        self::assertSame('The section Tehnică is empty.', $byCode['empty_section']['message']);
        self::assertArrayNotHasKey('sex_mismatch', $byCode, 'a prostate for a man: the page\'s own sex, from its frontmatter');
        self::assertSame('Described, not in the conclusion: Chist de [dimensiune].', $byCode['not_concluded']['message']);
    }

    public function testADocumentWithItsOwnFrontmatterIsCheckedByIt(): void
    {
        $text = "---\npatient:\n  sex: F\n---\n\n# TEST Pacient\n\n## IRM pelvis\n\n### Descriere\n\nProstată mărită.\n\n### Concluzii\n\nProstată mărită.\n";
        $codes = array_column(json_decode($this->call('POST', '/api/v1/checks', 'mihai', ['path' => self::PATH, 'text' => $text])->body, true)['data'], 'code');

        self::assertSame(['sex_mismatch'], $codes);
    }

    public function testThePriorIsTheLatestEarlierOneOfThisPatientAsSections(): void
    {
        $response = $this->call('GET', '/api/v1/pages/' . self::PATH . '/prior?exam=1', 'mihai');

        self::assertSame(200, $response->status);
        $data = json_decode($response->body, true)['data'];
        self::assertSame('2025-03-10', $data['study_date']);
        self::assertTrue($data['auto']);
        self::assertMatchesRegularExpression('#^/r/[0-9A-Z]{26}/1$#', $data['href']);
        self::assertSame('IRM cerebral', $data['exams'][0]['title']);
        self::assertSame(['Descriere', 'Concluzii'], array_column($data['exams'][0]['sections'], 'heading'));
        self::assertSame("Leziune frontală dreaptă de 8 mm, hiperintensă T2.\n\nVentricule normale.", $data['exams'][0]['sections'][0]['text']);
        self::assertEquals([['text' => '8 mm', 'mm' => 8, 'unit' => 'mm']], $data['exams'][0]['sections'][0]['passages'][0]['sizes']);
        self::assertStringNotContainsString('reports:', $response->body, 'never a page path (invariant 8)');
        self::assertStringNotContainsString('TEST', $response->body, 'never the name heading');
    }

    public function testANamedPriorWinsAndAReportWithNoneAnswersNull(): void
    {
        $storage = $this->storage();
        $record = $storage->read(self::PATH);
        $storage->save(self::PATH, ['priors' => [self::OLDEST]] + $record->frontmatter, $record->body, $record->rev, 'mihai');
        $data = json_decode($this->call('GET', '/api/v1/pages/' . self::PATH . '/prior', 'mihai')->body, true)['data'];
        self::assertSame('2024-01-05', $data['study_date']);
        self::assertFalse($data['auto']);

        self::assertNull(json_decode($this->call('GET', '/api/v1/pages/' . self::OLDEST . '/prior', 'mihai')->body, true)['data'], 'nothing earlier');
    }

    public function testOnlyARadiologistWhoMayWriteTheReport(): void
    {
        foreach ([['POST', '/api/v1/checks', ['path' => self::PATH, 'text' => 'x']], ['GET', '/api/v1/pages/' . self::PATH . '/prior', []]] as [$method, $url, $body]) {
            self::assertSame(404, $this->call($method, $url, null, $body)->status, $url);
            self::assertSame(404, $this->call($method, $url, 'ct', $body)->status, $url . ': no grant here');
            self::assertSame(403, $this->call($method, $url, 'owner', $body)->status, $url . ': reads it, no radiologist');
        }
        self::assertSame(404, $this->call('POST', '/api/v1/checks', 'mihai', ['path' => 'site:home', 'text' => 'x'])->status, 'reports only');
        self::assertSame(413, $this->call('POST', '/api/v1/checks', 'mihai', ['path' => self::PATH, 'text' => str_repeat('x', 200_001)])->status);
    }

    /** @param array<string, mixed> $body */
    private function call(string $method, string $url, ?string $user, array $body = []): Response
    {
        $cookies = $user !== null ? ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user)] : [];
        [$path, $query] = array_pad(explode('?', $url, 2), 2, '');
        parse_str($query, $q);

        return Kernel::boot($this->config)->handle(new Request($method, $path, query: $q, cookies: $cookies, body: $body !== [] ? (string) json_encode($body) : ''));
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }
}
