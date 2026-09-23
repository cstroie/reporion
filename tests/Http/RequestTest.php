<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use PHPUnit\Framework\TestCase;
use Reporion\Http\Request;

/**
 * The bug this guards against: the app is not always mounted at the web
 * server's root — confirmed live, this app is served at /reporion/ via a
 * rewrite to index.php/$path — and fromGlobals() originally used
 * REQUEST_URI directly, which still carries that mount prefix. Every route
 * silently 404'd under the real deployment while every test (which
 * constructs Request by hand) kept passing.
 */
final class RequestTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['PATH_INFO'], $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
        parent::tearDown();
    }

    public function testPrefersPathInfoWhenPresent(): void
    {
        // What lighttpd's "index.php/$1" rewrite target produces when the
        // app is mounted under a path prefix: PATH_INFO is already the
        // clean, prefix-free logical path.
        $_SERVER['PATH_INFO'] = '/reports:mri:mioveni:x';
        $_SERVER['REQUEST_URI'] = '/reporion/reports:mri:mioveni:x';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        self::assertSame('/reports:mri:mioveni:x', Request::fromGlobals()->path);
    }

    public function testFallsBackToRequestUriWhenPathInfoIsAbsent(): void
    {
        // A request for a real, existing file has no "extra" path segments,
        // so PATH_INFO is genuinely absent in both deployment modes
        // (confirmed empirically) — REQUEST_URI is already clean then.
        unset($_SERVER['PATH_INFO']);
        $_SERVER['REQUEST_URI'] = '/reports:mri:mioveni:x';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        self::assertSame('/reports:mri:mioveni:x', Request::fromGlobals()->path);
    }

    public function testEmptyPathInfoFallsBackToRequestUriToo(): void
    {
        // PATH_INFO can be set but empty (root request through a rewrite,
        // depending on SAPI) — an empty string must not win over a real path.
        $_SERVER['PATH_INFO'] = '';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        self::assertSame('/', Request::fromGlobals()->path);
    }

    /**
     * The bug this guards against: templates hardcoded absolute links
     * (/assets/..., /search, /login, page URLs) that only resolve correctly
     * when the app is mounted at the web server's root — confirmed live,
     * every one of them 404'd for a real browser under /reporion/ mounting.
     */
    public function testBasePathIsDerivedFromScriptNameNotHardcoded(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/reporion/index.php';

        self::assertSame('/reporion', Request::basePathFromGlobals());

        unset($_SERVER['SCRIPT_NAME']);
    }

    public function testBasePathIsEmptyWhenMountedAtRoot(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        self::assertSame('', Request::basePathFromGlobals());

        unset($_SERVER['SCRIPT_NAME']);
    }
}
