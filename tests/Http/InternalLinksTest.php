<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Http\Request;
use Reporion\Http\Session;
use Reporion\Kernel;

/**
 * Links between pages (decided 2026-09-26): canonical `ns:page` and the
 * importer's `ns/page` both resolve under the mount point, the linked page
 * lists its backlinks, and print/export keeps the text but not the address.
 */
final class InternalLinksTest extends HttpTestCase
{
    public function testLinksResolveUnderTheMountPointAndFillBacklinks(): void
    {
        $this->createPage('site:target', 'public', 'Target', 'the target');
        $this->createPage('site:linker', 'public', 'Linker', "See [the target](site:target#x) and [imported](site/target).\n");

        $linker = Kernel::boot($this->config)->handle(new Request('GET', '/site:linker', basePath: '/reporion'));
        self::assertStringContainsString('<a href="/reporion/site:target#x">the target</a>', $linker->body);
        self::assertStringContainsString('<a href="/reporion/site:target">imported</a>', $linker->body);

        // The backlinks panel is staff chrome; the public layout has none
        $target = Kernel::boot($this->config)->handle(new Request('GET', '/site:target', cookies: ['reporion' => $this->ownerCookie()], basePath: '/reporion'));
        self::assertStringContainsString('href="/reporion/site:linker" class="wk-mono">site:linker</a>', $target->body);
    }

    public function testAPrivateLinkerIsNotListedToAViewerWithoutAGrantThere(): void
    {
        (new FlatFileUserStore($this->dataRoot))->create('reader', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('site', GrantRole::Viewer)]);
        $this->createPage('site:target', 'private', 'Target', 'the target');
        $this->createPage('reports:secret-linker', 'private', 'Secret', "[t](site:target)\n");
        $this->createPage('site:visible-linker', 'private', 'Visible', "[t](site:target)\n");
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('reader');

        $target = Kernel::boot($this->config)->handle(new Request('GET', '/site:target', cookies: ['reporion' => $cookie]));

        self::assertSame(200, $target->status);
        self::assertStringContainsString('visible-linker', $target->body);
        self::assertStringNotContainsString('secret-linker', $target->body);
    }

    public function testThePrintedReportKeepsTheLinkTextButNotTheAddress(): void
    {
        $this->createPage('reports:mri:mioveni:250101-test-subject', 'private', 'Prior', 'prior');
        $this->createPage('reports:mri:mioveni:260101-test-subject', 'private', 'Now', "Compared with [the prior study](reports:mri:mioveni:250101-test-subject).\n");

        $print = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:260101-test-subject/print', cookies: ['reporion' => $this->ownerCookie()]));

        self::assertSame(200, $print->status);
        self::assertStringContainsString('Compared with the prior study.', $print->body);
        self::assertStringNotContainsString('250101-test-subject', $print->body);
    }

    private function ownerCookie(): string
    {
        $users = new FlatFileUserStore($this->dataRoot);
        if ($users->find('owner') === null) {
            $users->create('owner', password_hash('x', PASSWORD_ARGON2ID), true);
        }

        return (new Session('test-secret', 'reporion', 3600, $users))->issue('owner');
    }
}
