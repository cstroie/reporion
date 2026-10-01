<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use PHPUnit\Framework\TestCase;
use Reporion\Http\Breadcrumb;

final class BreadcrumbTest extends TestCase
{
    public function testATrailIsANavWithAnOrderedListAndTheCurrentPageMarked(): void
    {
        $html = Breadcrumb::render([
            ['label' => 'reports', 'href' => '/reports:'],
            ['label' => 'mri', 'href' => '/reports:mri:'],
            ['label' => 'mioveni'],
        ]);

        self::assertStringStartsWith('<nav class="wk-crumbs wk-mono" aria-label="Breadcrumb"><ol>', $html);
        self::assertStringContainsString('<li><a href="/reports:">reports</a></li><li><a href="/reports:mri:">mri</a></li>', $html);
        self::assertStringContainsString('<li><span aria-current="page">mioveni</span></li></ol></nav>', $html);
        self::assertSame(1, substr_count($html, 'aria-current'));
    }

    public function testALevelWithoutAHrefIsPlainTextAndTheLastNeverALink(): void
    {
        $html = Breadcrumb::render([['label' => 'Admin'], ['label' => 'Users', 'href' => '/admin/users']]);

        self::assertStringContainsString('<li><span>Admin</span></li>', $html);
        self::assertStringContainsString('<span aria-current="page">Users</span>', $html);
        self::assertStringNotContainsString('<a ', $html);
    }

    public function testAnIconIsDecorativeAndLabelsAreEscaped(): void
    {
        $html = Breadcrumb::render([['label' => 'PACS', 'icon' => 'monitor']], '<b>after</b>');

        self::assertStringContainsString('<li><i class="ph ph-monitor" aria-hidden="true"></i><span aria-current="page">PACS</span></li>', $html);
        self::assertStringEndsWith('</ol><b>after</b></nav>', $html);
        self::assertStringContainsString('&lt;x&gt;', Breadcrumb::render([['label' => '<x>']]));
    }
}
