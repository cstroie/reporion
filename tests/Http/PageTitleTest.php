<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Http\Request;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Storage\FlatFile;

/**
 * The browser tab's title: who, then what, then the app (2026-10-09).
 * Fixtures are invented.
 */
final class PageTitleTest extends HttpTestCase
{
    private const NEW = 'reports:mri:mioveni:260922-test-titlu';
    private const OLD = 'reports:mri:mioveni:260304-test-titlu';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        $storage = new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
        $patient = ['name' => 'TEST Titlu', 'born' => 1980, 'sex' => 'F', 'cnp' => '2800101123450'];
        foreach ([self::NEW => '2026-09-22', self::OLD => '2026-03-04'] as $path => $date) {
            $storage->create($path, ['title' => 'TEST Titlu', 'exam_title' => 'IRM cerebral', 'visibility' => 'private', 'study_date' => $date, 'patient' => $patient], "# TEST Titlu\n\n## IRM cerebral\n\n### Concluzii\n\nNormal.\n", 'owner');
        }
    }

    public function testTheTitleIsThePatientThenTheFunctionThenTheApp(): void
    {
        $app = t('app.name');
        self::assertSame('TEST Titlu — ' . t('tabs.report') . ' — ' . $app, $this->title(self::NEW));
        self::assertSame('TEST Titlu — ' . t('tabs.edit') . ' — ' . $app, $this->title(self::NEW . '/edit'));
        self::assertSame('TEST Titlu — ' . t('tabs.revisions') . ' — ' . $app, $this->title(self::NEW . '/revisions'));
        self::assertSame('TEST Titlu — ' . t('tabs.timeline') . ' — ' . $app, $this->title(self::NEW . '/timeline'));
        self::assertSame('TEST Titlu — ' . t('compare.title') . ' — ' . $app, $this->title(self::NEW . '/compare'));
        self::assertSame('TEST Titlu — ' . t('newr.title') . ' — ' . $app, $this->title(self::NEW . '/new'), 'a follow-up names its patient');
        self::assertSame(t('admin.users.title') . ' — ' . $app, $this->title('admin/users'), 'no subject: as before');
    }

    private function title(string $path): string
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('owner');
        $body = Kernel::boot($this->config)->handle(new Request('GET', '/' . $path, cookies: ['reporion' => $cookie]))->body;
        preg_match('#<title>(.*?)</title>#s', $body, $m);

        return html_entity_decode($m[1] ?? '', ENT_QUOTES);
    }
}
