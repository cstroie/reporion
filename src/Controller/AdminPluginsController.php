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
use Reporion\Plugin\Manifest;
use Reporion\Plugin\Registry;
use Reporion\Service\InstanceSettings;

/**
 * GET /admin/plugins, POST /admin/plugins/{id}/toggle and POST
 * /admin/plugins/{id}/settings — Admin → Plugins, owner-only. Every plugin
 * directory found, whether it is enabled, loaded or failed, and a form for
 * its settings built from its manifest. Both writes go to
 * data/settings.yaml (`plugins`), are audited `settings.change`, and take
 * effect from the next request (the loader runs at boot). A `secret`
 * setting is never shown back; left blank, the stored one stays.
 */
final class AdminPluginsController
{
    /**
     * @param array<string, mixed> $config the effective config (after InstanceSettings::applyTo())
     */
    public function __construct(
        private readonly InstanceSettings $settings,
        private readonly array $config,
        private readonly Registry $plugins,
        private readonly IndexInterface $index,
        private readonly AuditLog $audit,
    ) {
    }

    public function show(Request $request, ?User $principal, ?string $error = null, ?string $errorPlugin = null): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }
        $values = [];
        foreach ($this->plugins->manifests as $id => $manifest) {
            $values[$id] = $manifest->settingValues($this->stored($id));
        }

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/admin-plugins.php', [
            'manifests' => $this->plugins->manifests,
            'invalid' => $this->plugins->invalid,
            'enabled' => $this->plugins->enabled,
            'loaded' => $this->plugins->loaded,
            'failed' => $this->plugins->failed,
            'values' => $values,
            'saved' => isset($request->query['saved']) ? (string) $request->query['saved'] : null,
            'error' => $error,
            'errorPlugin' => $errorPlugin,
            'adminTab' => 'plugins',
            'basePath' => $request->basePath,
        ] + ChromeVars::shell($request, $principal, $this->index, ''), t('admin.plugins.title')), $error === null ? 200 : 422);
    }

    public function toggle(Request $request, string $id, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }
        if (!isset($this->plugins->manifests[$id])) {
            throw new PageNotFoundException();
        }
        parse_str($request->body, $fields);
        // The state asked for, not a flip: a second, stale tab cannot invert it
        $on = ($fields['enabled'] ?? null) === '1';
        $enabled = array_values(array_diff(array_filter((array) ($this->config['plugins']['enabled'] ?? []), 'is_string'), [$id]));
        if ($on) {
            $enabled[] = $id;
        }
        $this->settings->savePluginsEnabled($enabled);
        $this->audit->record('settings.change', $principal->username, $request, extra: ['section' => 'plugins', 'keys' => ['plugins.enabled'], 'plugin' => $id]);

        return Response::redirect($request->basePath . '/admin/plugins?saved=' . rawurlencode($id));
    }

    public function save(Request $request, string $id, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }
        $manifest = $this->plugins->manifests[$id] ?? throw new PageNotFoundException();
        parse_str($request->body, $fields);
        $stored = $this->stored($id);
        $values = [];
        try {
            foreach ($manifest->settings as $key => $spec) {
                $raw = $fields[$key] ?? ($spec['type'] === 'bool' ? '0' : '');
                if ($spec['type'] === 'secret' && $raw === '') {
                    // Blank keeps the stored secret — it is never sent back to the form
                    if (\array_key_exists($key, $stored)) {
                        $values[$key] = $stored[$key];
                    }
                    continue;
                }
                $values[$key] = Manifest::valid($spec, $raw);
            }
        } catch (InvalidArgumentException) {
            return $this->show($request, $principal, t('admin.plugins.err_value', [(string) ($key ?? '')]), $id);
        }
        $changed = array_keys(array_filter($values, static fn (mixed $v, string $k): bool => ($stored[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
        $this->settings->savePluginSettings($id, $values);
        $this->audit->record('settings.change', $principal->username, $request, extra: ['section' => 'plugins', 'plugin' => $id, 'keys' => $changed]);

        return Response::redirect($request->basePath . '/admin/plugins?saved=' . rawurlencode($id) . '#plugin-' . rawurlencode($id));
    }

    /** @return array<string, mixed> */
    private function stored(string $id): array
    {
        $all = $this->config['plugins']['settings'] ?? [];

        return \is_array($all) && \is_array($all[$id] ?? null) ? $all[$id] : [];
    }
}
