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
 * Admin → Settings, owner-only (decided 2026-09-26): this instance's
 * settings — identity, publishing, limits, sites and devices — kept in
 * data/settings.yaml (Service\InstanceSettings), not in conf/local.php.
 *
 * GET /admin/settings; POST /admin/settings/{section} saves one section
 * and redirects back (Post/Redirect/Get); POST /admin/settings/icon takes
 * the icon image as the request body. Each save is audited
 * settings.change with the keys that changed. GET /site-icon/{file} and
 * /favicon.ico serve the icon to everyone.
 */
final class AdminSettingsController
{
    /** Which settings each form saves */
    public const SECTIONS = [
        'site' => ['site.title', 'site.tagline', 'site.base_url', 'site.home_page', 'site.timezone'],
        'publishing' => ['feeds.namespaces', 'export.allow_public_export', 'export.pseudonymise_public', 'export.allow_draft_export'],
        'limits' => ['pages.trash_purge_days', 'media.max_bytes'],
        'sites' => ['sites'],
    ];

    /**
     * @param array<string, mixed> $config the effective config (settings already applied)
     */
    public function __construct(
        private readonly InstanceSettings $settings,
        private readonly array $config,
        private readonly IndexInterface $index,
        private readonly AuditLog $audit,
    ) {
    }

    public function show(Request $request, ?User $principal, ?string $error = null, ?string $errorSection = null): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }
        $stored = $this->settings->load();
        $fromFile = [];
        foreach (self::SECTIONS as $keys) {
            foreach ($keys as $key) {
                [$section, $field] = explode('.', $key . '.', 2);
                $field = rtrim($field, '.');
                $fromFile[$key] = $field === '' ? \array_key_exists($section, $stored) : \is_array($stored[$section] ?? null) && \array_key_exists($field, $stored[$section]);
            }
        }

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/admin-settings.php', [
            'values' => InstanceSettings::current($this->config),
            'fromFile' => $fromFile,
            'hasFile' => $stored !== [],
            'saved' => (string) ($request->query['saved'] ?? ''),
            'error' => $error,
            'errorSection' => $errorSection,
            'adminTab' => 'settings',
            'basePath' => $request->basePath,
        ] + ChromeVars::shell($request, $principal, $this->index, ''), t('admin.settings.title')), $error === null ? 200 : 422);
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

        try {
            $changed = $this->settings->save($changes);
        } catch (InvalidArgumentException $e) {
            return $this->show($request, $principal, $e->getMessage(), $section);
        }
        if ($changed !== []) {
            $this->audit->record('settings.change', $principal->username, $request, extra: ['section' => $section, 'keys' => $changed]);
        }

        return Response::redirect($request->basePath . '/admin/settings?saved=' . $section . '#' . $section);
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
