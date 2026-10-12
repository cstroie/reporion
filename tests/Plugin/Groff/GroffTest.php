<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Plugin\Groff;

use PHPUnit\Framework\TestCase;
use Reporion\Plugin\Groff\Groff;
use Reporion\Plugin\Groff\GroffException;
use Reporion\Plugin\Loader;

/** How groff is run: an argument list, a private directory, its failures named. The program is faked. */
final class GroffTest extends TestCase
{
    protected function setUp(): void
    {
        Loader::registerAutoload(\dirname(__DIR__, 3) . '/plugins', 'groff');
    }

    public function testTheCommandIsAnArgumentListWithTheFontAndNoShell(): void
    {
        $seen = null;
        $dir = null;
        $groff = new Groff('/usr/bin/groff', 'BT', 30, static function (array $argv, string $out, int $timeout) use (&$seen, &$dir): array {
            $seen = [$argv, $timeout, (string) file_get_contents($argv[\count($argv) - 1])];
            $dir = \dirname($out);
            file_put_contents($out, "%PDF-1.7\nfake");

            return [0, ''];
        });

        self::assertSame("%PDF-1.7\nfake", $groff->pdf(".TL\nx\n"));

        [$argv, $timeout, $source] = $seen;
        self::assertSame(['/usr/bin/groff', '-t', '-K', 'utf-8', '-fBT', '-ms', '-T', 'pdf'], \array_slice($argv, 0, 8));
        self::assertMatchesRegularExpression('#^' . preg_quote(sys_get_temp_dir(), '#') . '/reporion-groff-[0-9a-f]{16}/report\.ms$#', $argv[8]);
        self::assertSame(30, $timeout);
        self::assertSame(".TL\nx\n", $source);
        self::assertDirectoryDoesNotExist((string) $dir, 'the private directory is removed');
    }

    /** @dataProvider failures */
    public function testFailuresHaveAFixedReasonAndTheDirectoryIsRemoved(int $exit, string $stderr, ?string $writes, string $reason, string $logHas): void
    {
        $dir = null;
        $groff = new Groff('/usr/bin/groff', 'T', 7, static function (array $argv, string $out) use ($exit, $stderr, $writes, &$dir): array {
            $dir = \dirname($out);
            if ($writes !== null) {
                file_put_contents($out, $writes);
            }

            return [$exit, $stderr];
        });

        try {
            $groff->pdf('.TL');
            self::fail('expected ' . $reason);
        } catch (GroffException $e) {
            self::assertSame($reason, $e->getMessage());
            self::assertStringContainsString($logHas, $e->log);
        }
        self::assertDirectoryDoesNotExist((string) $dir);
    }

    /** @return iterable<string, array{0: int, 1: string, 2: ?string, 3: string, 4: string}> */
    public static function failures(): iterable
    {
        yield 'not runnable' => [-1, '', null, 'no-tool', 'cannot run /usr/bin/groff'];
        yield 'killed' => [-2, 'partial', null, 'timeout', 'killed after 7 s'];
        yield 'a macro missing' => [1, "troff: fatal error: can't find macro file s\n", null, 'failed', "can't find macro file s"];
        yield 'no output' => [0, '', '', 'empty', ''];
        yield 'not a pdf' => [0, '', 'PostScript?', 'empty', ''];
    }

    public function testTheRealRunnerRefusesAMissingOrNonExecutableBinary(): void
    {
        foreach (['/nonexistent/groff', '/etc/passwd'] as $binary) {
            try {
                (new Groff($binary, 'T', 5))->pdf('.TL');
                self::fail($binary);
            } catch (GroffException $e) {
                self::assertSame('no-tool', $e->getMessage());
            }
        }
    }

    public function testWithTheRealGroffAnMsDocumentBecomesAPdf(): void
    {
        $groff = '/usr/bin/groff';
        if (!is_executable($groff) || !is_file('/usr/share/groff/current/tmac/s.tmac') || !is_file('/usr/share/groff/current/font/devpdf/DESC') || !is_executable('/usr/bin/gropdf')) {
            self::markTestSkipped('groff with the ms macros and the PDF device (package groff) is not installed');
        }

        $pdf = (new Groff($groff, 'T', 30))->pdf(".nr LL 17c\n.TL\nTest\n.PP\nHello \\f[B]world\\f[R].\n");

        self::assertStringStartsWith('%PDF', $pdf);
    }
}
