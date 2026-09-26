<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Storage\FlatFile;

/**
 * GET /feed.atom and /feed/{ns}.atom — allowed namespaces only, never
 * reports, public pages only, never a page with patient data.
 */
final class FeedTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $s = new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
        $s->create('docs:public-guide', ['title' => 'Public guide', 'visibility' => 'public', 'summary' => 'How to report'], 'g', 'owner');
        $s->create('docs:sub:deeper', ['title' => 'Deeper public', 'visibility' => 'public'], 'd', 'owner');
        $s->create('docs:private-note', ['title' => 'Private note', 'visibility' => 'private'], 'p', 'owner');
        $s->create('docs:unlisted-note', ['title' => 'Unlisted note', 'visibility' => 'unlisted'], 'u', 'owner');
        $s->create('docs:public-case', ['title' => 'Public case with patient', 'visibility' => 'public', 'patient' => ['name' => 'TEST PATIENT', 'born' => 1970, 'sex' => 'F']], 'c', 'owner');
        $s->create('reports:mri:mioveni:public-report', ['title' => 'Public report', 'visibility' => 'public'], 'r', 'owner');
        $s->create('teaching:other', ['title' => 'Not configured', 'visibility' => 'public'], 't', 'owner');
    }

    public function testNoConfiguredNamespacesMeansNoFeed(): void
    {
        self::assertSame(404, $this->get('/feed.atom')->status);
    }

    public function testTheFeedHasPublicPatientFreePagesOfAllowedNamespacesOnly(): void
    {
        $this->config['feeds'] = ['namespaces' => ['docs', 'reports', 'reports:mri']];
        $this->config['site']['base_url'] = 'https://example.test/reporion';

        $response = $this->get('/feed.atom');

        self::assertSame(200, $response->status);
        self::assertStringStartsWith('application/atom+xml', $response->headers['Content-Type']);
        $feed = simplexml_load_string($response->body);
        self::assertNotFalse($feed, 'well-formed Atom');
        $titles = [];
        foreach ($feed->entry as $entry) {
            $titles[] = (string) $entry->title;
        }
        sort($titles);
        self::assertSame(['Deeper public', 'Public guide'], $titles);
        self::assertStringContainsString('https://example.test/reporion/docs:public-guide', $response->body);
        self::assertStringNotContainsString('TEST PATIENT', $response->body);
        self::assertStringNotContainsString('reports:', $response->body);
    }

    public function testPerNamespaceFeedsAreLimitedToTheAllowList(): void
    {
        $this->config['feeds'] = ['namespaces' => ['docs', 'reports']];

        self::assertSame(200, $this->get('/feed/docs.atom')->status);
        self::assertSame(404, $this->get('/feed/reports.atom')->status, 'reports is never a feed, even when configured');
        self::assertSame(404, $this->get('/feed/teaching.atom')->status);
    }

    private function get(string $path): Response
    {
        return Kernel::boot($this->config)->handle(new Request('GET', $path));
    }
}
