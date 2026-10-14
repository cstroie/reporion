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
use Reporion\Support\Devices;
use Reporion\Support\MetaText;

/**
 * Admin → Sites, owner-only: each site's letterhead on printed reports, its
 * accession code and its devices, kept in data/settings.yaml
 * (Service\InstanceSettings) like the rest of Admin → Settings, from which
 * this screen was split out. A card per site, each saved on its own.
 *
 * GET /admin/sites[?open=code]; POST /admin/sites adds a site (code, name);
 * POST /admin/sites/{code} saves one site; POST /admin/sites/{code}/remove
 * removes it (a site reports still name needs `confirm=1`). Each redirects
 * back (Post/Redirect/Get), or answers 422 with the message, the card that
 * caused it showing what was typed, and writes nothing. Audited
 * settings.change with section "sites" and the site's code.
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

    /**
     * @param ?string $errorSite the card the error belongs to: a site code, or '' for the Add a site bar
     * @param ?array<string, mixed> $draft what was posted there, shown back
     */
    public function show(Request $request, ?User $principal, ?string $error = null, ?string $errorSite = null, ?array $draft = null): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }
        $stored = $this->settings->load();
        $counts = $this->reportCounts($principal);

        $cards = [];
        foreach ($this->sites() as $code => $site) {
            $card = $errorSite === (string) $code && $draft !== null ? self::fromRow((string) $code, $draft) : self::fromSite((string) $code, $site);
            $card['reports'] = $counts[(string) $code] ?? 0;
            $cards[] = $card;
        }
        $saved = (string) ($request->query['saved'] ?? '');
        $open = $errorSite !== null && $errorSite !== '' ? $errorSite : (string) ($request->query['open'] ?? '');

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/admin-sites.php', [
            'cards' => $cards,
            'open' => $open !== '' ? $open : ($cards[0]['code'] ?? ''),
            'saved' => $saved,
            'fromFile' => ['sites' => \array_key_exists('sites', $stored)],
            'error' => $error,
            'errorSite' => $errorSite,
            'addDraft' => $errorSite === '' ? ($draft ?? []) : [],
            'adminTab' => 'sites',
            'basePath' => $request->basePath,
        ] + ChromeVars::shell($request, $principal, $this->index, ''), t('admin.sites.title')), $error === null ? 200 : 422);
    }

    public function add(Request $request, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }
        parse_str($request->body, $fields);
        $code = strtolower(trim((string) ($fields['code'] ?? '')));

        try {
            $this->settings->addSite($this->sites(), $code, (string) ($fields['name'] ?? ''));
        } catch (InvalidArgumentException $e) {
            return $this->show($request, $principal, $e->getMessage(), '', ['code' => $code, 'name' => (string) ($fields['name'] ?? '')]);
        }
        $this->audit->record('settings.change', $principal->username, $request, extra: ['section' => 'sites', 'site' => $code, 'keys' => ['sites']]);

        return $this->done($request, $code);
    }

    public function save(Request $request, string $code, ?User $principal): Response
    {
        $this->owned($code, $principal);
        parse_str($request->body, $fields);

        try {
            $changed = $this->settings->saveSite($this->sites(), $code, $fields);
        } catch (InvalidArgumentException $e) {
            return $this->show($request, $principal, $e->getMessage(), $code, $fields);
        }
        if ($changed) {
            $this->audit->record('settings.change', $principal->username, $request, extra: ['section' => 'sites', 'site' => $code, 'keys' => ['sites']]);
        }

        return $this->done($request, $code);
    }

    public function remove(Request $request, string $code, ?User $principal): Response
    {
        $this->owned($code, $principal);
        parse_str($request->body, $fields);
        $reports = $this->reportCounts($principal)[$code] ?? 0;
        if ($reports > 0 && ($fields['confirm'] ?? '') !== '1') {
            return $this->show($request, $principal, t('admin.sites.err.in_use', [$code, $reports]), $code);
        }

        try {
            $this->settings->removeSite($this->sites(), $code);
        } catch (InvalidArgumentException $e) {
            return $this->show($request, $principal, $e->getMessage(), $code);
        }
        $this->audit->record('settings.change', $principal->username, $request, extra: ['section' => 'sites', 'site' => $code, 'keys' => ['sites'], 'removed' => true]);

        return Response::redirect($request->basePath . '/admin/sites?saved=removed#sites');
    }

    private function done(Request $request, string $code): Response
    {
        return Response::redirect($request->basePath . '/admin/sites?saved=' . rawurlencode($code) . '&open=' . rawurlencode($code) . '#site-' . rawurlencode($code));
    }

    /** The owner, and a site that exists: 404 otherwise */
    private function owned(string $code, ?User $principal): void
    {
        if ($principal?->isOwner !== true || !isset($this->sites()[$code])) {
            throw new PageNotFoundException();
        }
    }

    /** @return array<string, array<string, mixed>> the effective sites, code → fields */
    private function sites(): array
    {
        $sites = [];
        foreach (\is_array($this->config['sites'] ?? null) ? $this->config['sites'] : [] as $code => $site) {
            $sites[(string) $code] = \is_array($site) ? $site : [];
        }

        return $sites;
    }

    /**
     * How many reports name each site, as the owner sees them (invariant 6 applies)
     *
     * @return array<string, int>
     */
    private function reportCounts(?User $principal): array
    {
        return $this->index->siteCounts($principal);
    }

    /**
     * A card from what is stored
     *
     * @param array<string, mixed> $site
     *
     * @return array<string, mixed>
     */
    private static function fromSite(string $code, array $site): array
    {
        $devices = [];
        foreach (Devices::entries($site) as $deviceCode => $entry) {
            $devices[] = ['code' => (string) $deviceCode, 'name' => $entry['name'], 'pacs' => $entry['pacs'], 'inst' => $entry['inst'], 'link_new' => '', 'remove' => false];
        }

        return ['code' => $code] + array_combine(InstanceSettings::SITE_FIELDS, array_map(static fn (string $f): string => MetaText::text($site[$f] ?? null), InstanceSettings::SITE_FIELDS)) + ['devices' => $devices];
    }

    /**
     * A card from what was posted: the fields as typed, the chips still ticked
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function fromRow(string $code, array $row): array
    {
        $devices = [];
        foreach (\is_array($row['devices'] ?? null) ? $row['devices'] : [] as $device) {
            if (!\is_array($device)) {
                continue;
            }
            $list = static fn (mixed $v): array => array_values(array_filter(array_map(static fn (mixed $k): string => \is_scalar($k) ? (string) $k : '', \is_array($v) ? $v : []), static fn (string $k): bool => $k !== ''));
            $devices[] = [
                'code' => MetaText::text($device['code'] ?? null),
                'name' => MetaText::text($device['name'] ?? null),
                'pacs' => $list($device['pacs'] ?? null),
                'inst' => $list($device['inst'] ?? null),
                'link_new' => MetaText::text($device['link_new'] ?? null),
                'remove' => ($device['remove'] ?? '') === '1',
            ];
        }

        return ['code' => $code] + array_combine(InstanceSettings::SITE_FIELDS, array_map(static fn (string $f): string => MetaText::text($row[$f] ?? null), InstanceSettings::SITE_FIELDS)) + ['devices' => $devices];
    }
}
