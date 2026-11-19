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
use Reporion\Service\InstanceSettings;

/**
 * Admin → General, owner-only (decided 2026-09-26): this instance's
 * site, editor, publishing, limits and debug settings, kept in
 * data/settings.yaml (Service\InstanceSettings), not in conf/local.php. The
 * other sections' forms are on the tab that owns their topic
 * (SettingsPanels::SECTION_TAB: Reports, Library, Data, Integrations).
 *
 * GET /admin/settings; POST /admin/settings/{section} saves one section
 * and redirects back to its tab (Post/Redirect/Get); POST /admin/settings/icon takes
 * the icon image as the request body. Each save is audited
 * settings.change with the keys that changed. GET /site-icon/{file} and
 * /favicon.ico serve the icon to everyone.
 */
final class AdminSettingsController
{
    /** Which settings each form saves (SettingsPanels::SECTIONS) */
    public const SECTIONS = SettingsPanels::SECTIONS;

    /**
     * @param array<string, \Closure(Request, User, string, string): Response> $tabs a tab key => the screen
     *        that shows a section's form, for a refused save (SettingsPanels::SECTION_TAB); General is this one
     */
    public function __construct(
        private readonly InstanceSettings $settings,
        private readonly SettingsPanels $panels,
        private readonly IndexInterface $index,
        private readonly AuditLog $audit,
        private readonly array $tabs = [],
    ) {
    }

    /** Admin → General: the site, editor, publishing, limits and debug panels */
    public function show(Request $request, ?User $principal, ?string $error = null, ?string $errorSection = null): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/admin-settings.php', [
            'adminTab' => 'general',
            'basePath' => $request->basePath,
        ] + $this->panels->vars($request, $error, $errorSection)
          + ChromeVars::shell($request, $principal, $this->index, ''), t('admin.general.title')), $error === null ? 200 : 422);
    }

    /** A refused save, shown on the tab that holds its form */
    private function refused(Request $request, User $principal, string $section, string $error): Response
    {
        $tab = SettingsPanels::SECTION_TAB[$section] ?? 'general';

        return isset($this->tabs[$tab]) ? ($this->tabs[$tab])($request, $principal, $error, $section) : $this->show($request, $principal, $error, $section);
    }

    public function save(Request $request, string $section, ?User $principal): Response
    {
        if ($principal?->isOwner !== true || !isset(self::SECTIONS[$section])) {
            throw new PageNotFoundException();
        }
        parse_str($request->body, $fields);
        $changes = [];
        foreach (self::SECTIONS[$section] as $key) {
            // parse_str turns dots into underscores; an unticked box is absent
            $changes[$key] = $fields[str_replace('.', '_', $key)] ?? '';
        }
        if ($section === 'site' && ($fields['remove_icon'] ?? '') === '1') {
            $changes['site.icon'] = '';
        }

        // The schedule cannot be switched on before it has a target (D49)
        if ($section === 'backup' && ($fields['backup_enabled'] ?? '') !== '') {
            $needed = ($fields['backup_transport'] ?? 'ssh') === 'daemon' ? ['backup_host', 'backup_module'] : ['backup_host', 'backup_user', 'backup_remote_dir'];
            foreach ($needed as $field) {
                if (trim((string) ($fields[$field] ?? '')) === '') {
                    return $this->refused($request, $principal, $section, t('admin.settings.err.backup.incomplete'));
                }
            }
        }

        try {
            $changed = $this->settings->save($changes);
        } catch (InvalidArgumentException $e) {
            return $this->refused($request, $principal, $section, $e->getMessage());
        }
        if ($changed !== []) {
            $this->audit->record('settings.change', $principal->username, $request, extra: ['section' => $section, 'keys' => $changed]);
        }

        [$route, $anchor] = explode('#', SettingsPanels::home($section), 2);

        return Response::redirect($request->basePath . $route . '?saved=' . $section . '#' . $anchor);
    }

    public function uploadIcon(Request $request, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        if ($request->body === '' || \strlen($request->body) > 512 * 1024) {
            return ApiResponse::error(422, 'invalid_icon', t('admin.settings.err_icon'));
        }
        try {
            $this->settings->saveIcon($request->body);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error(422, 'invalid_icon', $e->getMessage());
        }
        $this->audit->record('settings.change', $principal->username, $request, extra: ['section' => 'site', 'keys' => ['site.icon']]);

        return ApiResponse::json(['ok' => true]);
    }

    /** GET /site-icon/{file} (versioned: cached for a year) and /favicon.ico */
    public function icon(Request $request, bool $versioned): Response
    {
        $icon = $this->settings->icon();
        $bytes = $icon !== null ? file_get_contents($icon['file']) : false;
        if ($icon === null || $bytes === false) {
            return new Response(404, 'Not found', ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return new Response(200, $bytes, [
            'Content-Type' => $icon['type'],
            'Cache-Control' => $versioned ? 'public, max-age=31536000, immutable' : 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
