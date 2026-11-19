<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Kernel;

/**
 * The admin tabs (Support\AdminTabs): each one owns its settings and its
 * tasks; the old screens' addresses redirect to the tab that took them over.
 */
final class AdminTabsLayoutTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('owner', password_hash('x', PASSWORD_ARGON2ID), true);
        $users->create('editor', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('reports', GrantRole::Editor)]);
    }

    public function testTheNewTabsAreTheOwnersOnly(): void
    {
        foreach (['/admin/data', '/admin/reports', '/admin/library', '/admin/integrations'] as $route) {
            self::assertSame(200, $this->request('GET', $route, 'owner')->status, $route);
            self::assertSame(404, $this->request('GET', $route, 'editor')->status, $route);
            self::assertSame(404, $this->request('GET', $route, null)->status, $route);
        }
    }

    public function testTheTabRowAndThePeopleSubTabs(): void
    {
        $users = $this->request('GET', '/admin/users', 'owner')->body;
        foreach (['People', 'General', 'Reports', 'Library', 'AI', 'Tags', 'Data', 'Integrations'] as $label) {
            self::assertStringContainsString('>' . $label . '</a>', $users, $label);
        }
        self::assertMatchesRegularExpression('#data-on="1" aria-current="page" href="/admin/users">People</a>#', $users);
        self::assertStringContainsString('class="seg-opt seg-on" aria-current="page" href="/admin/users">Users</a>', $users);
        self::assertStringContainsString('href="/admin/groups">Groups</a>', $users);
        self::assertStringNotContainsString('href="/admin/groups">Groups</a>', $this->request('GET', '/admin/data', 'owner')->body, 'only People has sub-tabs');
    }

    public function testEachTabShowsItsOwnSettingsAndTasks(): void
    {
        $general = $this->request('GET', '/admin/settings', 'owner')->body;
        foreach (['site', 'editor', 'publishing', 'limits', 'debug'] as $id) {
            self::assertStringContainsString('id="' . $id . '"', $general, $id);
        }
        foreach (['library', 'backup', 'mcp', 'reports'] as $id) {
            self::assertStringNotContainsString('action="/admin/settings/' . $id . '"', $general, $id . ' moved to its tab');
        }
        self::assertStringNotContainsString('action="/admin/maintenance/', $general, 'no task on General');

        $data = $this->request('GET', '/admin/data', 'owner')->body;
        foreach (['index:verify', 'integrity:verify', 'journal:replay', 'pages:repair-paths', 'trash:purge', 'backup:create', 'backup:rsync'] as $task) {
            self::assertStringContainsString('action="/admin/maintenance/' . $task . '"', $data, $task);
        }
        self::assertStringContainsString('action="/admin/settings/backup"', $data);
        self::assertStringContainsString('action="/admin/settings/trash"', $data);
        self::assertStringContainsString('action="/admin/index/rebuild"', $data);
        self::assertStringNotContainsString('action="/admin/maintenance/library:', $data);

        $library = $this->request('GET', '/admin/library', 'owner')->body;
        self::assertStringContainsString('action="/admin/settings/library"', $library);
        foreach (['library:abstracts', 'library:digest', 'library:notices'] as $task) {
            self::assertStringContainsString('action="/admin/maintenance/' . $task . '"', $library, $task);
        }

        $reports = $this->request('GET', '/admin/reports', 'owner')->body;
        self::assertStringContainsString('action="/admin/settings/reports"', $reports);
        self::assertStringContainsString('action="/admin/sites"', $reports);
        self::assertStringContainsString('action="/admin/maintenance/pages:summarize"', $reports);

        $ai = $this->request('GET', '/admin/ai', 'owner')->body;
        self::assertStringContainsString('action="/admin/maintenance/index:vectors"', $ai);
        self::assertStringContainsString('action="/admin/maintenance/ai:prompts-relocate"', $ai);

        self::assertStringContainsString('action="/admin/settings/mcp"', $this->request('GET', '/admin/integrations', 'owner')->body);
    }

    public function testASaveAndARunComeBackToTheirTab(): void
    {
        self::assertStringEndsWith('/admin/library?saved=library#library', $this->request('POST', '/admin/settings/library', 'owner', '')->headers['Location']);
        self::assertStringEndsWith('/admin/integrations?saved=mcp#mcp', $this->request('POST', '/admin/settings/mcp', 'owner', 'mcp_access=local')->headers['Location']);
        self::assertStringEndsWith('/admin/reports?saved=reports#reports', $this->request('POST', '/admin/settings/reports', 'owner', '')->headers['Location']);

        $refused = $this->request('POST', '/admin/settings/backup', 'owner', 'backup_enabled=1');
        self::assertSame(422, $refused->status, 'a schedule without a target');
        self::assertStringContainsString('<h1 class="wk-doc-title">Data</h1>', $refused->body, 'shown on the tab that holds the form');

        $run = $this->request('POST', '/admin/maintenance/library:notices', 'owner', 'mode=check');
        self::assertMatchesRegularExpression('~/admin/library\?run=[0-9a-f-]+#report$~', $run->headers['Location']);
        $unconfirmed = $this->request('POST', '/admin/maintenance/library:notices', 'owner', 'mode=apply');
        self::assertSame(422, $unconfirmed->status);
        self::assertStringContainsString('<h1 class="wk-doc-title">Library</h1>', $unconfirmed->body);
        self::assertStringContainsString('Tick the confirmation box', $unconfirmed->body);
    }

    public function testTheOldAddressesRedirect(): void
    {
        $old = [
            '/admin/index' => '/admin/data#health',
            '/admin/trash' => '/admin/data#trash',
            '/admin/maintenance' => '/admin/data',
            '/admin/sites' => '/admin/reports#sites',
            '/admin/plugins' => '/admin/integrations',
        ];
        foreach ($old as $from => $to) {
            $response = $this->request('GET', $from, 'owner');
            self::assertSame(301, $response->status, $from);
            self::assertSame($to, $response->headers['Location'], $from);
            self::assertSame(404, $this->request('GET', $from, 'editor')->status, $from . ' stays hidden from a non-owner');
        }
        $run = $this->request('POST', '/admin/maintenance/index:vectors', 'owner', 'mode=check')->headers['Location'];
        preg_match('~run=([0-9a-f-]+)~', $run, $m);
        self::assertSame('/admin/ai?run=' . $m[1] . '#report', $this->request('GET', '/admin/maintenance?run=' . $m[1], 'owner')->headers['Location'], 'a run\'s old link opens its tab');
    }

    private function request(string $method, string $path, ?string $user, string $body = ''): Response
    {
        [$route, $query] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($query, $params);

        return Kernel::boot($this->config)->handle(new Request(
            $method,
            $route,
            query: array_map('strval', $params),
            cookies: $user === null ? [] : ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user)],
            body: $body,
        ));
    }
}
