<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use DateTimeImmutable;
use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Storage\FlatFile;

/**
 * GET /stats, GET /stats.csv and the start page's "This month" card
 * (roadmap phase 24), end to end through the real Kernel.
 */
final class StatsTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        $this->config['sites'] = ['mioveni' => ['name' => 'Spital Test'], 'pitesti' => ['name' => 'Alt Spital']];
        $storage = new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
        $date = (new DateTimeImmutable('-2 days'))->format('Y-m-d');
        foreach (['mri:mioveni' => ['MR', 'mioveni'], 'ct:pitesti' => ['CT', 'pitesti']] as $ns => [$modality, $site]) {
            $path = 'reports:' . $ns . ':' . (new DateTimeImmutable())->format('ymd') . '-test-unu';
            $storage->create($path, ['title' => 'TEST Patient Unu', 'visibility' => 'private', 'modality' => [$modality], 'site' => $site, 'study_date' => $date], "Text.\n", 'owner');
            $storage->sign($path, 'owner', []);
        }
        (new FlatFileUserStore($this->dataRoot))->create('mihai', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('reports:mri', GrantRole::Editor)]);
    }

    public function testAnonymousIsSentToSignIn(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request('GET', '/stats'));

        self::assertSame(302, $response->status);
        self::assertStringEndsWith('/login', $response->headers['Location']);
    }

    public function testThePageCountsWhatTheCallerCanList(): void
    {
        $owner = $this->get('owner', '/stats');
        self::assertSame(200, $owner->status);
        self::assertStringContainsString('Alt Spital', $owner->body);
        self::assertMatchesRegularExpression('#<b>2</b><span>' . preg_quote(t('stats.signed_in_period'), '#') . '#', $owner->body);
        self::assertStringNotContainsString('TEST Patient Unu', $owner->body, 'signed reports are not listed, only counted');

        $mihai = $this->get('mihai', '/stats');
        self::assertMatchesRegularExpression('#<b>1</b><span>' . preg_quote(t('stats.signed_in_period'), '#') . '#', $mihai->body);
    }

    public function testFiltersOutsideTheAllowedValuesAreIgnored(): void
    {
        $response = $this->get('owner', '/stats', ['months' => '7', 'site' => "x' OR 1=1", 'modality' => 'XX']);

        self::assertSame(200, $response->status);
        self::assertMatchesRegularExpression('#<b>2</b><span>' . preg_quote(t('stats.signed_in_period'), '#') . '#', $response->body);
    }

    public function testTheCsvIsTheCountTable(): void
    {
        $response = $this->get('owner', '/stats.csv', ['table' => 'site', 'modality' => 'CT']);

        self::assertSame('text/csv; charset=utf-8', $response->headers['Content-Type']);
        self::assertStringStartsWith("site,exams,signed,median_days,p90_days\n\"Alt Spital\",1,1,", $response->body);
        self::assertStringNotContainsString('Spital Test', $response->body, 'filtered to CT');

        $months = $this->get('owner', '/stats.csv', ['table' => 'nope']);
        self::assertStringStartsWith("month,exams,signed,median_days\n", $months->body);
        self::assertSame(13, substr_count($months->body, "\n"), 'twelve months and the header');
    }

    public function testTheStartPageCardLinksToStats(): void
    {
        $body = $this->get('owner', '/')->body;

        self::assertStringContainsString('wk-start-stats-5', $body);
        self::assertStringContainsString('/stats"><b>2</b>', $body);
    }

    /** @param array<string, string> $query */
    private function get(string $username, string $path, array $query = []): Response
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($username);

        return Kernel::boot($this->config)->handle(new Request('GET', $path, query: $query, cookies: ['reporion' => $cookie]));
    }
}
