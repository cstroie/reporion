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
use Reporion\Service\Ai\DefaultPrompts;
use Reporion\Service\Ai\AiConfig;
use Reporion\Service\Ai\Check;
use Reporion\Service\Ai\Usage;
use Reporion\Service\InstanceSettings;

/**
 * Admin → AI, owner-only (phase 15; its own pane since 2026-09-27): what is
 * in use: the assistant on or off, which of the three servers, which
 * prompt profile and on which namespaces, and the servers themselves
 * (address, model, API key, sampling, time limit, egress acknowledgement),
 * all in data/settings.yaml like the rest of the instance's settings.
 *
 * GET /admin/ai; POST /admin/ai/use and /admin/ai/servers save their form
 * (Post/Redirect/Get, audited settings.change with the keys that changed
 * never a value); POST /admin/ai/check asks the server in use for its
 * models, as `ai:check` does. A key is never sent back to the browser: a
 * blank field keeps it.
 */
final class AdminAiController
{
    /** What the Assistant panel's form saves (its routing table); the switch, the default server and Similar reports post alone */
    public const ROUTES = ['ai.prompt_profile', 'ai.namespaces', 'ai.fallback_profile', 'ai.profile_map'];

    /** What the Similar reports panel's form saves */
    public const SIMILAR = ['ai.embed_server', 'ai.embed_model', 'ai.embed_min_score', 'ai.embed_common_min'];

    /** The audio overview's speech model (docs/library.md, phase 8) */
    public const SPEECH = ['ai.speech_server', 'ai.speech_model', 'ai.speech_voices'];

