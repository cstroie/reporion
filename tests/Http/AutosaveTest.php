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
 * The server autosave (D25 amended 2026-10-12): PUT/GET/DELETE
 * /api/v1/pages/{path}/autosave, the editor's notice and its Restore.
 * Fictitious data only (invariant 10).
 */
final class AutosaveTest extends HttpTestCase
{
    private const PATH = 'docs:note';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('editor', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('docs', GrantRole::Editor)]);
        $users->create('viewer', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('docs', GrantRole::Viewer)]);
        $this->createPage(self::PATH, 'private', 'Note', "Original text.\n");
    }

    /** @param array<string, mixed>|null $json */
    private function call(?string $user, string $method, string $path, ?array $json = null, string $body = ''): Response
    {
        $cookies = [];
        if ($user !== null) {
            $cookies['reporion'] = (new Session(
                (string) $this->config['auth']['session_secret'],
                (string) $this->config['auth']['session_name'],
                (int) $this->config['auth']['session_lifetime'],
                new FlatFileUserStore($this->dataRoot),
            ))->issue($user);
        }

        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);

        return Kernel::boot($this->config)->handle(new Request($method, (string) parse_url($path, PHP_URL_PATH), query: $query, cookies: $cookies, body: $json !== null ? (string) json_encode($json) : $body));
    }

    /** Whether $user has a draft of docs:note, as the state database holds it (D54) */
    private function hasDraft(string $user): bool
    {
        $stmt = (new \PDO('sqlite:' . $this->dataRoot . '/reporion.sqlite'))->prepare(
            "SELECT COUNT(*) FROM draft d JOIN page p ON p.pid = d.pid WHERE p.path = 'docs:note' AND d.username = ?"
        );
        $stmt->execute([$user]);

        return (int) $stmt->fetchColumn() === 1;
    }

    /** @return array<string, mixed> */
    private function draftBody(int $baseRev = 1, bool $raw = false, string $form = 'body=Typed+text'): array
    {
        return ['base_rev' => $baseRev, 'raw' => $raw, 'form' => $form];
    }

    public function testWritersKeepTheirOwnDraftEveryoneElseGetsA404(): void
    {
        $url = '/api/v1/pages/' . self::PATH . '/autosave';
        foreach (['owner', 'editor'] as $user) {
            self::assertSame(204, $this->call($user, 'PUT', $url, $this->draftBody())->status, $user);
            self::assertTrue($this->hasDraft($user));
        }
        foreach ([null, 'viewer'] as $user) {
            self::assertSame(404, $this->call($user, 'PUT', $url, $this->draftBody())->status);
            self::assertSame(404, $this->call($user, 'GET', $url)->status);
            self::assertSame(404, $this->call($user, 'DELETE', $url)->status);
        }
        self::assertSame(404, $this->call('owner', 'PUT', '/api/v1/pages/docs:none/autosave', $this->draftBody())->status, 'a page not written yet');

        $meta = json_decode($this->call('editor', 'GET', $url)->body, true);
        self::assertSame(['base_rev' => 1, 'current_rev' => 1, 'stale' => false, 'raw' => false], array_diff_key($meta, ['ts' => 1]));
        self::assertStringNotContainsString('Typed', $this->call('editor', 'GET', $url)->body, 'metadata only: the text comes back through the editor');

        self::assertSame(204, $this->call('editor', 'DELETE', $url)->status);
        self::assertFalse($this->hasDraft('editor'));
        self::assertTrue($this->hasDraft('owner'), "not the owner's");
        self::assertSame(1, (new \Reporion\Storage\FileTree($this->dataRoot, new \Reporion\Index\Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations')))->read(self::PATH)->rev, 'never a revision');
    }

    public function testBadRequests(): void
    {
        $url = '/api/v1/pages/' . self::PATH . '/autosave';
        self::assertSame(422, $this->call('owner', 'PUT', $url, ['raw' => false, 'form' => 'x'])->status);
        self::assertSame(422, $this->call('owner', 'PUT', $url, ['base_rev' => '1', 'raw' => false, 'form' => 'x'])->status);

        $late = $this->call('owner', 'PUT', $url, $this->draftBody(0));
        self::assertSame(409, $late->status, 'the editor is behind');
        self::assertSame('conflict', json_decode($late->body, true)['error']['code']);
        self::assertFalse($this->hasDraft('owner'), 'nothing stored');

        self::assertSame(422, $this->call('owner', 'PUT', $url, $this->draftBody(1, false, str_repeat('x', 2 * 1024 * 1024 + 1)))->status);
    }

    public function testNoAuditLineAndNeverThePath(): void
    {
        $this->call('owner', 'PUT', '/api/v1/pages/' . self::PATH . '/autosave', $this->draftBody());
        $audit = '';
        foreach (glob($this->dataRoot . '/audit/*') ?: [] as $file) {
            $audit .= (string) file_get_contents($file);
        }
        self::assertStringNotContainsString('autosave', $audit);
        self::assertStringNotContainsString(self::PATH, $audit);
    }

    public function testTheEditorOffersTheDraftAndRestoreOpensOnIt(): void
    {
        $edit = '/' . self::PATH . '/edit';
        $plain = $this->call('editor', 'GET', $edit);
        self::assertSame(200, $plain->status);
        self::assertStringContainsString('"autosave":{"url":"\/api\/v1\/pages\/docs:note\/autosave"', $plain->body);
        self::assertStringContainsString('"delayMs":60000', $plain->body);
        self::assertStringNotContainsString('editor-autosave-banner', $plain->body, 'no draft yet');

        $this->call('editor', 'PUT', '/api/v1/pages/' . self::PATH . '/autosave', $this->draftBody(1, false, 'body=' . rawurlencode("Typed before the crash.\n") . '&fm_shown%5B%5D=title&fm%5Btitle%5D=' . rawurlencode('Draft title')));

        $offered = $this->call('editor', 'GET', $edit);
        self::assertStringContainsString('id="editor-autosave-banner"', $offered->body);
        self::assertStringContainsString('edit?autosave=restore', $offered->body);
        self::assertStringNotContainsString('Typed before the crash', $offered->body, 'offered, never applied by itself');
        self::assertStringContainsString('Original text.', $offered->body);
        self::assertStringNotContainsString('id="editor-autosave-banner"', $this->call('owner', 'GET', $edit)->body, "someone else's draft is not offered");

        $restored = $this->call('editor', 'GET', $edit . '?autosave=restore');
        self::assertSame(200, $restored->status);
        self::assertStringContainsString('Typed before the crash.', $restored->body);
        self::assertStringContainsString('Draft title', $restored->body, 'the Details fields too');
        self::assertStringContainsString('Restored from the server autosave', $restored->body);
        self::assertStringContainsString('"unsaved":true', $restored->body, 'nothing is stored until Save');
        self::assertStringNotContainsString('id="editor-autosave-banner"', $restored->body);
        self::assertStringContainsString('name="base_rev" value="1"', $restored->body);
        self::assertTrue($this->hasDraft('editor'), 'restoring does not remove the draft; the Save does');

        // A draft made in the other mode sends the editor to that mode first
        $this->call('editor', 'PUT', '/api/v1/pages/' . self::PATH . '/autosave', $this->draftBody(1, true, 'document=' . rawurlencode("---\ntitle: Raw\n---\n\nRaw typed.\n")));
        $redirect = $this->call('editor', 'GET', $edit . '?autosave=restore');
        self::assertSame(302, $redirect->status);
        self::assertStringEndsWith('/edit?raw=1&autosave=restore', $redirect->headers['Location']);
        $raw = $this->call('editor', 'GET', $edit . '?raw=1&autosave=restore');
        self::assertStringContainsString('Raw typed.', $raw->body);
    }

    public function testASaveRemovesTheDraftAndAStaleDraftIsOfferedWithAWarning(): void
    {
        $url = '/api/v1/pages/' . self::PATH . '/autosave';
        $this->call('editor', 'PUT', $url, $this->draftBody());
        $this->call('owner', 'PUT', $url, $this->draftBody());

        $saved = $this->call('editor', 'POST', '/' . self::PATH . '/edit', null, 'base_rev=1&body=' . rawurlencode("Saved by editor.\n"));
        self::assertSame(302, $saved->status);
        self::assertFalse($this->hasDraft('editor'), 'the Save removed its author\'s draft');
        self::assertTrue($this->hasDraft('owner'));

        // The owner's draft is now of an older revision: still offered, with the warning, and Save meets the conflict
        $offered = $this->call('owner', 'GET', '/' . self::PATH . '/edit');
        self::assertStringContainsString('id="editor-autosave-banner"', $offered->body);
        self::assertStringContainsString('made on revision 1', $offered->body);
        $restored = $this->call('owner', 'GET', '/' . self::PATH . '/edit?autosave=restore');
        self::assertStringContainsString('name="base_rev" value="1"', $restored->body, 'the draft\'s own revision: Save shows the conflict screen');
        self::assertStringContainsString('made on revision 1', $restored->body);
        self::assertSame(409, $this->call('owner', 'POST', '/' . self::PATH . '/edit', null, 'base_rev=1&body=Restored+text')->status);

        // And an editor still on revision 1 cannot store a new one
        self::assertSame(409, $this->call('owner', 'PUT', $url, $this->draftBody(1))->status);
        self::assertSame(204, $this->call('owner', 'PUT', $url, $this->draftBody(2))->status);
    }
}
