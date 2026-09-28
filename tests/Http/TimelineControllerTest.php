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
        // Counted facts only: two studies, and no site recorded on either
        self::assertStringContainsString('<b>2</b><span>' . t('timeline.studies') . '</span>', $response->body);
        self::assertStringContainsString('<b>0</b><span>' . t('timeline.sites') . '</span>', $response->body);
        self::assertStringContainsString('wk-tl-i wk-sel', $response->body, 'the report the tab belongs to is marked');
    }

    /**
     * D11's weak key (sha256(name|born|sex), no CNP): Index\Sqlite::
     * findByPatientKey() used to match only the `patient_key` column
     * regardless of which kind of key it was handed, so a weak-key-only
     * patient (no CNP — the common case) got zero rows back, not even
     * its own report. Fixed to match either column.
     */
    public function testTimelineShowsTheCurrentStudyWhenThereIsNoCnp(): void
    {
        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        (new FlatFile($this->dataRoot, $index))->create(
            'reports:mri:mioveni:a',
            ['title' => 'RM a', 'visibility' => 'private', 'modality' => ['MR'], 'patient' => ['name' => 'Ionescu Maria', 'born' => 1974, 'sex' => 'F']],
            'body a',
            'owner'
        );

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a/timeline',
            cookies: ['reporion' => $this->issueCookie('owner')]
        ));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('reports:mri:mioveni:a', $response->body);
        self::assertStringContainsString('<b>1</b><span>' . t('timeline.studies') . '</span>', $response->body, 'never zero — the current report is at least one study');
        self::assertStringContainsString('<b>1</b><span>' . t('timeline.modalities') . '</span>', $response->body);
    }

    public function testAMultiExamReportShowsItsExamsAndEveryNumber(): void
    {
        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        $patient = ['name' => 'Ionescu Maria', 'born' => 1974, 'sex' => 'F', 'cnp' => '2740101123456'];
        (new FlatFile($this->dataRoot, $index))->create('reports:mri:mioveni:a', [
            'title' => 'Ionescu Maria', 'exam_title' => 'IRM genunchi drept + IRM genunchi stâng', 'visibility' => 'private', 'patient' => $patient,
            'exams' => [['title' => 'IRM genunchi drept', 'accession' => 'MV-MR-26-0031'], ['title' => 'IRM genunchi stâng', 'accession' => 'MV-MR-26-0032']],
        ], 'body a', 'owner');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:a/timeline', cookies: ['reporion' => $this->issueCookie('owner')]));

        self::assertStringContainsString('>IRM genunchi drept + IRM genunchi stâng</a>', $response->body, 'one entry, titled by its exams');
        self::assertStringContainsString('MV-MR-26-0031, MV-MR-26-0032', $response->body);
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
