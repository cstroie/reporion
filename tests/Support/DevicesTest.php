<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Reporion\Service\InstanceSettings;
use Reporion\Support\Devices;

/**
 * A site's devices in both shapes — a plain name, or {name, pacs} once a
 * PACS scanner is linked — and the link itself (InstanceSettings).
 */
final class DevicesTest extends TestCase
{
    private const SITE = ['accession_code' => 'GA', 'devices' => [
        'GA-CT-01' => 'Siemens Emotion 16',
        'GA-MR-02' => ['name' => 'Virtutii GE 1.5T', 'pacs' => ['GE MEDICAL SYSTEMS SIGNA HDxt / MRC25120']],
    ]];

    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            @rmdir($this->dir);
        }
    }

    public function testBothShapesReadAsCodeAndName(): void
    {
        self::assertSame(['GA-CT-01' => 'Siemens Emotion 16', 'GA-MR-02' => 'Virtutii GE 1.5T'], Devices::names(self::SITE));
        self::assertSame([], Devices::names([]));
    }

    public function testAScannerIsFoundByItsPacsNameWhateverTheCaseAndSpacing(): void
    {
        self::assertSame('GA-MR-02', Devices::forPacs(self::SITE, 'ge medical systems  SIGNA HDxt / mrc25120'));
        self::assertNull(Devices::forPacs(self::SITE, 'GE MEDICAL SYSTEMS SIGNA HDxt / OTHER'));
        self::assertNull(Devices::forPacs(self::SITE, ''));
    }

    public function testANewCodeFollowsTheSitesOwnNumbering(): void
    {
        self::assertSame('GA-MR-01', Devices::suggestCode(self::SITE, 'MR', 'x'));
        self::assertSame('GA-CT-02', Devices::suggestCode(self::SITE, 'CT', 'x'));
        self::assertSame('MV-CT-01', Devices::suggestCode([], 'CT', 'mv'), 'no device yet: the fallback (the accession code)');
    }

    public function testAWrittenEntryIsAPlainNameUntilAScannerIsLinked(): void
    {
        self::assertSame('Aparat', Devices::dump(['name' => 'Aparat', 'pacs' => []]));
        self::assertSame(['name' => 'Aparat', 'pacs' => ['X']], Devices::dump(['name' => 'Aparat', 'pacs' => ['X']]));
        self::assertSame('A B', Devices::key(" A |\n B;"), 'one line, no list separators');
    }

    public function testLinkingMovesAScannerToOneDeviceAndKeepsTheRest(): void
    {
        $this->dir = sys_get_temp_dir() . '/reporion-devices-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $settings = new InstanceSettings($this->dir);
        $sites = ['scuc' => self::SITE, 'other' => ['name' => 'Other', 'devices' => []]];

        $settings->linkPacsDevice($sites, 'scuc', 'GA-CT-01', '', 'GE MEDICAL SYSTEMS SIGNA HDxt / MRC25120', false);
        $stored = $settings->load()['sites'];
        self::assertSame(['name' => 'Siemens Emotion 16', 'pacs' => ['GE MEDICAL SYSTEMS SIGNA HDxt / MRC25120']], $stored['scuc']['devices']['GA-CT-01']);
        self::assertSame('Virtutii GE 1.5T', $stored['scuc']['devices']['GA-MR-02'], 'one scanner, one device: it left the other one');
        self::assertSame('Other', $stored['other']['name']);

        $settings->linkPacsDevice($stored, 'scuc', 'GA-MR-03', 'Otopeni Siemens 3T', 'SIEMENS Skyra / AWP1', true);
        self::assertSame(['name' => 'Otopeni Siemens 3T', 'pacs' => ['SIEMENS Skyra / AWP1']], $settings->load()['sites']['scuc']['devices']['GA-MR-03']);

        foreach ([['scuc', 'GA-MR-03', 'Again', true], ['scuc', 'GA-XX-99', '', false], ['atlantis', 'A-1', 'x', true], ['scuc', 'bad code', 'x', true]] as [$site, $code, $name, $create]) {
            try {
                $settings->linkPacsDevice($settings->load()['sites'], $site, $code, $name, 'SIEMENS Skyra / AWP1', $create);
                self::fail('expected a refusal: ' . $site . ' ' . $code);
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
