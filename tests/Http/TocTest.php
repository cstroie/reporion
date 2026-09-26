<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Kernel;

/**
 * The table of contents beside the text (roadmap phase 8): only with two
 * or more headings, in the staff page view and the public layout, its
 * links resolving to anchors on the headings, with the collapsed fallback
 * for narrow screens.
 */
final class TocTest extends HttpTestCase
{
    public function testTwoHeadingsGetATocInTheStaffViewWithWorkingAnchors(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'RM', "## Tehnică\n\ntext\n\n## Concluzie\n\ntext\n");

        $body = $this->staff('/reports:mri:mioveni:a')->body;

        self::assertStringContainsString('class="wk-docgrid wk-has-toc"', $body);
        self::assertStringContainsString('<nav class="wk-toc" data-island="toc"', $body);
        self::assertStringContainsString('<details class="wk-toc-narrow">', $body, 'the narrow-screen fallback, no script needed');
        self::assertStringContainsString('<a href="#tehnica">Tehnică</a>', $body);
        self::assertStringContainsString('<h2 id="tehnica">Tehnică</h2>', $body, 'the link has somewhere to go');
        self::assertMatchesRegularExpression('~<script src="/assets/js/toc\.js\?v=[0-9a-f]+" defer>~', $body);
        self::assertLessThan(strpos($body, 'class="wk-prose"'), strpos($body, 'class="wk-toc"'), 'before the text in reading order');
    }

    public function testOneHeadingGetsNone(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'RM', "## Concluzie\n\ntext\n");

        $body = $this->staff('/reports:mri:mioveni:a')->body;

        self::assertStringNotContainsString('wk-toc', $body);
        self::assertStringNotContainsString('toc.js', $body);
        self::assertStringContainsString('class="wk-docgrid"', $body);
    }

    public function testThePublicLayoutHasItToo(): void
    {
        $this->createPage('docs:protocol', 'public', 'Protocol', "## Tehnică\n\ntext\n\n### Secvențe\n\ntext\n");

        $body = Kernel::boot($this->config)->handle(new Request('GET', '/docs:protocol'))->body;

        self::assertStringContainsString('class="wk-public-doc wk-public-doc-toc"', $body);
        self::assertStringContainsString('<nav class="wk-toc" data-island="toc"', $body);
        self::assertStringContainsString('<li style="padding-left:1em"><a href="#secvente">Secvențe</a></li>', $body, 'h3 indented under h2');
    }

    private function staff(string $path): Response
    {
        $users = new FlatFileUserStore($this->dataRoot);
        if ($users->find('owner') === null) {
            $users->create('owner', password_hash('x', PASSWORD_ARGON2ID), true);
        }

        return Kernel::boot($this->config)->handle(new Request('GET', $path, cookies: ['reporion' => (new Session('test-secret', 'reporion', 3600, $users))->issue('owner')]));
    }
}
