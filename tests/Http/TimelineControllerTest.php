<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Http\Request;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Storage\FlatFile;
use Reporion\Kernel;

/**
 * GET /{path}/timeline, end to end through the real Kernel.
 */
final class TimelineControllerTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
    }

    public function testTimelineShowsAllPagesForSamePatient(): void
    {
        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        (new FlatFile($this->dataRoot, $index))->create(
            'reports:mri:mioveni:a',
            ['title' => 'RM a', 'visibility' => 'private', 'patient' => ['name' => 'Ionescu Maria', 'born' => 1974, 'sex' => 'F', 'cnp' => '2740101123456']],
            'body a',
            'owner'
        );
        (new FlatFile($this->dataRoot, $index))->create(
            'reports:ct:mioveni:b',
            ['title' => 'CT b', 'visibility' => 'private', 'patient' => ['name' => 'Ionescu Maria', 'born' => 1974, 'sex' => 'F', 'cnp' => '2740101123456']],
            'body b',
            'owner'
        );

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a/timeline',
            cookies: ['reporion' => $this->issueCookie('owner')]
        ));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('reports:mri:mioveni:a', $response->body);
        self::assertStringContainsString('reports:ct:mioveni:b', $response->body);
    }

    public function testTimelineOfUnknownPathIs404(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:does-not-exist/timeline',
            cookies: ['reporion' => $this->issueCookie('owner')]
        ));

        self::assertSame(404, $response->status);
    }

    public function testNoPatientKeyShowsUnavailableMessage(): void
    {
        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        (new FlatFile($this->dataRoot, $index))->create(
            'reports:mri:mioveni:a',
            ['title' => 'RM a', 'visibility' => 'private'],
            'body a',
            'owner'
        );

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a/timeline',
            cookies: ['reporion' => $this->issueCookie('owner')]
        ));

        self::assertSame(200, $response->status);
        self::assertStringContainsString(t('timeline.no_patient'), $response->body);
    }

    public function testAnonymousCanSeeTimelineOfPublicPage(): void
    {
        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        (new FlatFile($this->dataRoot, $index))->create(
            'reports:mri:mioveni:a',
            ['title' => 'RM a', 'visibility' => 'public', 'patient' => ['name' => 'Ionescu Maria', 'born' => 1974, 'sex' => 'F', 'cnp' => '2740101123456']],
            'body a',
            'owner'
        );

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a/timeline',
        ));

        self::assertSame(200, $response->status);
    }

    public function testAnonymousCannotSeeTimelineOfPrivatePage(): void
    {
        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        (new FlatFile($this->dataRoot, $index))->create(
            'reports:mri:mioveni:a',
            ['title' => 'RM a', 'visibility' => 'private', 'patient' => ['name' => 'Ionescu Maria', 'born' => 1974, 'sex' => 'F', 'cnp' => '2740101123456']],
            'body a',
            'owner'
        );

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a/timeline',
        ));

        self::assertSame(404, $response->status);
    }

    private function issueCookie(string $username): string
    {
        return (new Session(
            (string) $this->config['auth']['session_secret'],
            (string) $this->config['auth']['session_name'],
            (int) $this->config['auth']['session_lifetime'],
            new FlatFileUserStore($this->dataRoot),
        ))->issue($username);
    }
}
