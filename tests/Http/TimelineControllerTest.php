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
            'reports:mri:mioveni:260101-test-a',
            ['title' => 'RM a', 'visibility' => 'private', 'patient' => ['name' => 'Ionescu Maria', 'born' => 1974, 'sex' => 'F', 'cnp' => '2740101123456']],
            'body a',
            'owner'
        );
        (new FlatFile($this->dataRoot, $index))->create(
            'reports:ct:mioveni:260102-test-b',
            ['title' => 'CT b', 'visibility' => 'private', 'patient' => ['name' => 'Ionescu Maria', 'born' => 1974, 'sex' => 'F', 'cnp' => '2740101123456']],
            'body b',
            'owner'
        );

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:260101-test-a/timeline',
            cookies: ['reporion' => $this->issueCookie('owner')]
        ));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('reports:mri:mioveni:260101-test-a', $response->body);
        self::assertStringContainsString('reports:ct:mioveni:260102-test-b', $response->body);
        // Counted facts only: two studies, and no site recorded on either
        self::assertStringContainsString('<dt>' . t('timeline.studies') . '</dt><dd>2</dd>', $response->body);
        self::assertStringContainsString('<dt>' . t('timeline.sites') . '</dt><dd>0</dd>', $response->body);
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
            'reports:mri:mioveni:260101-test-a',
            ['title' => 'RM a', 'visibility' => 'private', 'modality' => ['MR'], 'patient' => ['name' => 'Ionescu Maria', 'born' => 1974, 'sex' => 'F']],
            'body a',
            'owner'
        );

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:260101-test-a/timeline',
            cookies: ['reporion' => $this->issueCookie('owner')]
        ));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('reports:mri:mioveni:260101-test-a', $response->body);
        self::assertStringContainsString('<dt>' . t('timeline.studies') . '</dt><dd>1</dd>', $response->body, 'never zero — the current report is at least one study');
        self::assertStringContainsString('<dt>' . t('timeline.modalities') . '</dt><dd>1</dd>', $response->body);
    }

    public function testAMultiExamReportShowsItsExamsAndEveryNumber(): void
    {
        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        $patient = ['name' => 'Ionescu Maria', 'born' => 1974, 'sex' => 'F', 'cnp' => '2740101123456'];
        (new FlatFile($this->dataRoot, $index))->create('reports:mri:mioveni:260101-test-a', [
            'title' => 'Ionescu Maria', 'exam_title' => 'IRM genunchi drept + IRM genunchi stâng', 'visibility' => 'private', 'patient' => $patient,
            'exams' => [['title' => 'IRM genunchi drept', 'accession' => 'MV-MR-26-0031'], ['title' => 'IRM genunchi stâng', 'accession' => 'MV-MR-26-0032']],
        ], 'body a', 'owner');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:260101-test-a/timeline', cookies: ['reporion' => $this->issueCookie('owner')]));

        self::assertStringContainsString('>IRM genunchi drept + IRM genunchi stâng</a>', $response->body, 'one entry, titled by its exams');
        self::assertStringContainsString('MV-MR-26-0031, MV-MR-26-0032', $response->body);
    }

    public function testAnOrdinaryPageHasNoPatientHistoryAndNoPatientTab(): void
    {
        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        (new FlatFile($this->dataRoot, $index))->create('docs:notes', ['title' => 'Notes', 'visibility' => 'private'], 'body', 'owner');
        $cookies = ['reporion' => $this->issueCookie('owner')];

        self::assertSame(404, Kernel::boot($this->config)->handle(new Request('GET', '/docs:notes/timeline', cookies: $cookies))->status);

        $page = Kernel::boot($this->config)->handle(new Request('GET', '/docs:notes', cookies: $cookies))->body;
        self::assertStringContainsString('>View</a>', $page);
        self::assertStringContainsString('>Revisions</a>', $page);
        self::assertStringNotContainsString('>Patient</a>', $page);
        self::assertStringNotContainsString('>Report</a>', $page);
    }

    public function testAReportKeepsItsReportAndPatientTabs(): void
    {
        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        (new FlatFile($this->dataRoot, $index))->create('reports:mri:mioveni:260101-test-a', ['title' => 'Test A', 'visibility' => 'private'], 'body', 'owner');

        $page = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:260101-test-a', cookies: ['reporion' => $this->issueCookie('owner')]))->body;

        self::assertStringContainsString('>Report</a>', $page);
        self::assertStringContainsString('>Patient</a>', $page);
        self::assertStringNotContainsString('>View</a>', $page);
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
            'reports:mri:mioveni:260101-test-a',
            ['title' => 'RM a', 'visibility' => 'private'],
            'body a',
            'owner'
        );

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:260101-test-a/timeline',
            cookies: ['reporion' => $this->issueCookie('owner')]
        ));

        self::assertSame(200, $response->status);
        self::assertStringContainsString(t('timeline.no_patient'), $response->body);
    }

    public function testAnonymousCanSeeTimelineOfPublicPage(): void
    {
        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        (new FlatFile($this->dataRoot, $index))->create(
            'reports:mri:mioveni:260101-test-a',
            ['title' => 'RM a', 'visibility' => 'public', 'patient' => ['name' => 'Ionescu Maria', 'born' => 1974, 'sex' => 'F', 'cnp' => '2740101123456']],
            'body a',
            'owner'
        );

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:260101-test-a/timeline',
        ));

        self::assertSame(200, $response->status);
    }

    public function testAnonymousCannotSeeTimelineOfPrivatePage(): void
    {
        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        (new FlatFile($this->dataRoot, $index))->create(
            'reports:mri:mioveni:260101-test-a',
            ['title' => 'RM a', 'visibility' => 'private', 'patient' => ['name' => 'Ionescu Maria', 'born' => 1974, 'sex' => 'F', 'cnp' => '2740101123456']],
            'body a',
            'owner'
        );

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:260101-test-a/timeline',
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
