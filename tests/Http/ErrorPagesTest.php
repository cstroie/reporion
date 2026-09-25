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
 * Http\ErrorMapper::render() — error pages (design/mockup/WikiErrors.dc.html).
 */
final class ErrorPagesTest extends HttpTestCase
{
    public function testAnonymous404IsTheSameBarePageForPrivateAndMissing(): void
    {
        $this->createPage('reports:mri:mioveni:secret', 'private', 'Secret', 'body');

        $private = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:secret'));
        $missing = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:nothing'));

        self::assertSame(404, $private->status);
        self::assertSame(404, $missing->status);
        self::assertSame($private->body, $missing->body, 'a private page and a missing one must be indistinguishable');
        self::assertStringNotContainsString('secret', $private->body);
        self::assertStringContainsString(t('err.404.title'), $private->body);
    }

    public function testSignedIn404IsInTheShellAndOffersCreateOnlyToAWriter(): void
    {
        $this->createOwner();
        (new FlatFileUserStore($this->dataRoot))->create('ana', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('reports:mri', GrantRole::Viewer)]);
        $session = new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot));

        $owner = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:nothing', cookies: ['reporion' => $session->issue('owner')]));
        $viewer = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:nothing', cookies: ['reporion' => $session->issue('ana')]));

        self::assertSame(404, $owner->status);
        self::assertStringContainsString('wk-topnav', $owner->body);
        self::assertStringContainsString('href="/new?path=reports%3Amri%3Amioveni%3Anothing"', $owner->body);
        self::assertStringNotContainsString('/new?path=', $viewer->body);
    }

    public function testApiRoutesGetJsonErrors(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request('GET', '/api/v1/nope'));

        self::assertSame(404, $response->status);
        self::assertSame('not_found', json_decode($response->body, true)['error']['code']);
    }

    public function testNewPrefillsAnExactPathFromTheQuery(): void
    {
        $this->createOwner();
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('owner');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/new', query: ['path' => 'reports:mri:mioveni:nothing'], cookies: ['reporion' => $cookie]));

        self::assertStringContainsString('name="path" value="reports:mri:mioveni:nothing"', $response->body);
    }
}
