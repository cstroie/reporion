<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Audit\AuditLog;
use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Cli\Output;
use Reporion\Cli\TrashPurgeCommand;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Storage\FlatFile;

/**
 * Admin → Trash, POST /api/v1/pages/{path}/restore, DELETE ?purge=1 and
 * bin/reporion trash:purge (D3b for signed pages).
 */
final class TrashTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        (new FlatFileUserStore($this->dataRoot))->create('mihai', 'x', false, [new Grant('reports:mri', GrantRole::Editor)]);
    }

    public function testTheTrashTabListsAndRestoresForOwnersOnly(): void
    {
        $page = $this->storage()->create('reports:mri:mioveni:a', ['title' => 'Alpha', 'visibility' => 'private'], 'body', 'owner');
        $this->storage()->delete('reports:mri:mioveni:a', 'mihai');

        self::assertSame(404, $this->as('mihai', 'GET', '/admin/trash')->status);
        $list = $this->as('owner', 'GET', '/admin/trash');
        self::assertSame(200, $list->status);
        self::assertStringContainsString('Alpha', $list->body);
        self::assertStringContainsString('mihai', $list->body);

        self::assertSame(404, $this->as('mihai', 'POST', '/admin/trash/' . $page->pid . '/restore')->status);
        $restore = $this->as('owner', 'POST', '/admin/trash/' . $page->pid . '/restore');
        self::assertSame(302, $restore->status);
        self::assertSame('/reports:mri:mioveni:a', $restore->headers['Location']);
        self::assertStringContainsString('"action":"page.restore"', $this->audit());
    }

    public function testTheApiRestoresTheLatestDeletionAtAPath(): void
    {
        $this->storage()->create('reports:mri:mioveni:a', ['title' => 'Alpha', 'visibility' => 'private'], 'body', 'owner');
        $this->storage()->delete('reports:mri:mioveni:a', 'owner');

        $response = $this->as('mihai', 'POST', '/api/v1/pages/reports:mri:mioveni:a/restore');

        self::assertSame(200, $response->status);
        self::assertSame('reports:mri:mioveni:a', json_decode($response->body, true)['path']);
    }

    public function testPurgeOnDeleteIsOwnerOnlyAndSignedNeedsTheOverride(): void
    {
        $this->storage()->create('reports:mri:mioveni:a', ['title' => 'A', 'visibility' => 'private'], 'body', 'owner');
        $this->storage()->create('reports:mri:mioveni:s', ['title' => 'S', 'visibility' => 'private'], 'body', 'owner');
        $this->storage()->sign('reports:mri:mioveni:s', 'owner', []);

        self::assertSame(404, $this->as('mihai', 'DELETE', '/api/v1/pages/reports:mri:mioveni:a', ['purge' => '1'])->status);
        self::assertSame(422, $this->as('owner', 'DELETE', '/api/v1/pages/reports:mri:mioveni:s', ['purge' => '1'])->status);
        self::assertSame(200, $this->as('owner', 'DELETE', '/api/v1/pages/reports:mri:mioveni:a', ['purge' => '1'])->status);
        self::assertSame(200, $this->as('owner', 'DELETE', '/api/v1/pages/reports:mri:mioveni:s', ['purge' => '1', 'include_signed' => '1'])->status);

        self::assertSame([], $this->storage()->trash());
        self::assertSame(2, substr_count($this->audit(), '"action":"page.purge"'));
    }

    public function testTheCliPurgesOldDeletionsAndKeepsSignedOnesUnlessNamedOverride(): void
    {
        $storage = $this->storage();
        $old = $storage->create('docs:old', ['title' => 'Old', 'visibility' => 'private'], 'body', 'owner');
        $oldSigned = $storage->create('docs:old-signed', ['title' => 'Old signed', 'visibility' => 'private'], 'body', 'owner');
        $storage->sign('docs:old-signed', 'owner', []);
        $recent = $storage->create('docs:recent', ['title' => 'Recent', 'visibility' => 'private'], 'body', 'owner');
        // Deleted 40 days ago, as the journal records it
        $storage->delete('docs:old', 'owner');
        $storage->delete('docs:old-signed', 'owner');
        $this->backdateJournal(40);
        $storage->delete('docs:recent', 'owner');

        $command = new TrashPurgeCommand($storage, new AuditLog($this->dataRoot . '/audit'), 30);
        self::assertSame(1, $command->run(['--include-signed'], $this->cliOutput()), 'the override needs a named operator');
        self::assertSame(0, $command->run([], $this->cliOutput()));
        self::assertSame([$oldSigned->pid, $recent->pid], $this->pidsInTrash());

        self::assertSame(0, $command->run(['--include-signed', '--operator=owner'], $this->cliOutput()));
        self::assertSame([$recent->pid], $this->pidsInTrash());
    }

    private function backdateJournal(int $days): void
    {
        $then = (new \DateTimeImmutable('-' . $days . ' days'))->format('Y-m-d\TH:i:sP');
        foreach (glob($this->dataRoot . '/journal/*.ndjson') ?: [] as $file) {
            $lines = array_map(static function (string $line) use ($then): string {
                $data = json_decode($line, true);
                if (($data['op'] ?? null) === 'delete') {
                    $data['ts'] = $then;
                }

                return (string) json_encode($data);
            }, array_filter(explode("\n", (string) file_get_contents($file))));
            file_put_contents($file, implode("\n", $lines) . "\n");
        }
    }

    /** @return list<string> */
    private function pidsInTrash(): array
    {
        $pids = array_column($this->storage()->trash(), 'pid');
        sort($pids);

        return $pids;
    }

    private function cliOutput(): Output
    {
        return new Output(fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    }

    private function audit(): string
    {
        return (string) @file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }

    /** @param array<string, string> $query */
    private function as(string $username, string $method, string $path, array $query = []): Response
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($username);

        return Kernel::boot($this->config)->handle(new Request($method, $path, query: $query, cookies: ['reporion' => $cookie]));
    }
}
