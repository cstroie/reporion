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
    private const SERVER = ['name' => 'Local', 'endpoint' => 'http://127.0.0.1:8080/v1/', 'model' => 'qwen2.5:32b', 'temperature' => '0.2', 'top_p' => '0.9', 'max_tokens' => '2048', 'timeout' => '90'];

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
            self::assertSame(404, $this->request('POST', '/admin/ai/use', $user, 'ai_server=2&ai_prompt_profile=reports&ai_namespaces=reports')->status);
            self::assertSame(404, $this->request('POST', '/admin/ai/servers', $user, $this->servers([self::SERVER]))->status);
            self::assertSame(404, $this->request('POST', '/admin/ai/check', $user)->status);
        }
        self::assertSame(404, $this->request('POST', '/admin/settings/ai', 'owner', 'ai_enabled=1')->status, 'no longer a settings section');
        self::assertStringNotContainsString('id="ai"', $this->request('GET', '/admin/settings', 'owner')->body);
        self::assertStringContainsString('href="/admin/ai"', $this->request('GET', '/admin/settings', 'owner')->body, 'a tab of its own');
    }

    public function testThreeServersEachWithItsKeyNeverShownAndOneInUse(): void
    {
        $secret = 'sk-test-0123456789abcdef';
        $remote = ['name' => 'Cloud', 'endpoint' => 'https://llm.example.com/v1/chat/completions', 'model' => 'big', 'model_lite' => 'small', 'model_expert' => 'biggest', 'api_key' => $secret, 'external_ack' => '1'];
        self::assertSame(302, $this->request('POST', '/admin/ai/servers', 'owner', $this->servers([self::SERVER, $remote]))->status);

        $ai = (new InstanceSettings($this->dataRoot))->load()['ai'];
        self::assertCount(3, $ai['servers']);
        self::assertSame(['name' => 'Local', 'endpoint' => 'http://127.0.0.1:8080/v1', 'model' => 'qwen2.5:32b', 'model_lite' => '', 'model_expert' => '', 'api_key' => '', 'temperature' => 0.2, 'top_p' => 0.9, 'max_tokens' => 2048, 'timeout' => 90, 'external_ack' => false], $ai['servers'][0]);
        self::assertSame($secret, $ai['servers'][1]['api_key']);
        self::assertTrue($ai['servers'][1]['external_ack']);
        self::assertSame(['small', 'big', 'biggest'], [$ai['servers'][1]['model_lite'], $ai['servers'][1]['model'], $ai['servers'][1]['model_expert']], 'the three aliases');
        self::assertSame('', $ai['servers'][2]['endpoint'], 'an empty slot');
        self::assertSame('0640', substr(sprintf('%o', fileperms($this->dataRoot . '/settings.yaml')), -4));

        self::assertSame(302, $this->request('POST', '/admin/ai/use', 'owner', 'ai_enabled=1&ai_server=2&ai_prompt_profile=reports&ai_namespaces=reports%2C+docs')->status);
        $screen = $this->request('GET', '/admin/ai', 'owner')->body;
        self::assertStringNotContainsString($secret, $screen);
        self::assertStringContainsString('set — leave blank to keep', $screen);
        self::assertStringContainsString('<option value="2" selected>2 · Cloud</option>', $screen);
        self::assertStringContainsString('https://llm.example.com/v1', $screen, 'the server in use, its base address');
        self::assertStringContainsString('→ reports, docs', $screen);
        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"ai.servers"', $audit, 'which keys changed');
        self::assertStringContainsString('"ai.server"', $audit);
        self::assertStringNotContainsString($secret, $audit, 'never the value');

        // A blank key keeps that server's; the box removes it
        $this->request('POST', '/admin/ai/servers', 'owner', $this->servers([self::SERVER, ['api_key' => ''] + $remote]));
        self::assertSame($secret, (new InstanceSettings($this->dataRoot))->load()['ai']['servers'][1]['api_key']);
        $this->request('POST', '/admin/ai/servers', 'owner', $this->servers([self::SERVER, ['api_key' => '', 'remove_api_key' => '1'] + $remote]));
        self::assertSame('', (new InstanceSettings($this->dataRoot))->load()['ai']['servers'][1]['api_key']);

        $bad = $this->request('POST', '/admin/ai/servers', 'owner', $this->servers([self::SERVER, ['endpoint' => 'ftp://x'] + $remote]));
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('Server 2: ', $bad->body, 'the message names the slot');
        self::assertSame(422, $this->request('POST', '/admin/ai/servers', 'owner', $this->servers([['temperature' => '3'] + self::SERVER]))->status);

        // A blank sampling field stays blank — not sent — and reads back blank
        $this->request('POST', '/admin/ai/servers', 'owner', $this->servers([['top_p' => '', 'max_tokens' => ' '] + self::SERVER]));
        $saved = (new InstanceSettings($this->dataRoot))->load()['ai']['servers'][0];
        self::assertSame([0.2, '', ''], [$saved['temperature'], $saved['top_p'], $saved['max_tokens']]);
        $config = \Reporion\Service\Ai\AiConfig::fromConfig(['ai' => ['servers' => [$saved]]]);
        self::assertSame([0.2, null, 0], [$config->temperature, $config->topP, $config->maxTokens]);
        self::assertSame(0.8, \Reporion\Service\Ai\AiConfig::fromConfig(['ai' => ['servers' => [['endpoint' => 'http://x/v1']]]])->topP, 'never written: the default');
        self::assertSame(422, $this->request('POST', '/admin/ai/servers', 'owner', $this->servers([['api_key' => 'two words'] + self::SERVER]))->status);
        self::assertSame(422, $this->request('POST', '/admin/ai/use', 'owner', 'ai_server=4&ai_prompt_profile=reports&ai_namespaces=reports')->status);
    }

    public function testTheFlatSettingsOfBeforeAreServerOneUntilTheNextSave(): void
    {
        file_put_contents($this->dataRoot . '/settings.yaml', "ai:\n  enabled: true\n  endpoint: 'http://127.0.0.1:9/v1'\n  model: old-model\n  api_key: sk-old\n  profiles: {reports: reports, '*': default}\n  allow_egress_to: [x.example]\n");

        $screen = $this->request('GET', '/admin/ai', 'owner')->body;
        self::assertStringContainsString('value="old-model"', $screen);
        self::assertStringContainsString('<option value="1" selected>1 · Server 1</option>', $screen);

        $this->request('POST', '/admin/ai/servers', 'owner', $this->servers([['name' => 'Old', 'endpoint' => 'http://127.0.0.1:9/v1', 'model' => 'old-model']]));
        $this->request('POST', '/admin/ai/use', 'owner', 'ai_enabled=1&ai_server=1&ai_prompt_profile=reports&ai_namespaces=reports');
        $ai = (new InstanceSettings($this->dataRoot))->load()['ai'];
        self::assertSame('sk-old', $ai['servers'][0]['api_key'], 'the key came along');
        foreach (['endpoint', 'model', 'api_key', 'profiles', 'allow_egress_to'] as $old) {
            self::assertArrayNotHasKey($old, $ai, $old);
        }
    }

    public function testTheCheckListsTheServersModelsAndTheProfilesTheirPages(): void
    {
        $server = new FakeServer();
        try {
            $this->request('POST', '/admin/ai/servers', 'owner', $this->servers([['endpoint' => $server->url, 'model' => 'test-model'] + self::SERVER]));
            $this->request('POST', '/admin/ai/use', 'owner', 'ai_enabled=1&ai_server=1&ai_prompt_profile=reports&ai_namespaces=reports');
            $storage = new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
            $storage->create('ai:profiles:reports:conclusion', ['title' => 'Conclusion', 'label' => 'Concluzie', 'order' => 20, 'visibility' => 'private'], "{text}\n", 'owner');
            $storage->create('ai:profiles:reports:custom', ['title' => 'Custom', 'enabled' => false, 'visibility' => 'private'], "{prompt}\n", 'owner');
            $storage->create('ai:profiles:short:conclusion', ['title' => 'Conclusion', 'visibility' => 'private'], "{text}\n", 'owner');

            $screen = $this->request('GET', '/admin/ai', 'owner')->body;
            self::assertStringNotContainsString('<datalist id="ai-models">', $screen, 'no server call until asked');
            self::assertStringContainsString('href="/ai:profiles:reports:conclusion"', $screen);
            self::assertStringContainsString('Concluzie', $screen);
            self::assertStringContainsString('<option value="short">ai:profiles:short</option>', $screen, 'every profile with pages can be chosen');

            $checked = $this->request('POST', '/admin/ai/check', 'owner');
            self::assertSame(200, $checked->status);
            self::assertStringContainsString('<datalist id="ai-models">', $checked->body);
            self::assertStringContainsString('test-model', $checked->body);
        } finally {
            $server->stop();
        }
    }

    /** @param list<array<string, string>> $rows */
    private function servers(array $rows): string
    {
        return http_build_query(['servers' => $rows]);
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
