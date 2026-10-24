<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Plugin;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Reporion\Plugin\Dicom\DicomException;
use Reporion\Plugin\Dicom\Scu;
use Reporion\Plugin\Loader;
use Reporion\Plugin\Manifest;

/**
 * The PACS host and AE titles are arguments of their own on findscu's,
 * echoscu's and storescu's command lines: one that starts with "-" would be
 * read as an option, so no tool is run with it, and Admin → Plugins never
 * saves such a host in the first place. No dcmtk needed: the runner is faked.
 */
final class DicomScuArgumentsTest extends TestCase
{
    /** @var list<list<string>> */
    private array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();
        Loader::registerAutoload(\dirname(__DIR__, 2) . '/plugins', 'dicom');
    }

    private function scu(): Scu
    {
        return new Scu('/usr/bin/findscu', 5, function (array $argv): array {
            $this->ran[] = $argv;

            return [0, ''];
        });
    }

    public function testAnOptionLookingHostOrAeTitleRunsNothing(): void
    {
        $good = ['host' => '192.0.2.10', 'port' => 104, 'aet' => 'PACS', 'calling' => 'REPORION'];
        foreach ([
            ['host' => '-oconfig'], ['host' => ''], ['aet' => '--help'], ['calling' => '-v'],
            // `@file` makes a DCMTK tool read more arguments from that file
            ['host' => '@/etc/passwd'], ['aet' => '@args.txt'], ['calling' => '@x'],
            // Not a host, not an AE title, not a port
            ['host' => 'pacs;reboot'], ['host' => 'a b'], ['aet' => 'A\\B'], ['aet' => 'SEVENTEEN_CHARS_XX'], ['calling' => ' LEADING'],
            ['port' => 0], ['port' => 70000], ['port' => '104; x'], ['port' => null],
        ] as $bad) {
            foreach (['find' => fn (Scu $s) => $s->findStudies($bad + $good, ['StudyDate' => '20260101']), 'echo' => fn (Scu $s) => $s->echo($bad + $good), 'store' => fn (Scu $s) => $s->store($bad + $good, 'x')] as $name => $call) {
                try {
                    $call($this->scu());
                    self::fail($name . ' ran with ' . json_encode($bad));
                } catch (DicomException $e) {
                    self::assertSame('failed', $e->getMessage());
                }
            }
        }
        self::assertSame([], $this->ran);

        // What a real setup holds still runs: a name, an IPv6 address, an AE title with a space, a port as text
        foreach ([['host' => 'pacs-1.example.org'], ['host' => '::1'], ['aet' => 'MY PACS'], ['calling' => 'RP_MIOVENI.1'], ['port' => '11112']] as $ok) {
            $this->ran = [];
            $this->scu()->echo($ok + $good);
            self::assertCount(1, $this->ran, json_encode($ok));
        }
        $this->ran = [];

        $this->scu()->echo($good);
        self::assertCount(1, $this->ran);
        self::assertSame('192.0.2.10', $this->ran[0][\count($this->ran[0]) - 2]);
    }

    public function testAdminNeverSavesAnAeTitleThatStartsWithADashOrAnAt(): void
    {
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 2) . '/plugins/dicom/plugin.json'), true);
        foreach (['aet', 'calling_aet'] as $column) {
            $def = $manifest['settings']['servers']['columns'][$column];
            self::assertSame('MY PACS', Manifest::valid($def, 'MY PACS'));
            foreach (['-v', '@file'] as $bad) {
                try {
                    Manifest::valid($def, $bad);
                    self::fail($column . ' accepted ' . json_encode($bad));
                } catch (InvalidArgumentException) {
                    // expected
                }
            }
        }
    }

    public function testAdminNeverSavesAHostThatStartsWithADash(): void
    {
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 2) . '/plugins/dicom/plugin.json'), true);
        $host = $manifest['settings']['servers']['columns']['host'];

        self::assertSame('pacs.example.org', Manifest::valid($host, 'pacs.example.org'));
        foreach (['-oProxyCommand', '--aetitle', '@/etc/hosts'] as $bad) {
            try {
                Manifest::valid($host, $bad);
                self::fail('accepted ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                // expected
            }
        }
    }
}
