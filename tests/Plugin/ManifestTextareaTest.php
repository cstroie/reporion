<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Plugin;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Reporion\Plugin\Manifest;

/** The `textarea` plugin-setting type (2026-10-12): several lines, checked line by line when the manifest says so. */
final class ManifestTextareaTest extends TestCase
{
    private const SPEC = ['type' => 'textarea', 'default' => ".nr LL 17c\n.ds FAM {font}", 'line_pattern' => '#^\.(nr|ds) [A-Za-z]+( .*)?$#'];

    public function testSeveralLinesAreKeptAsTyped(): void
    {
        self::assertSame("  .nr LL 17c\n\n  .ds FAM T", Manifest::valid(['type' => 'textarea'], "  .nr LL 17c\r\n\r\n  .ds FAM T\r\n"), 'the indentation is the text');
        self::assertSame("a\nb", Manifest::valid(['type' => 'textarea'], "a\r\nb\r\n"), 'CRLF becomes LF, the trailing newline goes');
        self::assertSame("a\tb\nc", Manifest::valid(['type' => 'textarea'], "a\tb\nc"), 'a tab is text');
        self::assertSame('', Manifest::valid(['type' => 'textarea'], ''));
    }

    public function testControlCharactersAndLengthAreRefused(): void
    {
        foreach ([["a\x00b"], ["a\x1bb"], [str_repeat('x', Manifest::TEXTAREA_MAX + 1)]] as [$bad]) {
            try {
                Manifest::valid(['type' => 'textarea'], $bad);
                self::fail('refused');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        Manifest::valid(['type' => 'textarea', 'max' => 10], str_repeat('x', 11));
    }

    public function testEveryNonEmptyLineMustMatchTheLinePattern(): void
    {
        self::assertSame(".nr LL 17c\n\n.ds FAM T", Manifest::valid(self::SPEC, ".nr LL 17c\n\n.ds FAM T"), 'blank lines are fine');
        foreach ([".nr LL 17c\n.so /etc/passwd", ".sy id", "plain text"] as $bad) {
            try {
                Manifest::valid(self::SPEC, $bad);
                self::fail($bad . ' must be refused');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testTheDefaultAppliesAndTheManifestChecksItsOwnLinePattern(): void
    {
        $dir = sys_get_temp_dir() . '/reporion-manifest-' . bin2hex(random_bytes(5));
        mkdir($dir . '/demo', 0775, true);
        $write = static fn (array $settings): Manifest => Manifest::fromFile((static function () use ($dir, $settings): string {
            file_put_contents($dir . '/demo/plugin.json', (string) json_encode(['id' => 'demo', 'api' => 1, 'settings' => $settings]));

            return $dir . '/demo/plugin.json';
        })(), 'demo');
        try {
            $manifest = $write(['preamble' => self::SPEC]);
            self::assertSame(".nr LL 17c\n.ds FAM {font}", $manifest->settingValues([])['preamble']);
            self::assertSame(".nr LL 18c", $manifest->settingValues(['preamble' => ".nr LL 18c"])['preamble']);
            self::assertSame(".nr LL 17c\n.ds FAM {font}", $manifest->settingValues(['preamble' => ".so /etc/passwd"])['preamble'], 'a stored value that does not validate falls back to the default');

            $this->expectException(InvalidArgumentException::class);
            $write(['x' => ['type' => 'text', 'line_pattern' => '#a#']]);
        } finally {
            @unlink($dir . '/demo/plugin.json');
            @rmdir($dir . '/demo');
            @rmdir($dir);
        }
    }
}
