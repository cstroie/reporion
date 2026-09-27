<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Service\Ai\Actions;
use Reporion\Service\Ai\AiConfig;
use Reporion\Service\Ai\Check;
use Reporion\Service\InstanceSettings;

/**
 * Admin → AI, owner-only (phase 15; its own pane since 2026-09-27): the
 * assistant's server, model and limits, its API key, the prompt profiles
 * and their action pages — all in data/settings.yaml, like the rest of the
 * instance's settings.
 *
 * GET /admin/ai; POST /admin/ai saves (Post/Redirect/Get, audited
 * settings.change with the keys that changed — never a value); POST
 * /admin/ai/check asks the server for its models, as `ai:check` does. The
 * key is never sent back to the browser: a blank field keeps it.
 */
final class AdminAiController
{
    public const KEYS = ['ai.enabled', 'ai.endpoint', 'ai.model', 'ai.temperature', 'ai.top_p', 'ai.max_tokens', 'ai.timeout', 'ai.profiles', 'ai.external_ack'];

    /** @param array<string, mixed> $config the effective config (settings already applied) */
    public function __construct(
        private readonly InstanceSettings $settings,
        private readonly array $config,
        private readonly Actions $actions,
        private readonly Check $check,
        private readonly IndexInterface $index,
        private readonly AuditLog $audit,
    ) {
    }

    /** @param ?array<string, mixed> $check a server check's report, when one was asked for */
    public function show(Request $request, ?User $principal, ?string $error = null, ?array $check = null): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }
        $ai = AiConfig::fromConfig($this->config);
        $stored = $this->settings->load();
        $storedAi = \is_array($stored['ai'] ?? null) ? $stored['ai'] : [];
        $fromFile = [];
        foreach ([...self::KEYS, 'ai.api_key'] as $key) {
            $fromFile[$key] = \array_key_exists(substr($key, 3), $storedAi);
        }
        $values = array_intersect_key(InstanceSettings::current($this->config), array_flip(self::KEYS));
        $profiles = [];
        foreach (array_unique(array_values($ai->profiles)) as $profile) {
            $profiles[$profile] = $this->actions->pages($profile);
        }

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/admin-ai.php', [
            'values' => $values,
            'fromFile' => $fromFile,
            'keySet' => $ai->apiKey !== '',
            'status' => $check ?? $this->check->run($ai, false),
            'checked' => $check !== null,
            'profiles' => $profiles,
            'saved' => ($request->query['saved'] ?? '') === '1',
            'error' => $error,
            'adminTab' => 'ai',
            'basePath' => $request->basePath,
        ] + ChromeVars::shell($request, $principal, $this->index, ''), t('admin.ai.title')), $error === null ? 200 : 422);
    }

    public function save(Request $request, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }
        parse_str($request->body, $fields);
        $changes = [];
        foreach (self::KEYS as $key) {
            // parse_str turns dots into underscores; an unticked box is absent
            $changes[$key] = $fields[str_replace('.', '_', $key)] ?? '';
        }
        // The key is never shown: blank keeps it, the box removes it
        if (($fields['remove_api_key'] ?? '') === '1') {
            $changes['ai.api_key'] = '';
        } elseif (\is_string($fields['ai_api_key'] ?? null) && trim($fields['ai_api_key']) !== '') {
            $changes['ai.api_key'] = $fields['ai_api_key'];
        }

        try {
            $changed = $this->settings->save($changes);
        } catch (InvalidArgumentException $e) {
            return $this->show($request, $principal, $e->getMessage());
        }
        if ($changed !== []) {
            $this->audit->record('settings.change', $principal->username, $request, extra: ['section' => 'ai', 'keys' => $changed]);
        }

        return Response::redirect($request->basePath . '/admin/ai?saved=1');
    }

    public function check(Request $request, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }

        return $this->show($request, $principal, null, $this->check->run(AiConfig::fromConfig($this->config)));
    }
}
