<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Reporion\Service\InstanceSettings;
use Reporion\Support\Devices;

/**
 * A site's devices in both shapes: a plain name, or {name, pacs} once a
 * PACS scanner is linked, and the link itself (InstanceSettings).
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

    public function testAStationWithSeveralValuesMatchesTheLinkMadeOnItsFirst(): void
    {
        $site = ['devices' => ['LG-MR-01' => ['name' => 'Siemens 3T', 'pacs' => ['AWP196388']], 'LG-CT-01' => ['name' => 'CT', 'pacs' => ['GE SIGNA / MRC1']]]];

        self::assertSame('LG-MR-01', Devices::forPacs($site, 'AWP196388'));
        self::assertSame('LG-MR-01', Devices::forPacs($site, 'AWP196388\\PXD Server'), 'the same scanner, its station given with a second value');
        self::assertSame('LG-CT-01', Devices::forPacs($site, 'GE SIGNA / MRC1\\X'), 'only the station is cut');
        self::assertNull(Devices::forPacs($site, 'AWP1963889\\PXD Server'), 'a first value that differs is another scanner');
        self::assertNull(Devices::forPacs($site, 'OTHER MAKER SIGNA / MRC1\\X'), 'the maker and model still have to match');
        self::assertSame('GE SIGNA / MRC1', Devices::primary('GE SIGNA / MRC1\\X'));
        self::assertSame('AWP196388', Devices::primary('AWP196388'));

        // A link made on the two-valued form is exactly that form; the exact link wins when both exist
        $two = ['devices' => ['A' => ['name' => 'A', 'pacs' => ['AWP196388\\PXD Server']], 'B' => ['name' => 'B', 'pacs' => ['AWP196388']]]];
        self::assertSame('A', Devices::forPacs($two, 'AWP196388\\PXD Server'));
        self::assertSame('B', Devices::forPacs($two, 'AWP196388'));
        self::assertNull(Devices::forPacs(['devices' => ['A' => ['name' => 'A', 'pacs' => ['AWP196388\\PXD Server']]]], 'AWP196388'));
    }

    public function testAnInstitutionTextTellsTheDeviceOnlyWhenExactlyOneSaysSo(): void
    {
        $site = ['devices' => [
            'SC-CT-01' => ['name' => 'Somatom 16', 'inst' => ['Somatom16']],
            'SC-CT-02' => ['name' => 'Alt CT', 'inst' => ['Emotion', 'Alt  Spital']],
            'SC-MR-01' => 'Aera',
        ]];

        self::assertSame('SC-CT-01', Devices::forInstitution($site, 'SPITAL TEST"GR. X"/somatom16'), 'contains, any case');
        self::assertSame('SC-CT-02', Devices::forInstitution($site, 'spital   alt spital / x'), 'spacing ignored');
        self::assertNull(Devices::forInstitution($site, 'Somatom16 Emotion'), 'two devices say so: no guess');
        self::assertNull(Devices::forInstitution($site, 'Another place'));
        self::assertNull(Devices::forInstitution($site, ''));
        self::assertNull(Devices::forInstitution(['devices' => ['A' => 'plain']], 'anything'));
    }

    public function testInstTextsRoundTripAndAnEntryWithNeitherStaysAPlainName(): void
    {
        $entry = ['name' => 'Aparat', 'pacs' => [], 'inst' => ['Somatom16']];
        self::assertSame(['name' => 'Aparat', 'inst' => ['Somatom16']], Devices::dump($entry), 'only the filled lists are written');
        self::assertSame(['name' => 'A', 'pacs' => ['X'], 'inst' => ['Y']], Devices::dump(['name' => 'A', 'pacs' => ['X'], 'inst' => ['Y']]));
        self::assertSame('Aparat', Devices::dump(['name' => 'Aparat', 'pacs' => [], 'inst' => []]));
        self::assertSame(['Somatom16'], Devices::entries(['devices' => ['A' => $entry]])['A']['inst']);
        self::assertSame([], Devices::entries(['devices' => ['A' => 'plain']])['A']['inst']);
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

    public function testASerialNumberIsAnExtraKeyAndLinkingKeepsBoth(): void
    {
        $site = ['devices' => ['GA-MR-05' => ['name' => 'Sempra', 'pacs' => ['SIEMENS MAGNETOM Sempra / AWP181840', 'SN 181840']]]];

        self::assertSame(['SIEMENS MAGNETOM Sempra / AWP181840', 'SN 181840'], Devices::keysFor('SIEMENS MAGNETOM Sempra / AWP181840 · SN 181840'));
        self::assertSame(['A / B'], Devices::keysFor('A / B'));
        self::assertSame('GA-MR-05', Devices::forPacs($site, 'SIEMENS MAGNETOM Sempra / AWP181840'), 'a name without serial, as a PACS query gives it');
        self::assertSame('GA-MR-05', Devices::forPacs($site, 'SIEMENS MAGNETOM Sempra / RENAMED · SN 181840'), 'the station was renamed');
        self::assertNull(Devices::forPacs($site, 'SIEMENS MAGNETOM Sempra / RENAMED · SN 999'));
        self::assertNull(Devices::forPacs($site, 'SIEMENS MAGNETOM Sempra / RENAMED'));
    }

    public function testAStationAloneOrABareSerialInPacsFindsTheDevice(): void
    {
        $site = ['devices' => ['MD-MR-05' => ['name' => 'Sempra', 'pacs' => ['AWP181840']], 'MD-MR-06' => ['name' => 'Other', 'pacs' => ['777']]]];

        self::assertSame('MD-MR-05', Devices::forPacs($site, 'Siemens Healthineers MAGNETOM Sempra / AWP181840 · SN 181840'));
        self::assertSame('MD-MR-05', Devices::forPacs($site, 'Siemens Healthineers MAGNETOM Sempra / AWP181840'), 'a query result has no serial');
        self::assertSame('MD-MR-05', Devices::forPacs($site, 'SIEMENS Aera / AWP181840\\PXD Server'), 'the first station value');
        self::assertSame('MD-MR-06', Devices::forPacs($site, 'X Y / Z · SN 777'));
        self::assertNull(Devices::forPacs($site, 'Siemens Healthineers MAGNETOM Sempra / AWP999'));
    }
}
