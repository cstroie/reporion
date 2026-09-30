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
use Reporion\Service\InstanceSettings;

/**
 * Admin → Sites, owner-only: each site's letterhead on printed reports and
 * its devices, kept in data/settings.yaml (Service\InstanceSettings) like the
 * rest of Admin → Settings, from which this screen was split out.
 *
 * GET /admin/sites; POST /admin/sites saves the table and redirects back
 * (Post/Redirect/Get), or answers 422 with the message and writes nothing.
 * Audited settings.change with section "sites".
 */
final class AdminSitesController
{
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

    public function show(Request $request, ?User $principal, ?string $error = null): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }
        $stored = $this->settings->load();

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/admin-sites.php', [
            'values' => InstanceSettings::current($this->config),
            'fromFile' => ['sites' => \array_key_exists('sites', $stored)],
            'saved' => ($request->query['saved'] ?? '') === '1',
            'error' => $error,
            'adminTab' => 'sites',
            'basePath' => $request->basePath,
        ] + ChromeVars::shell($request, $principal, $this->index, ''), t('admin.sites.title')), $error === null ? 200 : 422);
    }

    public function save(Request $request, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }
        parse_str($request->body, $fields);

        try {
            $changed = $this->settings->save(['sites' => $fields['sites'] ?? '']);
        } catch (InvalidArgumentException $e) {
            return $this->show($request, $principal, $e->getMessage());
        }
        if ($changed !== []) {
            $this->audit->record('settings.change', $principal->username, $request, extra: ['section' => 'sites', 'keys' => $changed]);
        }

        return Response::redirect($request->basePath . '/admin/sites?saved=1');
    }
}
