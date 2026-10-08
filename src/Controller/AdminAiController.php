<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ApiResponse;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Service\Ai\Actions;
use Reporion\Service\Ai\AiConfig;
use Reporion\Service\Ai\Check;
use Reporion\Service\Ai\Usage;
use Reporion\Service\InstanceSettings;

/**
 * Admin → AI, owner-only (phase 15; its own pane since 2026-09-27): what is
 * in use — the assistant on or off, which of the three servers, which
 * prompt profile and on which namespaces — and the servers themselves
 * (address, model, API key, sampling, time limit, egress acknowledgement),
 * all in data/settings.yaml like the rest of the instance's settings.
 *
 * GET /admin/ai; POST /admin/ai/use and /admin/ai/servers save their form
 * (Post/Redirect/Get, audited settings.change with the keys that changed —
 * never a value); POST /admin/ai/check asks the server in use for its
 * models, as `ai:check` does. A key is never sent back to the browser: a
 * blank field keeps it.
 */
final class AdminAiController
{
    /** What the "in use" form saves */
    public const USE = ['ai.enabled', 'ai.server', 'ai.prompt_profile', 'ai.namespaces', 'ai.fallback_profile', 'ai.embed_server', 'ai.embed_model'];

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
    public function show(Request $request, ?User $principal, ?string $error = null, ?string $errorSection = null, ?array $check = null): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }
        $section = \is_array($this->config['ai'] ?? null) ? $this->config['ai'] : [];
        $ai = AiConfig::fromConfig($this->config);
        $servers = [];
        foreach (AiConfig::servers($section) as $i => $server) {
            $servers[] = [
                'name' => AiConfig::serverName($server, $i + 1),
                'keySet' => \is_string($server['api_key'] ?? null) && $server['api_key'] !== '',
                // Per alias, as stored or read from the flat fields of before (phase 33a)
                'tiers' => AiConfig::tierRows($server),
            ] + array_diff_key($server, ['api_key' => true, 'name' => true, 'tiers' => true]) + ['rawName' => \is_string($server['name'] ?? null) ? $server['name'] : ''];
        }
        $names = $this->actions->profiles();
        foreach (array_filter([$ai->promptProfile, $ai->fallbackProfile]) as $inUse) {
            if (!\in_array($inUse, $names, true)) {
                $names[] = $inUse;
            }
        }
        $profiles = [];
        $overview = [];
        foreach ($names as $profile) {
            $profiles[$profile] = true;
            // Phase 33e: what the profile holds, and where it serves
            $overview[$profile] = $this->actions->overview($profile) + [
                'serves' => $profile === $ai->promptProfile ? 'main' : ($profile === $ai->fallbackProfile ? 'fallback' : ''),
                'fallbackToo' => $profile === $ai->promptProfile && $profile === $ai->fallbackProfile,
            ];
        }

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/admin-ai.php', [
            'ai' => $ai,
            'servers' => $servers,
            'status' => $check ?? $this->check->run($ai, false),
            'checked' => $check !== null,
            'profiles' => $profiles,
            'overview' => $overview,
            // Phase 34e: the one embedding model of Similar reports
            'embedServer' => is_numeric($section['embed_server'] ?? null) ? (int) $section['embed_server'] : 0,
            'embedModel' => \is_string($section['embed_model'] ?? null) ? $section['embed_model'] : '',
            // Phase 34b: the assistant's use, read from the audit
            'usage' => (new Usage($this->audit->directory()))->compute(new \DateTimeImmutable('now'), is_numeric($request->query['days'] ?? null) ? (int) $request->query['days'] : 30),
            'saved' => (string) ($request->query['saved'] ?? ''),
            'error' => $error,
            'errorSection' => $errorSection,
            'adminTab' => 'ai',
            'basePath' => $request->basePath,
        ] + ChromeVars::shell($request, $principal, $this->index, ''), t('admin.ai.title')), $error === null ? 200 : 422);
    }

    public function save(Request $request, string $section, ?User $principal): Response
    {
        if ($principal?->isOwner !== true || !\in_array($section, ['use', 'servers'], true)) {
            throw new PageNotFoundException();
        }
        parse_str($request->body, $fields);
        $changes = [];
        if ($section === 'use') {
            foreach (self::USE as $key) {
                // parse_str turns dots into underscores; an unticked box is absent
                $changes[$key] = $fields[str_replace('.', '_', $key)] ?? '';
            }
        } else {
            $changes['ai.servers'] = $fields['servers'] ?? [];
        }

        try {
            $changed = $this->settings->save($changes);
        } catch (InvalidArgumentException $e) {
            return $this->show($request, $principal, $e->getMessage(), $section);
        }
        if ($changed !== []) {
            $this->audit->record('settings.change', $principal->username, $request, extra: ['section' => 'ai', 'keys' => $changed]);
        }

        return Response::redirect($request->basePath . '/admin/ai?saved=' . $section . '#' . $section);
    }

    public function check(Request $request, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }

        return $this->show($request, $principal, null, null, $this->check->run(AiConfig::fromConfig($this->config)));
    }

    /**
     * POST /admin/ai/servers/{slot}/models — one server's models, through its
     * filter, from its saved settings (the key never comes back to a browser,
     * so an unsaved card has to be saved first): `{data, total, error}` —
     * phase 33c. Owner-only, 404 otherwise.
     */
    public function models(Request $request, string $slot, ?User $principal): Response
    {
        $ai = $this->server($slot, $principal);

        return ApiResponse::json($this->check->models($ai));
    }

    /**
     * POST /admin/ai/servers/{slot}/test — each alias of one server: listed,
     * and one fixed one-line request with its parameters (no report text) —
     * `{data: [{tier, model, listed, ok, ms, answer, error}]}`; audited
     * `ai.test` with the outcomes, never an answer. Owner-only.
     */
    public function test(Request $request, string $slot, ?User $principal): Response
    {
        $ai = $this->server($slot, $principal);
        set_time_limit(\count(AiConfig::TIERS) * ($ai->timeout + 5) + 30);
        $rows = $this->check->test($ai);
        $this->audit->record('ai.test', $principal?->username ?? '', $request, outcome: array_filter($rows, static fn (array $r): bool => !$r['ok']) === [] ? 'ok' : 'error', extra: [
            'server' => $ai->server,
            'tiers' => array_map(static fn (array $r): array => ['tier' => $r['tier'], 'model' => $r['model'], 'ok' => $r['ok'], 'ms' => $r['ms']], $rows),
        ]);

        return ApiResponse::json(['data' => $rows]);
    }

    /** The saved settings of server $slot, for its owner; 404 for anyone else or a slot that is not one */
    private function server(string $slot, ?User $principal): AiConfig
    {
        if ($principal?->isOwner !== true || !ctype_digit($slot) || (int) $slot < 1 || (int) $slot > AiConfig::SLOTS) {
            throw new PageNotFoundException();
        }

        return AiConfig::fromConfig($this->config, (int) $slot);
    }
}
