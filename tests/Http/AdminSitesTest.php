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
use Reporion\Service\InstanceSettings;
use Reporion\Storage\FileTree;

/**
 * Admin → Sites: a card per site, each saved on its own (owner-only), the
 * devices as rows with their PACS scanner names and institution texts as
 * ticked chips, the sites kept in data/settings.yaml.
 */
final class AdminSitesTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('owner', password_hash('x', PASSWORD_ARGON2ID), true);
        $users->create('editor', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('reports', GrantRole::Editor)]);
    }

    protected function tearDown(): void
    {
        reporion_instance([]);
        parent::tearDown();
    }

    public function testEveryRouteIsOwnerOnly(): void
    {
        $this->add('mioveni', 'Spital');
        self::assertSame(200, $this->request('GET', '/admin/sites', 'owner')->status);
        foreach ([null, 'editor'] as $user) {
            self::assertSame(404, $this->request('GET', '/admin/sites', $user)->status);
            self::assertSame(404, $this->request('POST', '/admin/sites', $user, 'code=x&name=X')->status);
            self::assertSame(404, $this->request('POST', '/admin/sites/mioveni', $user, 'name=X')->status);
            self::assertSame(404, $this->request('POST', '/admin/sites/mioveni/remove', $user)->status);
        }
        self::assertSame(404, $this->request('POST', '/admin/sites/nosuch', 'owner', 'name=X')->status, 'a site that is not there');
        self::assertSame(404, $this->request('POST', '/admin/sites/nosuch/remove', 'owner')->status);
        self::assertSame(404, $this->request('POST', '/admin/settings/sites', 'owner', 'x=1')->status, 'no longer a settings section');
        self::assertStringNotContainsString('id="sites"', $this->request('GET', '/admin/settings', 'owner')->body);
    }

    public function testAddASiteOpensItsCardAndRefusesABadOrTakenCode(): void
    {
        $added = $this->request('POST', '/admin/sites', 'owner', 'code=Mioveni&name=Spital+Test');
        self::assertSame(302, $added->status);
        self::assertStringEndsWith('/admin/sites?saved=mioveni&open=mioveni#site-mioveni', $added->headers['Location']);
        self::assertSame('Spital Test', $this->stored()['mioveni']['name']);

        $screen = $this->request('GET', '/admin/sites', 'owner', query: ['saved' => 'mioveni', 'open' => 'mioveni'])->body;
        self::assertStringContainsString('<details class="wk-site" name="site" id="site-mioveni" open>', $screen);
        self::assertStringContainsString('0 reports · 0 devices', $screen);
        self::assertStringNotContainsString('id="site-mioveni" open>', $this->request('GET', '/admin/sites', 'owner')->body, 'entering the page, every site is closed');
        self::assertStringContainsString('role="status"', $screen, 'saved, in the card');

        $bad = $this->request('POST', '/admin/sites', 'owner', 'code=' . rawurlencode('Bad Code!') . '&name=X');
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('value="bad code!"', $bad->body, 'what was typed comes back');
        self::assertSame(422, $this->request('POST', '/admin/sites', 'owner', 'code=mioveni&name=Again')->status, 'taken');
        self::assertSame(['mioveni'], array_keys($this->stored()));
    }

    public function testSavingOneSiteKeepsTheOthersAndFeedsThePrintedLetterhead(): void
    {
        $this->add('mioveni', 'Spital Test');
        $this->add('scuc', 'Alt Spital');

        $saved = $this->request('POST', '/admin/sites/mioveni', 'owner', http_build_query([
            'name' => 'Spital Test', 'dept' => 'Radiologie', 'address' => 'Str. 1', 'phone' => '0123', 'letterhead' => '1',
            'devices' => [['code' => 'MV-MR-01', 'name' => 'Aparat RM'], ['code' => '', 'name' => '']],
        ]));
        self::assertSame(302, $saved->status);
        $sites = $this->stored();
        self::assertSame('Radiologie', $sites['mioveni']['dept']);
        self::assertSame(['MV-MR-01' => 'Aparat RM'], $sites['mioveni']['devices'], 'a device with nothing linked stays a plain name; the blank row is skipped');
        self::assertSame('Alt Spital', $sites['scuc']['name'], 'the other site is untouched');

        (new FileTree($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations')))
            ->create('reports:mri:mioveni:260101-test-subject', ['title' => 'RM', 'visibility' => 'private', 'site' => 'mioveni', 'device' => 'MV-MR-01'], "text\n", 'owner');
        $print = $this->request('GET', '/export/reports:mri:mioveni:260101-test-subject.html', 'owner')->body;
        self::assertStringContainsString('class="lh"', $print);
        self::assertStringContainsString('Spital Test', $print);
        self::assertStringContainsString('Aparat RM', $print);
    }

    public function testTheLetterheadSwitchIsOffByDefaultAndRoundTripsThroughTheForm(): void
    {
        $this->add('mioveni', 'Spital Test');
        self::assertFalse($this->stored()['mioveni']['letterhead'] ?? false, 'a new site prints no letterhead');
        $page = $this->request('GET', '/admin/sites', 'owner')->body;
        self::assertMatchesRegularExpression('#<input type="checkbox" name="letterhead" value="1" data-lh-switch>#', $page);

        $this->request('POST', '/admin/sites/mioveni', 'owner', http_build_query(['name' => 'Spital Test', 'letterhead' => '1']));
        self::assertTrue($this->stored()['mioveni']['letterhead']);
        self::assertMatchesRegularExpression('#name="letterhead" value="1" checked data-lh-switch#', $this->request('GET', '/admin/sites', 'owner')->body);

        // An unticked box posts nothing
        $this->request('POST', '/admin/sites/mioveni', 'owner', http_build_query(['name' => 'Spital Test']));
        self::assertFalse($this->stored()['mioveni']['letterhead']);
    }

    public function testAScannerCanBeTypedWithAPrefix(): void
    {
        $this->add('scuc', 'Spital');
        $this->request('POST', '/admin/sites/scuc', 'owner', http_build_query(['name' => 'Spital', 'devices' => [
            ['code' => 'SC-MR-05', 'name' => 'Sempra', 'pacs' => ['SIEMENS Aera / MR1'], 'link_new' => 'pacs:AWP181840; sn:181840; inst: Medima Health ; '],
        ]]));

        self::assertSame(['name' => 'Sempra', 'pacs' => ['SIEMENS Aera / MR1', 'AWP181840', 'SN 181840'], 'inst' => ['Medima Health']], $this->stored()['scuc']['devices']['SC-MR-05']);

        $page = $this->request('GET', '/admin/sites', 'owner')->body;
        self::assertStringContainsString('pacs:AWP181840', $page);
        self::assertStringContainsString('sn:181840', $page);
        self::assertStringContainsString('inst:Medima Health', $page);
    }

    public function testADevicesLinksAreChipsThatStayTickedUntilUntickedAndTextsAreAdded(): void
    {
        $this->add('scuc', 'Spital');
        $this->request('POST', '/admin/sites/scuc', 'owner', http_build_query(['name' => 'Spital', 'devices' => [
            ['code' => 'SC-CT-01', 'name' => 'Somatom 16', 'link_new' => 'Somatom16; inst:Sala 2'],
            ['code' => 'SC-MR-01', 'name' => 'Aera', 'pacs' => ['SIEMENS Aera / MR1', 'SIEMENS Aera / MR2'], 'link_new' => 'Aera1'],
            ['code' => 'SC-CT-02', 'name' => 'Plain'],
        ]]));
        $devices = $this->stored()['scuc']['devices'];
        self::assertSame(['name' => 'Somatom 16', 'inst' => ['Somatom16', 'Sala 2']], $devices['SC-CT-01']);
        self::assertSame(['name' => 'Aera', 'pacs' => ['SIEMENS Aera / MR1', 'SIEMENS Aera / MR2'], 'inst' => ['Aera1']], $devices['SC-MR-01']);
        self::assertSame('Plain', $devices['SC-CT-02']);

        // Shown back as ticked chips: a save of the form as it stands keeps them
        $screen = $this->request('GET', '/admin/sites', 'owner')->body;
        self::assertStringContainsString('name="devices[1][pacs][]" value="SIEMENS Aera / MR1" checked>', $screen);
        self::assertStringContainsString('name="devices[0][inst][]" value="Sala 2" checked>', $screen);

        // One scanner unticked (left out of the post), one device ticked for removal
        $this->request('POST', '/admin/sites/scuc', 'owner', http_build_query(['name' => 'Spital', 'devices' => [
            ['code' => 'SC-CT-01', 'name' => 'Somatom 16', 'inst' => ['Somatom16', 'Sala 2']],
            ['code' => 'SC-MR-01', 'name' => 'Aera', 'pacs' => ['SIEMENS Aera / MR2'], 'inst' => ['Aera1']],
            ['code' => 'SC-CT-02', 'name' => 'Plain', 'remove' => '1'],
        ]]));
        $devices = $this->stored()['scuc']['devices'];
        self::assertSame(['name' => 'Aera', 'pacs' => ['SIEMENS Aera / MR2'], 'inst' => ['Aera1']], $devices['SC-MR-01']);
        self::assertArrayNotHasKey('SC-CT-02', $devices);
    }

    public function testABadDeviceIsRefusedAndTheCardShowsWhatWasTyped(): void
    {
        $this->add('scuc', 'Spital');
        $dup = $this->request('POST', '/admin/sites/scuc', 'owner', http_build_query(['name' => 'Typed Name', 'devices' => [
            ['code' => 'SC-CT-01', 'name' => 'A'], ['code' => 'sc-ct-01', 'name' => 'B'],
        ]]));
        self::assertSame(422, $dup->status);
        self::assertStringContainsString('listed twice', $dup->body);
        self::assertStringContainsString('value="Typed Name"', $dup->body, 'typed values come back');
        self::assertStringContainsString('value="sc-ct-01"', $dup->body);
        self::assertSame([], $this->stored()['scuc']['devices'], 'nothing written');

        self::assertSame(422, $this->request('POST', '/admin/sites/scuc', 'owner', http_build_query(['devices' => [['code' => '-bad', 'name' => 'X']]]))->status);
        self::assertSame(422, $this->request('POST', '/admin/sites/scuc', 'owner', http_build_query(['accession_code' => 'M-V']))->status);
    }

    public function testTheAccessionCodeIsUpperCased(): void
    {
        $this->add('mioveni', 'Spital');
        $this->request('POST', '/admin/sites/mioveni', 'owner', http_build_query(['name' => 'Spital', 'accession_code' => 'mv']));

        self::assertSame('MV', $this->stored()['mioveni']['accession_code']);
        self::assertStringNotContainsString('Next (', $this->request('GET', '/admin/sites', 'owner')->body, 'no preview: it walks every page on disk');
    }

    public function testRemovingASiteReportsStillNameNeedsConfirmation(): void
    {
        $this->add('mioveni', 'Spital');
        $this->add('scuc', 'Alt');
        (new FileTree($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations')))
            ->create('reports:mri:mioveni:260101-test-subject', ['title' => 'RM', 'visibility' => 'private', 'site' => 'mioveni'], "text\n", 'owner');

        self::assertStringContainsString('1 report ·', $this->request('GET', '/admin/sites', 'owner')->body, 'counted');
        $refused = $this->request('POST', '/admin/sites/mioveni/remove', 'owner');
        self::assertSame(422, $refused->status);
        self::assertStringContainsString('named by 1 reports', $refused->body);
        self::assertArrayHasKey('mioveni', $this->stored());

        self::assertSame(302, $this->request('POST', '/admin/sites/mioveni/remove', 'owner', 'confirm=1')->status);
        self::assertSame(['scuc'], array_keys($this->stored()));
        self::assertSame(302, $this->request('POST', '/admin/sites/scuc/remove', 'owner')->status, 'a site no report names goes without a tick');
        self::assertSame([], $this->stored());
    }

    public function testTheFirstSaveCopiesConfsSitesIntoTheFileAndTheAuditNamesTheSite(): void
    {
        $this->config['sites'] = ['a' => ['name' => 'Site A', 'devices' => ['A-1' => 'One']], 'b' => ['name' => 'Site B']];
        self::assertStringContainsString('conf/local.php', $this->request('GET', '/admin/sites', 'owner')->body);

        $this->request('POST', '/admin/sites/a', 'owner', http_build_query(['name' => 'Site A+', 'devices' => [['code' => 'A-1', 'name' => 'One']]]));

        $sites = $this->stored();
        self::assertSame('Site A+', $sites['a']['name']);
        self::assertSame('Site B', $sites['b']['name'], 'the other conf site comes along');
        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"site":"a"', $audit);
        self::assertStringContainsString('"section":"sites"', $audit);
    }

    private function add(string $code, string $name): void
    {
        $this->request('POST', '/admin/sites', 'owner', http_build_query(['code' => $code, 'name' => $name]));
    }

    /** @return array<string, array<string, mixed>> */
    private function stored(): array
    {
        return (new InstanceSettings($this->dataRoot))->load()['sites'] ?? [];
    }

    /** @param array<string, string> $query */
    private function request(string $method, string $path, ?string $user, string $body = '', array $query = []): Response
    {
        return Kernel::boot($this->config)->handle(new Request(
            $method,
            $path,
            query: $query,
            cookies: $user === null ? [] : ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user)],
            body: $body,
        ));
    }
}
