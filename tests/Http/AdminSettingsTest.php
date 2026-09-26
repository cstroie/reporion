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
use Reporion\Kernel;

/**
 * Admin → Settings (decided 2026-09-26): this instance's settings in
 * data/settings.yaml, edited from the admin screen, laid over whatever
 * conf/local.php still carries.
 */
final class AdminSettingsTest extends HttpTestCase
{
    private const SITE = 'site_title=Imagistica+Test&site_tagline=Rapoarte&site_base_url=https%3A%2F%2Freports.test.example%2F&site_home_page=site%3Ahome&site_timezone=Europe%2FBucharest';

    private string $timezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->timezone = date_default_timezone_get();
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('owner', password_hash('x', PASSWORD_ARGON2ID), true);
        $users->create('editor', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('reports', GrantRole::Editor)]);
        $this->config['site']['title'] = 'From Local';
    }

    protected function tearDown(): void
    {
        // The instance's strings are per boot; don't leave them to the next test
        reporion_instance([]);
        date_default_timezone_set($this->timezone);
        parent::tearDown();
    }

    public function testOwnerOnly(): void
    {
        self::assertSame(200, $this->request('GET', '/admin/settings', 'owner')->status);
        foreach ([null, 'editor'] as $user) {
            self::assertSame(404, $this->request('GET', '/admin/settings', $user)->status);
            self::assertSame(404, $this->request('POST', '/admin/settings/site', $user, self::SITE)->status);
        }
        self::assertSame(404, $this->request('POST', '/admin/settings/paths', 'owner', 'x=1')->status, 'only the listed sections');
    }

    public function testUntilSavedTheValuesComeFromConfAndSayWhere(): void
    {
        $screen = $this->request('GET', '/admin/settings', 'owner')->body;

        self::assertStringContainsString('value="From Local"', $screen);
        self::assertStringContainsString('from conf/local.php', $screen);
        self::assertFileDoesNotExist($this->dataRoot . '/settings.yaml');
    }

    public function testSavingTheSiteWritesYamlAndRenamesTheSiteEverywhere(): void
    {
        $response = $this->request('POST', '/admin/settings/site', 'owner', self::SITE);

        self::assertSame(302, $response->status);
        self::assertStringEndsWith('/admin/settings?saved=site#site', $response->headers['Location']);
        $yaml = (string) file_get_contents($this->dataRoot . '/settings.yaml');
        self::assertStringContainsString("title: 'Imagistica Test'", $yaml);
        self::assertStringContainsString("base_url: 'https://reports.test.example'", $yaml, 'no trailing slash');
        self::assertStringContainsString('"action":"settings.change"', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'));

        self::assertStringContainsString('<title>Sign in — Imagistica Test</title>', $this->request('GET', '/login', null)->body);
        self::assertStringContainsString('Rapoarte', $this->request('GET', '/login', null)->body);
        self::assertStringContainsString('>Imagistica Test</a>', $this->request('GET', '/admin/settings', 'owner')->body);
        self::assertSame('Europe/Bucharest', date_default_timezone_get());
    }

    public function testABadValueIsRefusedAndNothingIsWritten(): void
    {
        $bad = str_replace('https%3A%2F%2Freports.test.example%2F', 'javascript%3Aalert(1)', self::SITE);

        $response = $this->request('POST', '/admin/settings/site', 'owner', $bad);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('http:// or https://', $response->body);
        self::assertFileDoesNotExist($this->dataRoot . '/settings.yaml');
    }

    public function testPublishingSwitchesAndLimitsOverrideTheConfig(): void
    {
        $this->request('POST', '/admin/settings/publishing', 'owner', 'feeds_namespaces=docs%2C+teaching&export_allow_public_export=1');
        $this->request('POST', '/admin/settings/limits', 'owner', 'pages_trash_purge_days=7&media_max_bytes=2');

        $yaml = (string) file_get_contents($this->dataRoot . '/settings.yaml');
        self::assertStringContainsString("namespaces:\n    - docs\n    - teaching", $yaml);
        self::assertStringContainsString('pseudonymise_public: false', $yaml, 'an unticked box is off');
        self::assertStringContainsString('max_bytes: 2097152', $yaml);
        self::assertStringContainsString('7 days', $this->request('GET', '/admin/trash', 'owner')->body, 'the trash reads the saved limit');
    }

    public function testSitesAndDevicesFeedThePrintedLetterhead(): void
    {
        $this->request('POST', '/admin/settings/sites', 'owner', http_build_query(['sites' => [
            ['code' => 'mioveni', 'name' => 'Spital Test', 'dept' => 'Radiologie', 'address' => '', 'phone' => '', 'devices' => "MV-MR-01 = Aparat RM\n"],
            ['code' => '', 'name' => '', 'devices' => ''],
        ]]));
        (new \Reporion\Storage\FlatFile($this->dataRoot, new \Reporion\Index\Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations')))
            ->create('reports:mri:mioveni:a', ['title' => 'RM', 'visibility' => 'private', 'site' => 'mioveni', 'device' => 'MV-MR-01'], "text\n", 'owner');

        $print = $this->request('GET', '/reports:mri:mioveni:a/print', 'owner')->body;

        self::assertStringContainsString('Spital Test', $print);
        self::assertStringContainsString('Aparat RM', $print);

        $bad = $this->request('POST', '/admin/settings/sites', 'owner', http_build_query(['sites' => [['code' => 'Bad Code!']]]));
        self::assertSame(422, $bad->status);
    }

    public function testTheReportsSectionAndAnAccessionCodeAreSaved(): void
    {
        $this->request('POST', '/admin/settings/reports', 'owner', http_build_query(['reports_modality_namespaces' => "MR = rm\nCT = ct\n"]));
        $this->request('POST', '/admin/settings/sites', 'owner', http_build_query(['sites' => [['code' => 'mioveni', 'name' => 'Spital', 'accession_code' => 'mv', 'devices' => '']]]));

        $yaml = (string) file_get_contents($this->dataRoot . '/settings.yaml');
        self::assertStringContainsString("modality_namespaces:\n    MR: rm\n    CT: ct", $yaml);
        self::assertStringContainsString('accession_code: MV', $yaml, 'upper-cased');
        self::assertSame(422, $this->request('POST', '/admin/settings/reports', 'owner', http_build_query(['reports_modality_namespaces' => 'mr = RM!']))->status);
        self::assertSame(422, $this->request('POST', '/admin/settings/sites', 'owner', http_build_query(['sites' => [['code' => 'x', 'accession_code' => 'M-V']]]))->status);
    }

    public function testAnUploadedIconIsServedAndLinkedAndSvgIsRefused(): void
    {
        $image = imagecreatetruecolor(32, 32);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        self::assertSame(422, $this->request('POST', '/admin/settings/icon', 'owner', '<svg xmlns="http://www.w3.org/2000/svg"/>')->status);
        self::assertSame(404, $this->request('POST', '/admin/settings/icon', 'editor', $png)->status);
        self::assertSame(404, $this->request('GET', '/favicon.ico', null)->status, 'no icon yet');
        self::assertSame(200, $this->request('POST', '/admin/settings/icon', 'owner', $png)->status);

        $icon = $this->request('GET', '/favicon.ico', null);
        self::assertSame(200, $icon->status);
        self::assertSame('image/png', $icon->headers['Content-Type']);
        self::assertSame($png, $icon->body);
        self::assertMatchesRegularExpression('~<link rel="icon" href="/site-icon/icon\.png\?v=[0-9a-f]{10}">~', $this->request('GET', '/login', null)->body);

        $this->request('POST', '/admin/settings/site', 'owner', self::SITE . '&remove_icon=1');
        self::assertSame(404, $this->request('GET', '/favicon.ico', null)->status);
    }

    public function testAnUnreadableSettingsFileFallsBackInsteadOfBreakingTheSite(): void
    {
        file_put_contents($this->dataRoot . '/settings.yaml', "site: [unclosed\n");

        self::assertSame(200, $this->request('GET', '/login', null)->status);
        self::assertStringContainsString('From Local', $this->request('GET', '/login', null)->body);
    }

    private function request(string $method, string $path, ?string $user, string $body = ''): Response
    {
        return Kernel::boot($this->config)->handle(new Request(
            $method,
            $path,
            cookies: $user === null ? [] : ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user)],
            body: $body,
        ));
    }
}
