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
 * GET /admin/integrations, POST /admin/plugins/{id}/toggle and POST
 * /admin/plugins/{id}/settings: Admin → Integrations, owner-only. First the
 * MCP server's settings (SettingsPanels, saved by POST /admin/settings/mcp),
 * then every plugin
 * directory found, whether it is enabled, loaded or failed, and a form for
 * its settings built from its manifest. Both writes go to
 * data/settings.yaml (`plugins`), are audited `settings.change`, and take
 * effect from the next request (the loader runs at boot). A `secret`
 * setting is never shown back; left blank, the stored one stays. GET
 * /admin/plugins, the plugins' old screen, redirects here.
 */
final class AdminIntegrationsController
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
        private readonly SettingsPanels $panels,
    ) {
    }

    /** GET /admin/plugins: the plugins' old screen */
    public function redirect(Request $request, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }

        return Response::redirect($request->basePath . '/admin/integrations', 301);
    }

    /** A refused save of a settings section shown here (mcp) */
    public function refused(Request $request, User $principal, string $error, string $section): Response
    {
        return $this->show($request, $principal, null, null, $error, $section);
    }

    public function show(Request $request, ?User $principal, ?string $error = null, ?string $errorPlugin = null, ?string $settingsError = null, ?string $section = null): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }
        $values = [];
        foreach ($this->plugins->manifests as $id => $manifest) {
            $values[$id] = $manifest->settingValues($this->stored($id));
        }

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/admin-integrations.php', [
            'manifests' => $this->plugins->manifests,
            'invalid' => $this->plugins->invalid,
            'enabled' => $this->plugins->enabled,
            'loaded' => $this->plugins->loaded,
            'failed' => $this->plugins->failed,
            'values' => $values,
            'sites' => $this->sites(),
            'saved' => isset($request->query['saved']) ? (string) $request->query['saved'] : null,
            'error' => $error,
            'errorPlugin' => $errorPlugin,
            // The MCP panel's own variables: kept apart, its $values and $saved are not the plugins'
            'settingsVars' => $this->panels->vars($request, $settingsError, $section) + ['basePath' => $request->basePath],
            'adminTab' => 'integrations',
            'basePath' => $request->basePath,
        ] + ChromeVars::shell($request, $principal, $this->index, ''), t('admin.integrations.title')), $error === null && $settingsError === null ? 200 : 422);
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
        try {
            $this->settings->savePluginsEnabled($enabled);
        } catch (InvalidArgumentException $e) {
            return $this->show($request, $principal, $e->getMessage(), $id);
        }
        $this->audit->record('settings.change', $principal->username, $request, extra: ['section' => 'plugins', 'keys' => ['plugins.enabled'], 'plugin' => $id]);

        return Response::redirect($request->basePath . '/admin/integrations?saved=' . rawurlencode($id));
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
                $raw = $fields[$key] ?? ($spec['type'] === 'bool' ? '0' : ($spec['type'] === 'sites' ? [] : ''));
                if ($spec['type'] === 'sites') {
                    // Rows for the configured sites only (Admin → Reports → Sites)
                    $raw = array_intersect_key(\is_array($raw) ? $raw : [], $this->sites());
                }
                if ($spec['type'] === 'secret' && $raw === '') {
                    // Blank keeps the stored secret: it is never sent back to the form
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
        try {
            $this->settings->savePluginSettings($id, $values);
        } catch (InvalidArgumentException $e) {
            return $this->show($request, $principal, $e->getMessage(), $id);
        }
        $this->audit->record('settings.change', $principal->username, $request, extra: ['section' => 'plugins', 'plugin' => $id, 'keys' => $changed]);

        return Response::redirect($request->basePath . '/admin/integrations?saved=' . rawurlencode($id) . '#plugin-' . rawurlencode($id));
    }

    /** @return array<string, string> site code → name, for `sites` settings */
    private function sites(): array
    {
        $sites = [];
        foreach (\is_array($this->config['sites'] ?? null) ? $this->config['sites'] : [] as $code => $site) {
            $name = \is_array($site) && \is_string($site['name'] ?? null) ? $site['name'] : '';
            $sites[(string) $code] = $name !== '' ? $name : (string) $code;
        }

        return $sites;
    }

    /** @return array<string, mixed> */
    private function stored(string $id): array
    {
        $all = $this->config['plugins']['settings'] ?? [];

        return \is_array($all) && \is_array($all[$id] ?? null) ? $all[$id] : [];
    }
}
