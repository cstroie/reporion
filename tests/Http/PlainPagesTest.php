<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Storage\FlatFile;

/**
 * Reports versus every other page (Support\ReportPath, decided
 * 2026-09-26): a report prints with its letterhead and signature and its
 * metadata panel starts open; any other page — including a site's
 * description page under reports: — prints plainly, exports as a draft,
 * and keeps its metadata panel closed.
 */
final class PlainPagesTest extends HttpTestCase
{
    private const REPORT = 'reports:mri:mioveni:260101-test-subject';
    private const SITE_PAGE = 'reports:mri:mioveni';

    public function testAReportKeepsItsPrintTemplateAndOpenPanel(): void
    {
        $this->page(self::REPORT, ['patient' => ['name' => 'TEST SUBJECT']]);

        self::assertStringContainsString('<details class="wk-meta" open>', $this->staff('/' . self::REPORT)->body);
        $print = $this->staff('/' . self::REPORT . '/print')->body;
        self::assertStringContainsString('class="draft-band"', $print, 'the report template');
        self::assertSame(409, $this->staff('/export/' . self::REPORT . '.pdf')->status, 'a draft report still waits for its signature');
    }

    public function testAnyOtherPagePrintsPlainlyExportsAsADraftAndHidesItsPanel(): void
    {
        foreach (['docs:protocol-rm', self::SITE_PAGE] as $path) {
            $this->page($path, []);

            self::assertStringContainsString('<details class="wk-meta">', $this->staff('/' . $path)->body, $path . ': closed');
            $print = $this->staff('/' . $path . '/print')->body;
            self::assertStringNotContainsString('class="draft-band"', $print, $path);
            self::assertStringNotContainsString('class="pt"', $print, $path . ': no patient block');
            self::assertStringContainsString('<h1 class="doc-title">Titlu</h1>', $print, $path);
            self::assertMatchesRegularExpression('~rev 1 · \d{2}\.\d{2}\.\d{4}~', $print, $path);

            $pdf = $this->staff('/export/' . $path . '.pdf');
            self::assertSame(200, $pdf->status, $path . ': never signed, so a draft exports');
            self::assertStringStartsWith('%PDF', $pdf->body);
        }
    }

    /** @param array<string, mixed> $extra */
    private function page(string $path, array $extra): void
    {
        (new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations')))
            ->create($path, ['title' => 'Titlu', 'visibility' => 'private'] + $extra, "## Secțiune\n\nText.\n", 'owner');
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
