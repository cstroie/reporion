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
use Reporion\Support\Exams;

/**
 * POST /join (roadmap phase 29): two reports of one patient made one
 * multi-exam report, after a check screen.
 */
final class JoinTest extends HttpTestCase
{
    private const A = 'reports:mri:mioveni:261002-test-pacient';
    private const B = 'reports:ct:mioveni:261002-test-pacient';
    private const C = 'reports:mri:mioveni:260901-test-pacient';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        $patient = ['name' => 'TEST Pacient', 'cnp' => '2800115123456', 'sex' => 'F', 'born' => 1980];
        $s = $this->storage();
        $s->create(self::A, ['title' => 'TEST Pacient', 'site' => 'mioveni', 'patient' => $patient, 'referrer' => 'dr. A', 'indication' => 'Cefalee.',
            'exam_title' => 'IRM cerebral', 'modality' => ['MR'], 'region' => ['neuro'], 'study_date' => '2026-10-02T11:00:00+03:00', 'accession' => 'MV-MR-26-0001', 'template' => 'templates:mri:cerebral'],
            "# TEST Pacient\n\n## IRM cerebral\n\nFără leziuni.\n\n### Concluzii\n\nNormal.\n", 'owner');
        $s->create(self::B, ['title' => 'TEST Pacient', 'site' => 'mioveni', 'patient' => $patient, 'referrer' => 'dr. B', 'indication' => 'Cefalee.',
            'exam_title' => 'CT torace', 'modality' => ['CT'], 'region' => ['chest'], 'study_date' => '2026-10-02T09:00:00+03:00', 'accession' => 'MV-CT-26-0001', 'study_uid' => '1.2.3'],
            "# TEST Pacient\n\n## CT torace\n\nNodul.\n\n### Concluzii\n\nNodul 5 mm.\n", 'owner');
        // A later report of the patient pointing at A: relinked
        $s->create(self::C, ['title' => 'TEST Pacient', 'site' => 'mioveni', 'patient' => $patient, 'priors' => [self::A], 'exam_title' => 'IRM', 'modality' => ['MR'], 'study_date' => '2026-09-01'],
            "# TEST Pacient\n\n## IRM\n\nSee [A](" . self::A . ").\n", 'owner');
    }

    public function testTheCheckScreenOrdersTheExamsByTimeAndWritesNothing(): void
    {
        $screen = $this->post(['paths' => [self::A, self::B]]);

        self::assertSame(200, $screen->status);
        self::assertLessThan(strpos($screen->body, 'IRM cerebral</span>'), strpos($screen->body, 'CT torace</span>'), 'the 09:00 CT first');
        self::assertStringContainsString('name="choice[referrer]"', $screen->body, 'the referrers differ: pick one');
        self::assertStringNotContainsString('name="choice[indication]"', $screen->body, 'the same indication: nothing to pick');
        self::assertStringContainsString('name="ns"', $screen->body, 'two modalities: the folder can be chosen');
        self::assertTrue($this->exists(self::A) && $this->exists(self::B));
    }

    public function testJoinWritesOneReportAndTrashesTheParents(): void
    {
        $revs = ['rev' => [$this->storage()->read(self::A)->pid => '1', $this->storage()->read(self::B)->pid => '1']];
        $done = $this->post(['paths' => [self::A, self::B], 'order' => ['0.0', '1.0'], 'choice' => ['referrer' => '1'], 'ns' => 'mri', 'action' => 'join'] + $revs);

        self::assertSame(302, $done->status);
        self::assertSame('/' . self::A . '/edit', $done->headers['Location'], 'the parent\'s path, freed by the trash');
        self::assertFalse($this->exists(self::B));

        $joined = $this->storage()->read(self::A);
        $fm = $joined->frontmatter;
        self::assertSame(['IRM cerebral', 'CT torace'], array_column($fm['exams'], 'title'), 'in the order posted');
        self::assertSame(['MV-MR-26-0001', 'MV-CT-26-0001'], array_column($fm['exams'], 'accession'), 'each keeps its number (D20)');
        self::assertSame('1.2.3', $fm['exams'][1]['study_uid']);
        self::assertSame('templates:mri:cerebral', $fm['exams'][0]['template']);
        self::assertSame(['MR', 'CT'], $fm['modality']);
        self::assertSame('dr. B', $fm['referrer'], 'as picked');
        self::assertSame('2026-10-02T09:00:00+03:00', $fm['study_date'], 'the earliest exam');
        self::assertCount(2, $fm['joined_from']);
        self::assertSame('draft', $joined->status);
        self::assertSame([], Exams::problems($fm, $joined->body), 'whole: a ## and a conclusion per exam');
        self::assertStringStartsWith("# TEST Pacient\n\n## IRM cerebral\n", $joined->body);

        $c = $this->storage()->read(self::C);
        self::assertSame([self::A], $c->frontmatter['priors'], 'the prior now names the joined report');
        self::assertStringContainsString('(' . self::A . ')', $c->body);

        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"action":"page.join"', $audit);
        self::assertStringNotContainsString('test-pacient', $audit, 'no path in the audit (invariant 8)');
    }

    public function testAParentChangedSinceTheCheckIsRefused(): void
    {
        $a = $this->storage()->read(self::A);
        $b = $this->storage()->read(self::B);
        $this->storage()->save(self::B, $b->frontmatter, $b->body . "\nMore.\n", 1, 'owner');

        $refused = $this->post(['paths' => [self::A, self::B], 'action' => 'join', 'rev' => [$a->pid => '1', $b->pid => '1']]);

        self::assertSame(422, $refused->status);
        self::assertStringContainsString(htmlspecialchars(t('join.err_changed'), ENT_QUOTES), $refused->body);
        self::assertTrue($this->exists(self::A) && $this->exists(self::B), 'nothing written');
    }

    public function testOtherPatientsOrSitesAreRefused(): void
    {
        $this->storage()->create('reports:ct:mioveni:261002-test-altul', ['title' => 'TEST Altul', 'site' => 'mioveni', 'patient' => ['name' => 'TEST Altul', 'cnp' => '1800115123456'], 'exam_title' => 'CT', 'modality' => ['CT'], 'study_date' => '2026-10-02'], "# TEST Altul\n\n## CT\n", 'owner');
        $this->storage()->create('reports:ct:scuc:261002-test-pacient', ['title' => 'TEST Pacient', 'site' => 'scuc', 'patient' => ['name' => 'TEST Pacient', 'cnp' => '2800115123456'], 'exam_title' => 'CT', 'modality' => ['CT'], 'study_date' => '2026-10-02'], "# TEST Pacient\n\n## CT\n", 'owner');

        $patient = $this->post(['paths' => [self::A, 'reports:ct:mioveni:261002-test-altul']])->body;
        $site = $this->post(['paths' => [self::A, 'reports:ct:scuc:261002-test-pacient']])->body;
        $one = $this->post(['paths' => [self::A]])->body;

        self::assertStringContainsString(htmlspecialchars(t('join.err_patient'), ENT_QUOTES), $patient);
        self::assertStringContainsString(htmlspecialchars(t('join.err_site'), ENT_QUOTES), $site);
        self::assertStringContainsString(htmlspecialchars(t('join.err_two'), ENT_QUOTES), $one);
        self::assertMatchesRegularExpression('/value="join" disabled/', $patient);
    }

    public function testTwoExamsWithOneAccessionAreRefused(): void
    {
        $b = $this->storage()->read(self::B);
        $fm = $b->frontmatter;
        $fm['exams'][0]['accession'] = 'MV-MR-26-0001';
        $this->storage()->save(self::B, $fm, $b->body, 1, 'owner');

        $screen = $this->post(['paths' => [self::A, self::B]])->body;

        self::assertStringContainsString(htmlspecialchars(t('join.err_accession', ['MV-MR-26-0001']), ENT_QUOTES), $screen, 'D20: never held twice');
    }

    public function testTheNameDefaultsToWhatTheReportsShareAndIsTheUsersToEdit(): void
    {
        $patient = ['name' => 'TEST Genunchi', 'cnp' => '1800115123457', 'sex' => 'M', 'born' => 1980];
        $knee = static fn (string $side): array => ['title' => 'TEST Genunchi', 'site' => 'mioveni', 'patient' => $patient, 'exam_title' => 'IRM Genunchi ' . $side, 'modality' => ['MR'], 'region' => ['msk'], 'study_date' => '2026-09-03'];
        $rk = 'reports:mri:mioveni:260903-test-genunchi-rk';
        $lk = 'reports:mri:mioveni:260903-test-genunchi-lk';
        $this->storage()->create($rk, $knee('Drept') + ['accession' => 'MV-MR-26-0101'], "# TEST Genunchi\n\n## IRM Genunchi Drept\n\nA.\n\n### Concluzii\n\nNormal.\n", 'owner');
        $this->storage()->create($lk, $knee('Stâng') + ['accession' => 'MV-MR-26-0102'], "# TEST Genunchi\n\n## IRM Genunchi Stâng\n\nB.\n\n### Concluzii\n\nNormal.\n", 'owner');

        $screen = $this->post(['paths' => [$rk, $lk]])->body;
        self::assertStringContainsString('<span>reports:mri:mioveni:</span><input class="input wk-tflex" type="text" name="leaf" value="260903-test-genunchi"', $screen, '-rk / -lk dropped');

        foreach (['260904-test-genunchi', 'Test Genunchi', '260903-'] as $bad) {
            self::assertStringContainsString(htmlspecialchars(t('join.err_leaf', ['260903']), ENT_QUOTES), $this->post(['paths' => [$rk, $lk], 'leaf' => $bad])->body, $bad);
        }
        self::assertStringContainsString(htmlspecialchars(t('join.err_taken'), ENT_QUOTES), $this->takenScreen($rk, $lk), 'another page there');

        $revs = ['rev' => [$this->storage()->read($rk)->pid => '1', $this->storage()->read($lk)->pid => '1']];
        $done = $this->post(['paths' => [$rk, $lk], 'leaf' => '260903-test-genunchi-joined', 'action' => 'join'] + $revs);
        self::assertSame('/reports:mri:mioveni:260903-test-genunchi-joined/edit', $done->headers['Location'], 'the name as written');
        self::assertFalse($this->exists($rk) || $this->exists($lk));
        self::assertMatchesRegularExpression('/\A# TEST Genunchi\n\n## IRM Genunchi (Drept|Stâng)\n\n[AB]\.\n\n### Concluzii\n/u', $this->storage()->read('reports:mri:mioveni:260903-test-genunchi-joined')->body);
    }

    public function testAnyOfTheSummariesGoInEachAfterItsExamTitle(): void
    {
        $patient = ['name' => 'TEST Genunchi', 'cnp' => '1800115123457', 'sex' => 'M', 'born' => 1980];
        $knee = static fn (string $side, string $time, string $summary): array => ['title' => 'TEST Genunchi', 'site' => 'mioveni', 'patient' => $patient, 'exam_title' => 'IRM Genunchi ' . $side, 'modality' => ['MR'], 'study_date' => '2026-09-03T' . $time . ':00+03:00', 'summary' => $summary];
        $rk = 'reports:mri:mioveni:260903-test-genunchi-rk';
        $lk = 'reports:mri:mioveni:260903-test-genunchi-lk';
        // The left knee first on the list, the right knee examined first
        $this->storage()->create($lk, $knee('Stâng', '10:00', 'Minim edem al tendonului patelar') + ['accession' => 'MV-MR-26-0102'], "# TEST Genunchi\n\n## IRM Genunchi Stâng\n\nB.\n\n### Concluzii\n\nB.\n", 'owner');
        $this->storage()->create($rk, $knee('Drept', '09:00', 'IRM Genunchi Drept: aspect normal.') + ['accession' => 'MV-MR-26-0101'], "# TEST Genunchi\n\n## IRM Genunchi Drept\n\nA.\n\n### Concluzii\n\nA.\n", 'owner');
        $paths = ['paths' => [$lk, $rk]];

        $all = $this->post($paths)->body;
        self::assertSame(2, substr_count($all, 'name="choice[summary][]"'), 'checkboxes, not radios');
        self::assertSame(2, preg_match_all('/name="choice\[summary\]\[\]" value="\d" checked/', $all), 'all ticked at first');
        self::assertStringContainsString('IRM Genunchi Drept: Aspect normal. IRM Genunchi Stâng: Minim edem al tendonului patelar.', html_entity_decode($all, ENT_QUOTES), 'exam order, each once led by its title');

        $one = html_entity_decode($this->post($paths + ['summary_set' => '1', 'choice' => ['summary' => ['0']]])->body, ENT_QUOTES);
        self::assertStringContainsString(t('join.summary_result') . ' IRM Genunchi Stâng: Minim edem al tendonului patelar.', $one);

        $revs = ['rev' => [$this->storage()->read($rk)->pid => '1', $this->storage()->read($lk)->pid => '1']];
        $this->post($paths + $revs + ['summary_set' => '1', 'leaf' => '260903-test-genunchi', 'action' => 'join']);
        self::assertArrayNotHasKey('summary', $this->storage()->read('reports:mri:mioveni:260903-test-genunchi')->frontmatter, 'none ticked: no summary');
    }

    public function testAJoinedTextOutOfTheReportShapeIsNotJoined(): void
    {
        $b = $this->storage()->read(self::B);
        // An exam heading again at ### under its ## — the archive's shape, which no level can place
        $this->storage()->save(self::B, $b->frontmatter, "# TEST Pacient\n\n## CT torace\n\n### CT torace nativ\n\nNodul.\n\n### Concluzii\n\nNodul 5 mm.\n", 1, 'owner');

        $screen = $this->post(['paths' => [self::A, self::B], 'ns' => 'mri']);

        self::assertMatchesRegularExpression('/' . preg_quote(htmlspecialchars(explode('%s', t('join.err_structure'))[0], ENT_QUOTES), '/') . '/', $screen->body);
        self::assertMatchesRegularExpression('/value="join" disabled/', $screen->body);
    }

    private function takenScreen(string $rk, string $lk): string
    {
        $this->storage()->create('reports:mri:mioveni:260903-test-genunchi', ['title' => 'TEST Genunchi', 'site' => 'mioveni', 'exam_title' => 'X', 'modality' => ['MR'], 'study_date' => '2026-09-03'], "# X\n", 'owner');
        $screen = $this->post(['paths' => [$rk, $lk]])->body;
        $this->storage()->delete('reports:mri:mioveni:260903-test-genunchi', 'owner');

        return $screen;
    }

    /** @param array<string, mixed> $fields */
    private function post(array $fields): Response
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('owner');

        return Kernel::boot($this->config)->handle(new Request('POST', '/join', cookies: ['reporion' => $cookie], body: http_build_query($fields)));
    }

    private function exists(string $path): bool
    {
        try {
            $this->storage()->read($path);

            return true;
        } catch (\Reporion\Exception\PageNotFoundException) {
            return false;
        }
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }
}
