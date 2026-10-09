<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use Reporion\Plugin\Dicom\Scu;
use Reporion\Plugin\Loader;

/**
 * Scu::findStudiesPerServer() with real processes: a stand-in findscu (a
 * shell script that waits, then writes one dcmtk-shaped answer) shows the
 * sites asked side by side, each site's own queries one after another, and
 * a site stopping at its first failure.
 */
final class DicomScuParallelTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Loader::registerAutoload(\dirname(__DIR__, 2) . '/plugins', 'dicom');
        $this->dir = sys_get_temp_dir() . '/reporion-fakescu-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        $log = $this->dir . '/calls.log';
        // Arguments end in host, port: the host names the site; "down" fails as a refused connection
        file_put_contents($this->dir . '/findscu', <<<SH
            #!/bin/sh
            out=''; prev=''; host=''; last=''
            for arg in "\$@"; do
              [ "\$prev" = '-od' ] && out="\$arg"
              prev="\$arg"; host="\$last"; last="\$arg"
            done
            echo "start \$host \$(date +%s.%N)" >> '{$log}'
            sleep 0.6
            echo "end \$host \$(date +%s.%N)" >> '{$log}'
            if [ "\$host" = 'down' ]; then echo 'E: TCP Initialization Error: Connection refused' >&2; exit 1; fi
            printf '<?xml version="1.0"?><file-format><data-set><element name="StudyInstanceUID">1.2.%s</element></data-set></file-format>' "\$host" > "\$out/rsp0001.xml"
            SH);
        chmod($this->dir . '/findscu', 0700);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function testSitesRunSideBySideAndEachSitesQueriesInTurn(): void
    {
        $server = static fn (string $host): array => ['host' => $host, 'port' => 104, 'aet' => 'PACS', 'calling' => 'RP'];
        $two = [['match' => ['ModalitiesInStudy' => 'CT'], 'private' => []], ['match' => ['ModalitiesInStudy' => 'MR'], 'private' => []]];
        $lanes = [
            'a' => ['server' => $server('site-a'), 'queries' => $two],
            'b' => ['server' => $server('site-b'), 'queries' => $two],
            'down' => ['server' => $server('down'), 'queries' => $two],
        ];

        $before = glob(sys_get_temp_dir() . '/reporion-dicom-*') ?: [];
        $began = microtime(true);
        $out = (new Scu($this->dir . '/findscu', 5))->findStudiesPerServer($lanes);
        $took = microtime(true) - $began;

        self::assertSame([[['StudyInstanceUID' => '1.2.site-a']], [['StudyInstanceUID' => '1.2.site-a']]], $out['a']['rows']);
        self::assertNull($out['a']['error']);
        self::assertCount(2, $out['b']['rows']);
        self::assertSame([], $out['down']['rows']);
        self::assertSame('unreachable', $out['down']['error']);

        $calls = array_map(static fn (string $line): array => explode(' ', $line), file($this->dir . '/calls.log', FILE_IGNORE_NEW_LINES) ?: []);
        $hosts = array_count_values(array_map(static fn (array $c): string => $c[1], array_filter($calls, static fn (array $c): bool => $c[0] === 'start')));
        ksort($hosts);
        self::assertSame(['down' => 1, 'site-a' => 2, 'site-b' => 2], $hosts, 'a failed site is not asked again');
        foreach (['site-a', 'site-b'] as $host) {
            $mine = array_values(array_filter($calls, static fn (array $c): bool => $c[1] === $host));
            self::assertSame(['start', 'end', 'start', 'end'], array_column($mine, 0), $host . ': one query at a time');
        }
        self::assertLessThan(2.4, $took, 'five 0.6 s queries, two per site at most: about 1.2 s side by side, 3 s one after another');
        self::assertSame($before, glob(sys_get_temp_dir() . '/reporion-dicom-*') ?: [], 'no answer directory left behind');
    }
}
