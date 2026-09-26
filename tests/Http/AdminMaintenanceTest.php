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
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Storage\FlatFile;

/**
 * Admin → Maintenance (decided 2026-09-26): the bin/reporion maintenance
 * commands from the browser — owner-only, nothing on GET, check before
 * apply, apply only with the confirm box, Post/Redirect/Get so a refresh
 * never re-runs, one lock with the CLI, reports by pid.
 */
final class AdminMaintenanceTest extends HttpTestCase
{
    private const PATH = 'reports:mri:mioveni:260101-test-subject';

    protected function setUp(): void
    {
        parent::setUp();
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('owner', password_hash('x', PASSWORD_ARGON2ID), true);
        $users->create('editor', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('reports', GrantRole::Editor)]);
    }

    public function testOwnerOnlyEverythingElseIs404(): void
    {
        self::assertSame(200, $this->request('GET', '/admin/maintenance', 'owner')->status);
        foreach ([null, 'editor'] as $user) {
            self::assertSame(404, $this->request('GET', '/admin/maintenance', $user)->status);
            self::assertSame(404, $this->request('POST', '/admin/maintenance/index:verify', $user, 'mode=check')->status);
        }
        self::assertSame(404, $this->request('POST', '/admin/maintenance/serve', 'owner', 'mode=check')->status, 'only listed tasks');
    }

    public function testACheckIsStoredAndShownAfterARedirectAndARefreshDoesNotRerun(): void
    {
        $this->createPage(self::PATH, 'private', 'RM lombar', 'text');
        $pid = $this->storage()->read(self::PATH)->pid;
        $this->backdatedDelete();

        $post = $this->request('POST', '/admin/maintenance/trash:purge', 'owner', 'mode=check&older_than=30');
        self::assertSame(302, $post->status);
        self::assertMatchesRegularExpression('~/admin/maintenance\?run=\d{8}-\d{6}-[0-9a-f]{6}#report$~', $post->headers['Location']);
        self::assertCount(1, $this->storage()->trash(), 'a check deletes nothing');

        $url = substr($post->headers['Location'], 0, (int) strpos($post->headers['Location'], '#'));
        $shown = $this->request('GET', $url, 'owner');
        self::assertSame(200, $shown->status);
        self::assertStringContainsString('would purge', $shown->body);
        self::assertStringContainsString('RM lombar', $shown->body, 'the owner sees which page');
        self::assertStringContainsString($pid, $shown->body);

        $this->request('GET', $url, 'owner');
        self::assertCount(1, glob($this->dataRoot . '/maintenance/runs/*.json') ?: [], 'showing a run never runs anything');
    }

    public function testApplyNeedsTheConfirmBoxAndThenRuns(): void
    {
        $this->createPage(self::PATH, 'private', 'RM', 'text');
        $this->backdatedDelete();

        $refused = $this->request('POST', '/admin/maintenance/trash:purge', 'owner', 'mode=apply&older_than=30');
        self::assertSame(422, $refused->status);
        self::assertCount(1, $this->storage()->trash());

        $applied = $this->request('POST', '/admin/maintenance/trash:purge', 'owner', 'mode=apply&older_than=30&confirm=1');
        self::assertSame(302, $applied->status);
        self::assertSame([], $this->storage()->trash());

        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"action":"maintenance.run"', $audit);
        self::assertStringContainsString('"task":"trash:purge"', $audit);
        self::assertStringContainsString('"action":"page.purge","pid"', $audit);
    }

    public function testAWriteWaitsForNoOneWhileAnotherHoldsTheLock(): void
    {
        $lock = MaintenanceRunner::lock($this->dataRoot);
        try {
            $busy = $this->request('POST', '/admin/maintenance/trash:purge', 'owner', 'mode=apply&confirm=1');
            self::assertStringEndsWith('/admin/maintenance?busy=1', $busy->headers['Location']);
            self::assertStringContainsString('in progress', $this->request('GET', '/admin/maintenance?busy=1', 'owner')->body);
            self::assertSame(302, $this->request('POST', '/admin/maintenance/index:verify', 'owner', 'mode=check')->status, 'a check needs no lock');
            self::assertStringEndsWith('/admin/index?busy=1', $this->request('POST', '/admin/index/rebuild', 'owner')->headers['Location'], 'nor does a rebuild run beside it');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testTheJsonReportNamesPagesByPidOnly(): void
    {
        $this->createPage(self::PATH, 'private', 'RM', 'text');
        $pid = $this->storage()->read(self::PATH)->pid;
        $this->backdatedDelete();
        $post = $this->request('POST', '/admin/maintenance/trash:purge', 'owner', 'mode=check&older_than=30');
        preg_match('~run=([0-9a-f-]+)~', $post->headers['Location'], $m);

        $json = $this->request('GET', '/admin/maintenance/runs/' . $m[1] . '.json', 'owner');

        self::assertSame('application/json; charset=utf-8', $json->headers['Content-Type']);
        $data = json_decode($json->body, true);
        self::assertSame('trash:purge', $data['task']);
        self::assertSame('check', $data['mode']);
        self::assertSame(1, $data['summary']['would_purge']);
        self::assertSame($pid, $data['items'][0]['pid']);
        self::assertStringNotContainsString('test-subject', $json->body, 'never the path (invariant 8)');
        self::assertSame(404, $this->request('GET', '/admin/maintenance/runs/' . $m[1] . '.json', 'editor')->status);
        self::assertSame(404, $this->request('GET', '/admin/maintenance/runs/../../users/owner.json', 'owner')->status);
    }

    private function backdatedDelete(): void
    {
        $this->storage()->delete(self::PATH, 'owner');
        // As the journal records it: deleted 40 days ago
        foreach (glob($this->dataRoot . '/journal/*.ndjson') ?: [] as $file) {
            $old = date('Y-m-d\TH:i:sP', time() - 40 * 86400);
            file_put_contents($file, (string) preg_replace('/"ts":"[^"]+"/', '"ts":"' . $old . '"', (string) file_get_contents($file)));
        }
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
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