    /** @param array<string, mixed> $config the effective config (settings already applied) */
    public function __construct(
        private readonly InstanceSettings $settings,
        private readonly array $config,
        private readonly Actions $actions,
        private readonly Check $check,
        private readonly IndexInterface $index,
        private readonly AuditLog $audit,
        private readonly ?DefaultPrompts $defaults = null,
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
                // Per alias, as stored (phase 33a)
                'tiers' => AiConfig::tierRows($server),
            ] + array_diff_key($server, ['api_key' => true, 'name' => true, 'tiers' => true]) + ['rawName' => \is_string($server['name'] ?? null) ? $server['name'] : ''];
        }
        $names = $this->actions->profiles();
        foreach (array_filter([$ai->promptProfile, $ai->fallbackProfile, ...array_values($ai->profileMap)]) as $inUse) {
            if (!\in_array($inUse, $names, true)) {
                $names[] = $inUse;
            }
        }
        $profiles = [];
        $overview = [];
        foreach ($names as $profile) {
            $profiles[$profile] = true;
            // Phase 33e: what the profile holds, and where it serves
            // The target a profile is offered by default: radiology for the one serving reports, else generic
            $target = $ai->profileFor('reports') === $profile ? DefaultPrompts::RADIOLOGY : DefaultPrompts::GENERIC;
            $shipped = $this->defaults?->status($profile, $target) ?? [];
            $overview[$profile] = $this->actions->overview($profile) + [
                'shipped' => $shipped,
                'target' => $target,
                'shippedMissing' => \count(array_filter($shipped, static fn (string $state): bool => $state === 'missing')),
                'serves' => $profile === $ai->promptProfile ? 'main' : ($profile === $ai->fallbackProfile ? 'fallback' : (\in_array($profile, $ai->profileMap, true) ? 'mapped' : '')),
                'fallbackToo' => $profile === $ai->promptProfile && $profile === $ai->fallbackProfile,
            ];
        }

        // The routing table: namespace → profile, the map over the main profile's namespaces (AiConfig::profileFor())
        $routes = array_fill_keys($ai->namespaces, $ai->promptProfile);
        foreach ($ai->profileMap as $ns => $profile) {
            $routes[$ns] = $profile;
        }

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/admin-ai.php', [
            'ai' => $ai,
            'routes' => $routes,
            'servers' => $servers,
            'status' => $check ?? $this->check->run($ai, false),
            'checked' => $check !== null,
            'profiles' => $profiles,
            'overview' => $overview,
            // Phase 34e: the one embedding model of Similar reports
            'embedServer' => is_numeric($section['embed_server'] ?? null) ? (int) $section['embed_server'] : 0,
            'embedModel' => \is_string($section['embed_model'] ?? null) ? $section['embed_model'] : '',
            'embedCommonMin' => is_numeric($section['embed_common_min'] ?? null) ? (string) $section['embed_common_min'] : '',
            'embedMinScore' => is_numeric($section['embed_min_score'] ?? null) ? (string) $section['embed_min_score'] : '',
            // Phase 8: the speech model of the audio overview
            'speechServer' => is_numeric($section['speech_server'] ?? null) ? (int) $section['speech_server'] : 0,
            'speechModel' => \is_string($section['speech_model'] ?? null) ? $section['speech_model'] : '',
            'speechVoices' => \is_string($section['speech_voices'] ?? null) ? $section['speech_voices'] : '',
            // Phase 34b: the assistant's use, read from the audit
            'usage' => (new Usage($this->audit->directory()))->compute(new \DateTimeImmutable('now'), is_numeric($request->query['days'] ?? null) ? (int) $request->query['days'] : 30),
            'saved' => (string) ($request->query['saved'] ?? ''),
            'promptsMade' => $request->query['made'] ?? null,
            'promptsKept' => $request->query['kept'] ?? null,
            'error' => $error,
            'errorSection' => $errorSection,
            'adminTab' => 'ai',
            'basePath' => $request->basePath,
        ] + ChromeVars::shell($request, $principal, $this->index, ''), t('admin.ai.title')), $error === null ? 200 : 422);
    }

    public function save(Request $request, string $section, ?User $principal): Response
    {
        if ($principal?->isOwner !== true || !\in_array($section, ['use', 'similar', 'speech', 'server', 'enabled', 'servers'], true)) {
            throw new PageNotFoundException();
        }
        // The switch and the default server sit in the Assistant panel: its notice reports them
        $panel = \in_array($section, ['server', 'enabled'], true) ? 'use' : $section;
        parse_str($request->body, $fields);
        $changes = [];
        if ($section === 'use' || $section === 'similar' || $section === 'speech') {
            foreach ($section === 'use' ? self::ROUTES : ($section === 'similar' ? self::SIMILAR : self::SPEECH) as $key) {
                // parse_str turns dots into underscores; an unticked box is absent
                $changes[$key] = $fields[str_replace('.', '_', $key)] ?? '';
            }
            // The routing table's rows stand for the namespace, profile and map fields
            if ($section === 'use' && \is_array($fields['route_ns'] ?? null)) {
                try {
                    $changes = self::routeChanges($fields) + $changes;
                } catch (InvalidArgumentException $e) {
                    return $this->show($request, $principal, $e->getMessage(), $panel);
                }
            }
        } elseif ($section === 'enabled') {
            // The header's Enabled | Disabled: this one setting, whatever else the page's forms hold unsaved
            $changes['ai.enabled'] = $fields['ai_enabled'] ?? '';
        } elseif ($section === 'server') {
            // The server bar's Save: the default server alone
            $changes['ai.server'] = $fields['ai_server'] ?? '';
        } else {
            $changes['ai.servers'] = $fields['servers'] ?? [];
        }

        try {
            $changed = $this->settings->save($changes);
        } catch (InvalidArgumentException $e) {
            return $this->show($request, $principal, $e->getMessage(), $panel);
        }
        if ($changed !== []) {
            $this->audit->record('settings.change', $principal->username, $request, extra: ['section' => 'ai', 'keys' => $changed]);
        }

        return Response::redirect($request->basePath . '/admin/ai?saved=' . $panel . '#' . $panel);
    }

    /**
     * POST /admin/ai/prompts: make the shipped prompts a profile lacks (`profile`, a new name
     * allowed; `only[]` limits it to some ids). Never over a page that is there, so asking twice
     * is harmless. Audited settings.change with the ids made (never a page's text).
     */
    public function installPrompts(Request $request, ?User $principal): Response
    {
        if ($principal?->isOwner !== true || $this->defaults === null) {
            throw new PageNotFoundException();
        }
        parse_str($request->body, $fields);
        $profile = \is_string($fields['profile'] ?? null) ? trim($fields['profile']) : '';
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $profile) !== 1) {
            return $this->show($request, $principal, t('admin.ai.prompts_install_profile'), 'prompts');
        }
        $only = null;
        if (\is_array($fields['only'] ?? null)) {
            $only = array_values(array_filter($fields['only'], static fn (mixed $id): bool => \is_string($id) && isset(DefaultPrompts::CATALOGUE[$id])));
        }
        $target = \is_string($fields['target'] ?? null) && \in_array($fields['target'], DefaultPrompts::TARGETS, true) ? $fields['target'] : DefaultPrompts::DEFAULT_TARGET;
        $result = $this->defaults->install($profile, $principal->username, $only, target: $target);
        $this->audit->record('settings.change', $principal->username, $request, extra: [
            'section' => 'ai.prompts',
            'profile' => $profile,
            'target' => $target,
            'created' => implode(',', $result['created']),
        ]);

        return Response::redirect($request->basePath . '/admin/ai?made=' . \count($result['created']) . '&kept=' . \count($result['skipped']) . '#prompts');
    }

    /**
     * The routing table (rows `route_ns[]` + `route_profile[]`, and `route_else`) as the settings it
     * stands for: the profile most rows use (the first on a tie) is `ai.prompt_profile` and its rows
     * `ai.namespaces`; every other row is a `namespace = profile` line of `ai.profile_map`; the last
     * row is `ai.fallback_profile`. A blank namespace is an unused row; the same namespace twice
     * keeps the later one. Their values are checked by InstanceSettings, as ever.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, string>
     *
     * @throws InvalidArgumentException no row names a namespace
     */
    private static function routeChanges(array $fields): array
    {
        $names = \is_array($fields['route_ns'] ?? null) ? $fields['route_ns'] : [];
        $profiles = \is_array($fields['route_profile'] ?? null) ? $fields['route_profile'] : [];
        $rows = [];
        foreach ($names as $i => $ns) {
            $ns = \is_string($ns) ? trim(trim($ns), ':') : '';
            if ($ns !== '') {
                $rows[$ns] = \is_string($profiles[$i] ?? null) ? trim($profiles[$i]) : '';
            }
        }
        if ($rows === []) {
            throw new InvalidArgumentException(t('admin.ai.routes_none'));
        }
        $main = '';
        $most = 0;
        foreach (array_count_values($rows) as $profile => $count) {
            if ($count > $most) {
                $main = (string) $profile;
                $most = $count;
            }
        }
        $map = [];
        foreach ($rows as $ns => $profile) {
            if ($profile !== $main) {
                $map[] = $ns . ' = ' . $profile;
            }
        }

        return [
            'ai.prompt_profile' => $main,
            'ai.namespaces' => implode(', ', array_keys(array_filter($rows, static fn (string $p): bool => $p === $main))),
            'ai.profile_map' => implode("\n", $map),
            'ai.fallback_profile' => \is_string($fields['route_else'] ?? null) ? trim($fields['route_else']) : '',
        ];
    }

    public function check(Request $request, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }

        // The server chosen in the bar's select, saved or not; a missing or bad value is the saved default
        parse_str($request->body, $fields);
        $slot = \is_string($fields['ai_server'] ?? null) && ctype_digit($fields['ai_server']) && (int) $fields['ai_server'] >= 1 && (int) $fields['ai_server'] <= AiConfig::SLOTS ? (int) $fields['ai_server'] : null;

        $ai = AiConfig::fromConfig($this->config, $slot);

        // The card names the server that was checked, and the select keeps the choice
        return $this->show($request, $principal, null, null, $this->check->run($ai) + ['serverName' => $ai->serverName, 'serverSlot' => $ai->server]);
    }

    /**
     * POST /admin/ai/servers/{slot}/models: one server's models, through its
     * filter, from its saved settings (the key never comes back to a browser,
     * so an unsaved card has to be saved first): `{data, total, error}`
     * phase 33c. `?all=1` skips the filter: the Embedding model field's
     * list (phase 34e), since a filter is for chat models. Owner-only, 404
     * otherwise.
     */
    public function models(Request $request, string $slot, ?User $principal): Response
    {
        $ai = $this->server($slot, $principal);

        return ApiResponse::json($this->check->models($ai, ($request->query['all'] ?? '') === '1'));
    }

    /**
     * POST /admin/ai/servers/{slot}/test: each alias of one server: listed,
     * and one fixed one-line request with its parameters (no report text)
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
