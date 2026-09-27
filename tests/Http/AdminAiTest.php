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
use Reporion\Service\InstanceSettings;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Ai\FakeServer;

/**
 * Admin → AI (phase 15; its own pane since 2026-09-27): the assistant's
 * settings, its API key, in data/settings.yaml — the key never shown back —
 * the server check and the prompt profiles.
 */
final class AdminAiTest extends HttpTestCase
{
    private const FORM = [
        'ai_enabled' => '1', 'ai_endpoint' => 'http://127.0.0.1:8080/v1/', 'ai_model' => 'qwen2.5:32b',
        'ai_temperature' => '0.2', 'ai_top_p' => '0.9', 'ai_max_tokens' => '2048', 'ai_timeout' => '90',
        'ai_profiles' => "reports = reports\n* = default",
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('owner', password_hash('x', PASSWORD_ARGON2ID), true);
        $users->create('editor', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('reports', GrantRole::Editor)]);
    }

    public function testOwnerOnlyAndNotOnTheSettingsPage(): void
    {
        self::assertSame(200, $this->request('GET', '/admin/ai', 'owner')->status);
        foreach ([null, 'editor'] as $user) {
            self::assertSame(404, $this->request('GET', '/admin/ai', $user)->status);
            self::assertSame(404, $this->request('POST', '/admin/ai', $user, http_build_query(self::FORM))->status);
            self::assertSame(404, $this->request('POST', '/admin/ai/check', $user)->status);
        }
        self::assertSame(404, $this->request('POST', '/admin/settings/ai', 'owner', http_build_query(self::FORM))->status, 'no longer a settings section');
        self::assertStringNotContainsString('id="ai"', $this->request('GET', '/admin/settings', 'owner')->body);
        self::assertStringContainsString('href="/admin/ai"', $this->request('GET', '/admin/settings', 'owner')->body, 'a tab of its own');
    }

    public function testTheSettingsAndTheKeyAreSavedAndTheKeyIsNeverShown(): void
    {
        $secret = 'sk-test-0123456789abcdef';
        self::assertSame(302, $this->request('POST', '/admin/ai', 'owner', http_build_query(self::FORM + ['ai_api_key' => $secret]))->status);

        $ai = (new InstanceSettings($this->dataRoot))->load()['ai'];
        self::assertTrue($ai['enabled']);
        self::assertSame('http://127.0.0.1:8080/v1', $ai['endpoint']);
        self::assertSame(['reports' => 'reports', '*' => 'default'], $ai['profiles']);
        self::assertArrayNotHasKey('allow_egress_to', $ai, 'no host list any more');
        self::assertFalse($ai['external_ack'], 'an unticked box');
        self::assertSame(0.2, $ai['temperature']);
        self::assertSame($secret, $ai['api_key']);
        self::assertSame('0640', substr(sprintf('%o', fileperms($this->dataRoot . '/settings.yaml')), -4));

        $screen = $this->request('GET', '/admin/ai', 'owner')->body;
        self::assertStringNotContainsString($secret, $screen);
        self::assertStringContainsString('set — leave blank to keep', $screen);
        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"ai.api_key"', $audit, 'which keys changed');
        self::assertStringNotContainsString($secret, $audit, 'never the value');

        // A blank field keeps it; the box removes it
        $this->request('POST', '/admin/ai', 'owner', http_build_query(self::FORM + ['ai_api_key' => '']));
        self::assertSame($secret, (new InstanceSettings($this->dataRoot))->load()['ai']['api_key']);
        $this->request('POST', '/admin/ai', 'owner', http_build_query(self::FORM + ['remove_api_key' => '1']));
        self::assertSame('', (new InstanceSettings($this->dataRoot))->load()['ai']['api_key']);

        self::assertSame(422, $this->request('POST', '/admin/ai', 'owner', http_build_query(['ai_endpoint' => 'ftp://x'] + self::FORM))->status);
        self::assertSame(422, $this->request('POST', '/admin/ai', 'owner', http_build_query(['ai_temperature' => '3'] + self::FORM))->status);
        self::assertSame(422, $this->request('POST', '/admin/ai', 'owner', http_build_query(self::FORM + ['ai_api_key' => "two words"]))->status);
    }

    public function testTheCheckListsTheServersModelsAndTheProfilesTheirPages(): void
    {
        $server = new FakeServer();
        try {
            $this->request('POST', '/admin/ai', 'owner', http_build_query(['ai_endpoint' => $server->url, 'ai_model' => 'test-model'] + self::FORM));
            $storage = new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
            $storage->create('ai:profiles:reports:conclusion', ['title' => 'Conclusion', 'label' => 'Concluzie', 'order' => 20, 'visibility' => 'private'], "{text}\n", 'owner');
            $storage->create('ai:profiles:reports:custom', ['title' => 'Custom', 'enabled' => false, 'visibility' => 'private'], "{prompt}\n", 'owner');

            $screen = $this->request('GET', '/admin/ai', 'owner')->body;
            self::assertStringNotContainsString('test-model, ', $screen, 'no server call until asked');
            self::assertStringContainsString('href="/ai:profiles:reports:conclusion"', $screen);
            self::assertStringContainsString('Concluzie', $screen);
            self::assertStringContainsString('ai:profiles:default', $screen, 'a profile with no pages yet says so');

            $checked = $this->request('POST', '/admin/ai/check', 'owner');
            self::assertSame(200, $checked->status);
            self::assertStringContainsString('<datalist id="ai-models">', $checked->body);
            self::assertStringContainsString('test-model', $checked->body);
        } finally {
            $server->stop();
        }
    }

    private function request(string $method, string $path, ?string $user, string $body = ''): Response
    {
        return Kernel::boot($this->config)->handle(new Request(
            $method,
            $path,
            cookies: $user === null ? [] : ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user)],
            body: $body,
        ));
    }
}
