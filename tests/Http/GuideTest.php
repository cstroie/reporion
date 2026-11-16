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

/** The imaging guide's routes (D62): public, the API's shapes, the public frame for anonymous */
final class GuideTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new FlatFileUserStore($this->dataRoot))->create('reader', password_hash('x', PASSWORD_ARGON2ID), false);
    }

    private function loaded(): void
    {
        $index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        Kernel::guide($this->dataRoot, $index)->load((string) file_get_contents(\dirname(__DIR__) . '/fixtures/guide/guide-sample.csv'), 'GIRI@test', 'owner');
    }

    private function call(?string $user, string $url): Response
    {
        $cookies = $user !== null ? ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user)] : [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return Kernel::boot($this->config)->handle(new Request('GET', (string) parse_url($url, PHP_URL_PATH), query: array_map('strval', $query), cookies: $cookies, remoteAddr: '192.0.2.1'));
    }

    public function testNothingLoadedSaysSo(): void
    {
        $page = $this->call(null, '/guide');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('guide:load', $page->body);
        self::assertSame([], json_decode($this->call(null, '/api/v1/guide?q=ecografie')->body, true)['data']);
    }

    public function testTheApiAnswersAnyoneWithSituationsAndTheirExams(): void
    {
        $this->loaded();
        $found = json_decode($this->call(null, '/api/v1/guide?q=apendicita&tip=T')->body, true);
        self::assertSame('Apendicită', $found['data'][0]['title']);
        self::assertSame(['E', 'T'], array_column($found['data'][0]['exams'], 'tip'));
        self::assertSame(['limit' => 10, 'offset' => 0, 'next' => null], $found['page']);

        $key = $found['data'][0]['key'];
        $one = $this->call(null, '/api/v1/guide/' . $key);
        self::assertSame(200, $one->status);
        self::assertSame('Pediatrie', json_decode($one->body, true)['data']['chapter']);
        self::assertSame(404, $this->call(null, '/api/v1/guide/abcdef123456')->status);
        self::assertSame(404, $this->call(null, '/api/v1/guide/abc')->status);
    }

    public function testThePagesShowInThePublicFrameOrTheAppShell(): void
    {
        $this->loaded();
        $anon = $this->call(null, '/guide?q=avc');
        self::assertSame(200, $anon->status);
        self::assertStringContainsString('wk-topnav-anon', $anon->body);
        self::assertStringContainsString('Accident vascular cerebral acut', $anon->body);
        self::assertStringContainsString('GIRI@test', $anon->body, 'the version credited');

        $signed = $this->call('reader', '/guide');
        self::assertStringNotContainsString('wk-topnav-anon', $signed->body);
        self::assertStringContainsString('Pediatrie', $signed->body, 'the outline');

        $key = json_decode($this->call(null, '/api/v1/guide?q=avc')->body, true)['data'][0]['key'];
        $situation = $this->call(null, '/guide/' . $key);
        self::assertSame(200, $situation->status);
        self::assertStringContainsString('IRM cerebral', $situation->body);
        self::assertStringContainsString('wk-guide-ind-indicated', $situation->body);
        self::assertSame(404, $this->call(null, '/guide/abcdef123456')->status);
    }
}
