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

        self::assertStringContainsString('<title>Sign in: Imagistica Test</title>', $this->request('GET', '/login', null)->body);
        self::assertStringContainsString('Rapoarte', $this->request('GET', '/login', null)->body);
        self::assertStringContainsString('<span class="wk-brand-text">Imagistica Test</span></a>', $this->request('GET', '/admin/settings', 'owner')->body);
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

    public function testTheBackupSectionIsSavedAndTheScheduleNeedsATarget(): void
    {
        $form = ['backup_host' => 'backup.example.org', 'backup_port' => '22', 'backup_user' => 'backup', 'backup_remote_dir' => '/srv/backups/reporion', 'backup_ssh_key' => '', 'backup_bwlimit' => '', 'backup_keep' => 'month', 'backup_interval_hours' => '24'];

        $noTarget = $this->request('POST', '/admin/settings/backup', 'owner', http_build_query(['backup_enabled' => '1', 'backup_keep' => 'week', 'backup_interval_hours' => '24']));
        self::assertSame(422, $noTarget->status);
        self::assertFileDoesNotExist($this->dataRoot . '/settings.yaml');

        self::assertSame(302, $this->request('POST', '/admin/settings/backup', 'owner', http_build_query($form + ['backup_enabled' => '1']))->status);
        $yaml = (string) file_get_contents($this->dataRoot . '/settings.yaml');
        self::assertStringContainsString("backup:\n  enabled: true\n", $yaml);
        self::assertStringContainsString('  host: backup.example.org', $yaml);
        self::assertStringContainsString('  transport: ssh', $yaml);
        self::assertStringContainsString('keep: month', $yaml);
        self::assertStringContainsString('bin/reporion backup:rsync --if-due', $this->request('GET', '/admin/settings', 'owner')->body);

        self::assertSame(422, $this->request('POST', '/admin/settings/backup', 'owner', http_build_query(['backup_host' => '-oProxyCommand=x'] + $form))->status);
    }

    public function testTheReportsSectionIsSaved(): void
    {
        $this->request('POST', '/admin/settings/reports', 'owner', http_build_query(['reports_modality_namespaces' => "MR = rm\nCT = ct\n"]));

        $yaml = (string) file_get_contents($this->dataRoot . '/settings.yaml');
        self::assertStringContainsString("modality_namespaces:\n    MR: rm\n    CT: ct", $yaml);
        self::assertSame(422, $this->request('POST', '/admin/settings/reports', 'owner', http_build_query(['reports_modality_namespaces' => 'mr = RM!']))->status);
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

    public function testAnUnreadableSettingsFileIsNeverSavedOver(): void
    {
        $file = $this->dataRoot . '/settings.yaml';
        $broken = "site: [unclosed\nai:\n  servers: {1: {endpoint: 'http://ai.test'}}\n";
        file_put_contents($file, $broken);

        $response = $this->request('POST', '/admin/settings/site', 'owner', self::SITE);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('does not read as YAML', $response->body);
        self::assertSame($broken, file_get_contents($file), 'every setting it holds is still there to fix by hand');
    }

    public function testTheMcpSectionKeepsTheGateValueAndShowsTheRule(): void
    {
        $page = $this->request('GET', '/admin/settings', 'owner')->body;
        self::assertStringContainsString('id="mcp"', $page);
        self::assertStringNotContainsString('X-Access-Token" !=', $page, 'no rule before a value is saved');
        preg_match('/name="mcp_gate"[^>]*placeholder="([^"]+)"/', $page, $first);
        preg_match('/name="mcp_gate"[^>]*placeholder="([^"]+)"/', $this->request('GET', '/admin/settings', 'owner')->body, $second);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', $first[1]);
        self::assertNotSame($first[1], $second[1], 'a new suggestion at every load');

        $bad = $this->request('POST', '/admin/settings/mcp', 'owner', 'mcp_access=token&mcp_gate=short');
        self::assertSame(422, $bad->status);
        self::assertFileDoesNotExist($this->dataRoot . '/settings.yaml');

        $ok = $this->request('POST', '/admin/settings/mcp', 'owner', 'mcp_access=token&mcp_gate=' . $first[1]);
        self::assertSame(302, $ok->status);
        self::assertStringContainsString('gate: ' . $first[1], (string) file_get_contents($this->dataRoot . '/settings.yaml'));
        $shown = $this->request('GET', '/admin/settings', 'owner')->body;
        self::assertStringContainsString('$REQUEST_HEADER["X-Access-Token"] != "' . $first[1] . '"', $shown);
        self::assertStringContainsString('value="' . $first[1] . '"', $shown);

        // PHP does not check the header: a request without it is judged by its token alone
        $none = Kernel::boot($this->config)->handle(new Request('POST', '/mcp', body: '{}', remoteAddr: '127.0.0.1'));
        self::assertSame(401, $none->status);
    }

    public function testTheMcpSectionShowsTheAddressToGiveTheClient(): void
    {
        $session = ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('owner')];
        $page = Kernel::boot($this->config)->handle(new Request('GET', '/admin/settings', cookies: $session, secure: true, host: 'wiki.example.org', basePath: '/reporion'))->body;
        self::assertStringContainsString('value="https://wiki.example.org/reporion/mcp" readonly', $page, 'the address this page came by');

        $this->request('POST', '/admin/settings/site', 'owner', str_replace('reports.test.example%2F', 'reports.example.org%2Frep%2F', self::SITE));
        $page = Kernel::boot($this->config)->handle(new Request('GET', '/admin/settings', cookies: $session, host: 'other.example'))->body;
        self::assertStringContainsString('value="https://reports.example.org/rep/mcp" readonly', $page, 'site.base_url wins, no doubled slash');
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
