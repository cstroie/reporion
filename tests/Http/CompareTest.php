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
 * GET /{path}/compare — a report beside another study of the same patient
 * (phase 17a), end to end through the real Kernel. Fixtures are invented.
 */
final class CompareTest extends HttpTestCase
{
    private const NEW = 'reports:mri:mioveni:260922-test-a';
    private const OLD = 'reports:mri:mioveni:260304-test-a';
    private const CT = 'reports:ct:mioveni:250901-test-a';
    private const OTHER = 'reports:mri:mioveni:260922-test-b';
    private const PATIENT = ['name' => 'Testescu Ana', 'born' => 1980, 'sex' => 'F', 'cnp' => '2800101123450'];

    private FlatFile $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        $this->storage = new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
        $this->report(self::NEW, '2026-09-22', "# Testescu Ana\n\n## IRM cerebral\n\n### Descriere\n\nLeziune 12 mm.\n\n### Concluzii\n\nStabil.\n", 'private');
        $this->report(self::OLD, '2026-03-04', "# Testescu Ana\n\n## IRM cerebral nativ\n\n### Tehnică\n\nT2, FLAIR.\n\n### Descriere\n\nLeziune 11 mm.\n\n### Concluzie\n\nFără leziuni active.\n", 'private');
        $this->report(self::CT, '2025-09-01', "# Testescu Ana\n\n## CT cerebral\n\n### Descriere\n\nNormal.\n", 'private');
        $this->storage->create(self::OTHER, [
            'title' => 'Altcineva Ion', 'visibility' => 'private', 'study_date' => '2026-09-22',
            'patient' => ['name' => 'Altcineva Ion', 'born' => 1970, 'sex' => 'M', 'cnp' => '1700101123451'],
        ], "# Altcineva Ion\n\n### Descriere\n\nAltceva.\n", 'owner');
    }

    public function testWithoutWithTheNextOlderStudyIsShownSectionsInSync(): void
    {
        $response = $this->get(self::NEW . '/compare');

        self::assertSame(200, $response->status);
        $body = $response->body;
        self::assertStringContainsString('Leziune 12 mm.', $body);
        self::assertStringContainsString('Leziune 11 mm.', $body);
        self::assertStringNotContainsString('Normal.', $body, 'the study before this one, not every prior');
        self::assertStringContainsString('wk-cmp-sync', $body);
        // Newer on the left: its Descriere cell comes before the older one's
        self::assertLessThan(strpos($body, 'Leziune 11 mm.'), strpos($body, 'Leziune 12 mm.'));
        // "Concluzii" and "Concluzie" share a row: the newer's cell is right before the older's
        self::assertMatchesRegularExpression('#Stabil\.</p>\s*</div>\s*<div class="wk-prose wk-cmp-cell"><span[^>]*>2026-03-04</span><h3[^>]*>Concluzie#u', $body);
        // The older-only Tehnică has an empty cell beside it
        self::assertStringContainsString('wk-cmp-cell wk-cmp-none', $body);
        self::assertStringContainsString(t('compare.interval', ['6 months 18 days']), $body);
        // Neither name heading is printed in the columns
        self::assertStringNotContainsString('<h1', substr($body, (int) strpos($body, 'wk-cmp-sync')));
    }

    public function testAPriorWrittenInAnotherOrderIsShownInThisOnesAndItIsSaid(): void
    {
        self::assertStringNotContainsString('wk-cmp-note', $this->get(self::NEW . '/compare')->body, 'nothing moved');

        $flipped = 'reports:mri:mioveni:260601-test-a';
        $this->report($flipped, '2026-06-01', "# Testescu Ana\n\n## IRM cerebral\n\n### Concluzii\n\nIntermediar.\n\n### Descriere\n\nLeziune 11,5 mm.\n", 'private');
        $body = $this->get(self::NEW . '/compare')->body;

        self::assertStringContainsString(htmlspecialchars(t('compare.reordered', ['2026-06-01']), ENT_QUOTES), $body);
        self::assertLessThan(strpos($body, 'Intermediar.'), strpos($body, 'Leziune 11,5 mm.'), 'Descriere first, as in the newer report');
    }

    public function testWithPicksTheStudyAndOrderIsByDateWhicheverPageAsks(): void
    {
        $ct = (string) $this->storage->read(self::CT)->pid;
        $response = $this->get(self::OLD . '/compare', ['with' => $ct, 'sync' => '0']);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Normal.', $response->body);
        self::assertStringNotContainsString('wk-cmp-sync', $response->body, 'sync=0: the two pages whole');

        $newest = $this->get(self::CT . '/compare', ['with' => (string) $this->storage->read(self::NEW)->pid]);
        self::assertLessThan(strpos($newest->body, 'Normal.'), strpos($newest->body, 'Leziune 12 mm.'), 'newer on the left even from the older page');
    }

    public function testTheOldestStudyHasNoPriorAndAnotherPatientIsNotFound(): void
    {
        self::assertSame(404, $this->get(self::CT . '/compare')->status, 'nothing older to compare with');
        self::assertSame(404, $this->get(self::NEW . '/compare', ['with' => (string) $this->storage->read(self::OTHER)->pid])->status);
        self::assertSame(404, $this->get(self::NEW . '/compare', ['with' => (string) $this->storage->read(self::NEW)->pid])->status, 'not with itself');
        self::assertSame(404, $this->get(self::NEW . '/compare', ['with' => '01NOTAPID000000000000000000'])->status);
    }

    public function testAPriorTheCallerCannotReadIsNotFoundLikeAMissingOne(): void
    {
        // The older MRI under a namespace the viewer has no grant on
        $hidden = 'reports:ct:pitesti:260304-test-a';
        $this->report($hidden, '2026-03-05', "# Testescu Ana\n\n### Descriere\n\nAscuns.\n", 'private');
        (new FlatFileUserStore($this->dataRoot))->create('viewer', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('reports:mri', GrantRole::Viewer)]);

        $asViewer = fn (string $path, array $query = []): Response => Kernel::boot($this->config)->handle(new Request('GET', '/' . $path, query: $query, cookies: ['reporion' => $this->issueCookie('viewer')]));
        $hiddenPid = (string) $this->storage->read($hidden)->pid;

        self::assertSame(404, $asViewer(self::NEW . '/compare', ['with' => $hiddenPid])->status);
        $prior = $asViewer(self::NEW . '/compare');
        self::assertSame(200, $prior->status, 'the next older study the viewer can read');
        self::assertStringContainsString('Leziune 11 mm.', $prior->body);
        self::assertStringNotContainsString('Ascuns', $prior->body);
        self::assertSame(200, $this->get(self::NEW . '/compare', ['with' => $hiddenPid])->status, 'the owner reads it');
    }

    public function testTheDeltaPanelAsksAboutTheOlderStudyOnlyForAWriterWithTheEvolutionPrompt(): void
    {
        self::assertStringNotContainsString('ai-evo-config', $this->get(self::NEW . '/compare')->body, 'the assistant is off (D15)');

        $this->config['ai'] = ['enabled' => true, 'servers' => [['endpoint' => 'http://127.0.0.1:9/v1', 'tiers' => ['normal' => ['model' => 'test-model']]]]];
        $this->storage->create('ai:profiles:reports', ['title' => 'Reports profile', 'visibility' => 'private'], "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n", 'owner');
        $this->storage->create('ai:profiles:reports:evolution', ['title' => 'Evolution', 'visibility' => 'private'], "{text}\n{history}\n", 'owner');
        (new FlatFileUserStore($this->dataRoot))->create('viewer', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('reports:mri', GrantRole::Viewer)]);

        $body = $this->get(self::OLD . '/compare', ['with' => (string) $this->storage->read(self::NEW)->pid])->body;
        self::assertStringContainsString('"path":"' . self::NEW . '","with":"' . $this->storage->read(self::OLD)->pid . '"', $body, 'about the newer, against the older');

        $asViewer = Kernel::boot($this->config)->handle(new Request('GET', '/' . self::NEW . '/compare', cookies: ['reporion' => $this->issueCookie('viewer')]));
        self::assertSame(200, $asViewer->status);
        self::assertStringNotContainsString('ai-evo-config', $asViewer->body, 'a viewer cannot ask');
    }

    public function testAnonymousGets404OnAPrivateReport(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request('GET', '/' . self::NEW . '/compare'));

        self::assertSame(404, $response->status);
    }

    public function testAnOrdinaryPageHasNoCompare(): void
    {
        $this->storage->create('docs:notes', ['title' => 'Notes', 'visibility' => 'private'], 'body', 'owner');

        self::assertSame(404, $this->get('docs:notes/compare')->status);
    }

    public function testTwoTickedStudiesRedirectToTheNewerWithTheOlder(): void
    {
        $response = $this->get(self::CT . '/compare', ['back' => '/x'], ['paths' => [self::CT, self::NEW]]);

        self::assertSame(302, $response->status);
        self::assertSame('/' . self::NEW . '/compare?with=' . $this->storage->read(self::CT)->pid, $response->headers['Location']);
    }

    public function testAPickThatIsNotTwoOfThisPatientsStudiesGoesBackToTheTimeline(): void
    {
        foreach ([[self::NEW], [self::NEW, self::OLD, self::CT], [self::NEW, self::OTHER], [self::NEW, self::NEW]] as $paths) {
            $response = $this->get(self::NEW . '/compare', [], ['paths' => $paths]);
            self::assertSame(302, $response->status);
            self::assertSame('/' . self::NEW . '/timeline?compare=pick', $response->headers['Location'], implode(',', $paths));
        }
    }

    private function report(string $path, string $date, string $body, string $visibility): void
    {
        $this->storage->create($path, ['title' => 'Testescu Ana', 'visibility' => $visibility, 'study_date' => $date, 'patient' => self::PATIENT], $body, 'owner');
    }

    /**
     * @param array<string, string>       $query
     * @param array<string, list<string>> $lists
     */
    private function get(string $path, array $query = [], array $lists = []): Response
    {
        return Kernel::boot($this->config)->handle(new Request('GET', '/' . $path, query: $query, cookies: ['reporion' => $this->issueCookie('owner')], queryLists: $lists));
    }

    private function issueCookie(string $username): string
    {
        return (new Session(
            (string) $this->config['auth']['session_secret'],
            (string) $this->config['auth']['session_name'],
            (int) $this->config['auth']['session_lifetime'],
            new FlatFileUserStore($this->dataRoot),
        ))->issue($username);
    }
}
