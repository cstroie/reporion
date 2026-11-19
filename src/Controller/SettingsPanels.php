<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Http\Request;
use Reporion\Service\Backup\RsyncBackup;
use Reporion\Service\InstanceSettings;
use Reporion\Support\AdminTabs;

/**
 * The settings of data/settings.yaml (Service\InstanceSettings) as panels
 * the admin tabs share: each section's keys, the tab that shows its form
 * (templates/partials/settings/{section}.php) and the variables those
 * forms need. Saving stays one route, POST /admin/settings/{section}
 * (AdminSettingsController), which sends the owner back to that tab.
 */
final class SettingsPanels
{
    /** Which settings each form saves */
    public const SECTIONS = [
        'site' => ['site.title', 'site.tagline', 'site.base_url', 'site.home_page', 'site.timezone'],
        'publishing' => ['feeds.namespaces', 'export.allow_public_export', 'export.pseudonymise_public', 'export.allow_draft_export'],
        'mcp' => ['mcp.access', 'mcp.write_anywhere', 'mcp.reports', 'mcp.gate'],
        'limits' => ['media.max_bytes'],
        'trash' => ['pages.trash_purge_days'],
        'editor' => ['editor.save_stays_open'],
        'library' => ['library.lookup', 'library.web_clip', 'library.search', 'library.unpaywall_email', 'library.openalex_key', 'library.context_tokens', 'library.language'],
        'debug' => ['debug.panels'],
        'reports' => ['reports.modality_namespaces', 'references.namespaces'],
        'backup' => ['backup.enabled', 'backup.transport', 'backup.module', 'backup.password_file', 'backup.host', 'backup.port', 'backup.user', 'backup.remote_dir', 'backup.ssh_key', 'backup.bwlimit', 'backup.include_conf', 'backup.keep', 'backup.interval_hours'],
    ];

    /** Section => the admin tab whose screen shows its form */
    public const SECTION_TAB = [
        'site' => 'general',
        'editor' => 'general',
        'publishing' => 'general',
        'limits' => 'general',
        'debug' => 'general',
        'reports' => 'reports',
        'library' => 'library',
        'trash' => 'data',
        'backup' => 'data',
        'mcp' => 'integrations',
    ];

    /**
     * @param array<string, mixed> $config the effective config (settings already applied)
     */
    public function __construct(
        private readonly InstanceSettings $settings,
        private readonly array $config,
    ) {
    }

    /** Where a section's form is shown, with its anchor */
    public static function home(string $section): string
    {
        return AdminTabs::route(self::SECTION_TAB[$section] ?? 'general') . '#' . $section;
    }

    /**
     * What partials/settings/helpers.php and the panels read.
     *
     * @return array<string, mixed>
     */
    public function vars(Request $request, ?string $error = null, ?string $errorSection = null): array
    {
        $stored = $this->settings->load();
        $fromFile = [];
        foreach (self::SECTIONS as $keys) {
            foreach ($keys as $key) {
                [$section, $field] = explode('.', $key . '.', 2);
                $field = rtrim($field, '.');
                $fromFile[$key] = $field === '' ? \array_key_exists($section, $stored) : \is_array($stored[$section] ?? null) && \array_key_exists($field, $stored[$section]);
            }
        }

        return [
            'values' => InstanceSettings::current($this->config),
            'fromFile' => $fromFile,
            'hasFile' => $stored !== [],
            'backupState' => RsyncBackup::state((string) ($this->config['paths']['data'] ?? '')),
            // A fresh suggestion for the X-Access-Token value at every load: long, random, URL-safe
            'gateSuggestion' => rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '='),
            'saved' => (string) ($request->query['saved'] ?? ''),
            'settingsError' => $error,
            'errorSection' => $errorSection,
            'mcpUrl' => $this->mcpUrl($request),
        ];
    }

    /** The address a client is given for the MCP server: site.base_url when set, else the one this page came by */
    private function mcpUrl(Request $request): string
    {
        $base = rtrim((string) (InstanceSettings::current($this->config)['site.base_url'] ?? ''), '/');
        if ($base === '' && preg_match('/^[A-Za-z0-9.-]+(:[0-9]{1,5})?$|^\[[0-9A-Fa-f:]+\](:[0-9]{1,5})?$/', $request->host) === 1) {
            $base = ($request->secure ? 'https' : 'http') . '://' . $request->host . $request->basePath;
        }

        return $base === '' ? '' : $base . '/mcp';
    }
}
