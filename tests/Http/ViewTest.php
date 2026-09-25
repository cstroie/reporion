<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use PHPUnit\Framework\TestCase;
use Reporion\Http\View;
use RuntimeException;

final class ViewTest extends TestCase
{
    public function testAThrowingTemplateLeavesNoPartialOutputBehind(): void
    {
        $template = tempnam(sys_get_temp_dir(), 'view') . '.php';
        file_put_contents($template, "<p>PATIENT NAME</p><?php throw new \\RuntimeException('boom'); ?>");
        $level = ob_get_level();

        try {
            View::render($template, []);
            self::fail('expected the template exception');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        } finally {
            unlink($template);
        }

        self::assertSame($level, ob_get_level());
        self::assertSame('', (string) ob_get_contents());
    }
}
