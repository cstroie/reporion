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
        foreach ([['host' => '-oconfig'], ['host' => ''], ['aet' => '--help'], ['calling' => '-v']] as $bad) {
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

        $this->scu()->echo($good);
        self::assertCount(1, $this->ran);
        self::assertSame('192.0.2.10', $this->ran[0][\count($this->ran[0]) - 2]);
    }

    public function testAdminNeverSavesAHostThatStartsWithADash(): void
    {
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 2) . '/plugins/dicom/plugin.json'), true);
        $host = $manifest['settings']['servers']['columns']['host'];

        self::assertSame('pacs.example.org', Manifest::valid($host, 'pacs.example.org'));
        foreach (['-oProxyCommand', '--aetitle'] as $bad) {
            try {
                Manifest::valid($host, $bad);
                self::fail('accepted ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                // expected
            }
        }
    }
}
