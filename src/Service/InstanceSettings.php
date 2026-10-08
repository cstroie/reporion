<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use DateTimeZone;
use InvalidArgumentException;
use Reporion\Service\Ai\AiConfig;
use Reporion\Service\Ai\TierSettings;
use Reporion\Storage\AtomicWriter;
use Reporion\Support\Devices;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * This instance's settings — what an owner tunes from Admin → Settings,
 * kept in data/settings.yaml (decided 2026-09-26): on disk with the data it
 * describes, backed up with it, human-readable, never PHP. conf/local.php
 * keeps only what is needed before data/ can be found (paths) and secrets
 * (the session key); any setting it still carries is the fallback until
 * the first save from Admin → Settings.
 *
 * The file mirrors the config keys the code already reads (site.*, sites,
 * feeds, export, pages, media), so applyTo() overlays it onto the config
 * and nothing downstream changes.
 */
final class InstanceSettings
{
    public const FILE = 'settings.yaml';

    /** The scalar settings: dotted key → type */
    public const FIELDS = [
        'site.title' => 'text',
        'site.tagline' => 'text',
        'site.base_url' => 'url',
        'site.home_page' => 'path',
        'site.timezone' => 'timezone',
        'site.icon' => 'icon',
        'feeds.namespaces' => 'namespaces',
        'export.allow_draft_export' => 'bool',
        'export.allow_public_export' => 'bool',
        'export.pseudonymise_public' => 'bool',
        'pages.trash_purge_days' => 'days',
        'media.max_bytes' => 'bytes',
        'reports.modality_namespaces' => 'modality_map',
        // Where a template's References picker looks (phase 25; default radiology)
        'references.namespaces' => 'namespaces',
        // The AI assistant (phase 15), edited in Admin → AI: whether it is
        // on, which of the servers (`ai.servers`, below) is in use, and the
        // prompt profile in use with the namespaces it serves. Servers carry
        // their API keys (the owner's choice, 2026-09-27): never shown back,
        // and the file is 0640
        'ai.enabled' => 'bool',
        'ai.server' => 'slot',
        'ai.prompt_profile' => 'profile_name',
        'ai.namespaces' => 'namespaces',
        // The profile for every other page; blank: no Assistant there (2026-10-08)
        'ai.fallback_profile' => 'profile_name_or_none',
    ];

    /** Fields of each entry under `sites` (letterhead and devices, per site code) */
    public const SITE_FIELDS = ['name', 'dept', 'address', 'phone', 'accession_code'];

    public function __construct(private readonly string $dataRoot)
    {
    }

    /**
     * What data/settings.yaml holds — empty when there is none yet or it
     * does not parse (the config fallbacks then apply; never a broken site).
     *
     * @return array<string, mixed>
     */
    public function load(): array
    {
        $file = $this->file();
        if (!is_file($file)) {
            return [];
        }
        try {
            $data = Yaml::parse((string) file_get_contents($file));
        } catch (ParseException) {
            return [];
        }

        return \is_array($data) ? $data : [];
    }

    /**
     * $config with the stored settings laid over it: each known field, and
     * `sites` as a whole.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public function applyTo(array $config): array
    {
        $settings = $this->load();
        foreach (array_keys(self::FIELDS) as $key) {
            [$section, $field] = explode('.', $key, 2);
            if (\is_array($settings[$section] ?? null) && \array_key_exists($field, $settings[$section])) {
                $config[$section][$field] = $settings[$section][$field];
            }
        }
        if (\is_array($settings['sites'] ?? null)) {
            $config['sites'] = $settings['sites'];
        }
        $config['ai'] = \is_array($config['ai'] ?? null) ? $config['ai'] : [];
        $stored = \is_array($settings['ai'] ?? null) ? $settings['ai'] : [];
        if (\is_array($stored['servers'] ?? null)) {
            $config['ai']['servers'] = $stored['servers'];
        } else {
            // Before the server slots: the flat keys, read as server 1 (AiConfig)
            foreach (array_intersect_key($stored, array_flip(AiConfig::SERVER_FIELDS)) as $field => $value) {
                $config['ai'][$field] = $value;
            }
        }
        if (\is_array($stored['profiles'] ?? null) && !isset($stored['prompt_profile'])) {
            $config['ai']['profiles'] = $stored['profiles'];
        }
        // Plugins (Admin → Plugins): which are enabled, and each one's settings
        $plugins = \is_array($settings['plugins'] ?? null) ? $settings['plugins'] : [];
        if (\is_array($plugins['enabled'] ?? null)) {
            $config['plugins']['enabled'] = array_values(array_filter($plugins['enabled'], 'is_string'));
        }
        if (\is_array($plugins['settings'] ?? null)) {
            $config['plugins']['settings'] = $plugins['settings'];
        }

        return $config;
    }

    /**
     * Which plugins are enabled (Admin → Plugins). Ids only; the loader
     * skips one that is not installed.
     *
     * @param list<string> $ids
     */
    public function savePluginsEnabled(array $ids): void
    {
        foreach ($ids as $id) {
            if (!\is_string($id) || preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $id) !== 1) {
                throw new InvalidArgumentException('Invalid plugin id');
            }
        }
        $settings = $this->load();
        $settings['plugins']['enabled'] = array_values(array_unique($ids));
        $this->write($settings);
    }

    /**
     * One plugin's settings, already validated against its manifest
     * (Plugin\Manifest::valid()). They may hold a secret — the file is 0640.
     *
     * @param array<string, mixed> $values
     */
    public function savePluginSettings(string $id, array $values): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $id) !== 1) {
            throw new InvalidArgumentException('Invalid plugin id');
        }
        $settings = $this->load();
        $settings['plugins']['settings'][$id] = $values;
        $this->write($settings);
    }

    /**
     * The value every field has now — stored, else the config fallback —
     * for the admin form.
     *
     * @param array<string, mixed> $config the effective config (after applyTo())
     *
     * @return array<string, mixed>
     */
    public static function current(array $config): array
    {
        $values = [];
        foreach (array_keys(self::FIELDS) as $key) {
            [$section, $field] = explode('.', $key, 2);
            $values[$key] = $config[$section][$field] ?? null;
        }
        $values['sites'] = \is_array($config['sites'] ?? null) ? $config['sites'] : [];

        return $values;
    }

    /**
     * Validates $changes (dotted key → raw value, and/or `sites` → list of
     * rows) and writes them over what is stored. Unknown keys are refused.
     *
     * @param array<string, mixed> $changes
     *
     * @return list<string> the keys whose value changed
     *
     * @throws InvalidArgumentException with a message for the form
     */
    public function save(array $changes): array
    {
        $settings = $this->load();
        $changed = [];
        foreach ($changes as $key => $raw) {
            if ($key === 'ai.servers') {
                $ai = \is_array($settings['ai'] ?? null) ? $settings['ai'] : [];
                $value = self::validServers($raw, AiConfig::servers($ai));
                if (($ai['servers'] ?? null) !== $value) {
                    $changed[] = 'ai.servers';
                }
                // The flat keys of before now live in server 1
                foreach (AiConfig::SERVER_FIELDS as $field) {
                    unset($settings['ai'][$field]);
                }
                $settings['ai']['servers'] = $value;
                continue;
            }
            if ($key === 'sites') {
                $value = self::validSites($raw);
                if (($settings['sites'] ?? null) !== $value) {
                    $changed[] = 'sites';
                }
                $settings['sites'] = $value;
                continue;
            }
            $type = self::FIELDS[$key] ?? throw new InvalidArgumentException('Unknown setting');
            $value = self::valid($key, $type, $raw);
            [$section, $field] = explode('.', $key, 2);
            if (!\is_array($settings[$section] ?? null) || !\array_key_exists($field, $settings[$section]) || $settings[$section][$field] !== $value) {
                $changed[] = $key;
            }
            $settings[$section][$field] = $value;
        }

        if (($changes['site.icon'] ?? null) === '') {
            foreach (glob($this->dataRoot . '/site/icon.*') ?: [] as $old) {
                @unlink($old);
            }
        }
        // Gone (2026-09-27): the AI host allow-list (the acknowledgement
        // decides) and the namespace → profile map (one profile in use)
        foreach (['allow_egress_to', 'profiles'] as $old) {
            if (isset($settings['ai'][$old]) && ($old !== 'profiles' || isset($settings['ai']['prompt_profile']))) {
                unset($settings['ai'][$old]);
                $changed[] = 'ai.' . $old;
            }
        }
        if ($changed !== []) {
            $this->write($settings);
        }

        return $changed;
    }

    /**
     * Links a PACS scanner name (the `pacs_device` of a linked study) to a
     * device of $site: a new device ($create, $code free, $name given) or an
     * existing one. One scanner name is one device: it leaves any other
     * device of the site it was linked to.
     *
     * @param array<string, mixed> $effectiveSites the config's `sites` (after applyTo()), the
     *                                             starting point while the file holds none
     *
     * @throws InvalidArgumentException with a message for the form
     */
    public function linkPacsDevice(array $effectiveSites, string $site, string $code, string $name, string $pacsName, bool $create): void
    {
        $settings = $this->load();
        $sites = \is_array($settings['sites'] ?? null) ? $settings['sites'] : $effectiveSites;
        if (!\is_array($sites[$site] ?? null)) {
            throw new InvalidArgumentException(t('admin.settings.err.site_code', [$site]));
        }
        $key = Devices::key($pacsName);
        $code = trim($code);
        $name = mb_substr(trim((string) preg_replace('/[\s|]+/u', ' ', $name)), 0, 200);
        if ($key === '') {
            throw new InvalidArgumentException(t('devices.err.no_scanner'));
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,31}$/', $code) !== 1) {
            throw new InvalidArgumentException(t('admin.settings.err.device', [$code]));
        }
        $entries = Devices::entries($sites[$site]);
        // A code is one device whatever its case: an existing one is named as it is stored
        foreach (array_keys($entries) as $known) {
            if (strcasecmp((string) $known, $code) === 0) {
                $code = (string) $known;
            }
        }
        if ($create && (isset($entries[$code]) || $name === '')) {
            throw new InvalidArgumentException(t($name === '' ? 'devices.err.no_name' : 'devices.err.taken', [$code]));
        }
        if (!$create && !isset($entries[$code])) {
            throw new InvalidArgumentException(t('admin.settings.err.device', [$code]));
        }
        foreach ($entries as $other => $entry) {
            $entries[$other]['pacs'] = array_values(array_filter($entry['pacs'], static fn (string $k): bool => mb_strtolower($k) !== mb_strtolower($key)));
        }
        $entries[$code] ??= ['name' => $name, 'pacs' => []];
        $entries[$code]['pacs'][] = $key;
        $sites[$site]['devices'] = array_map(Devices::dump(...), $entries);
        $settings['sites'] = $sites;
        $this->write($settings);
    }

    /**
     * Stores an uploaded site icon (PNG, ICO, GIF or WebP — no SVG, which
     * can carry script) as data/site/icon.{ext} and records it.
     *
     * @throws InvalidArgumentException for anything else
     */
    public function saveIcon(string $bytes): string
    {
        $ext = self::iconType($bytes) ?? throw new InvalidArgumentException(t('admin.settings.err_icon'));
        $dir = $this->dataRoot . '/site';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create the site directory');
        }
        foreach (glob($dir . '/icon.*') ?: [] as $old) {
            @unlink($old);
        }
        $name = 'icon.' . $ext;
        AtomicWriter::put($dir . '/' . $name, $bytes);
        $settings = $this->load();
        $settings['site']['icon'] = $name . '?v=' . substr(hash('sha256', $bytes), 0, 10);
        $this->write($settings);

        return $name;
    }

    /**
     * The stored icon file and its content type, or null.
     *
     * @return ?array{file: string, type: string}
     */
    public function icon(): ?array
    {
        $name = (string) ($this->load()['site']['icon'] ?? '');
        $name = (string) strtok($name, '?');
        if (preg_match('/^icon\.(png|ico|gif|webp)$/', $name, $m) !== 1 || !is_file($this->dataRoot . '/site/' . $name)) {
            return null;
        }
        $types = ['png' => 'image/png', 'ico' => 'image/x-icon', 'gif' => 'image/gif', 'webp' => 'image/webp'];

        return ['file' => $this->dataRoot . '/site/' . $name, 'type' => $types[$m[1]]];
    }

    private static function iconType(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\x00\x00\x01\x00")) {
            return 'ico';
        }
        $info = @getimagesizefromstring($bytes);

        return \is_array($info) ? ([IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'][$info[2]] ?? null) : null;
    }

    private static function valid(string $key, string $type, mixed $raw): mixed
    {
        $text = \is_string($raw) ? trim($raw) : '';
        $fail = static function () use ($key): never {
            throw new InvalidArgumentException(t('admin.settings.err.' . $key));
        };

        return match ($type) {
            'text' => $key === 'site.title' && ($text === '' || mb_strlen($text) > 80) ? $fail() : mb_substr($text, 0, 240),
            'url' => $text === '' || preg_match('~^https?://[^\s/?#]+(/[^\s?#]*)?$~i', $text) === 1 ? rtrim($text, '/') : $fail(),
            'path' => preg_match('/^[a-z0-9][a-z0-9_.-]*(:[a-z0-9][a-z0-9_.-]*)*$/', $text) === 1 ? $text : $fail(),
            'timezone' => self::isTimezone($text) ? $text : $fail(),
            'bool' => \in_array($raw, [true, '1', 'on', 'yes'], true),
            'days' => ctype_digit($text) && (int) $text >= 1 && (int) $text <= 3650 ? (int) $text : $fail(),
            'bytes' => ctype_digit($text) && (int) $text >= 1 && (int) $text <= 512 ? (int) $text * 1024 * 1024 : $fail(),
            'namespaces' => self::validNamespaces($raw, $fail),
            'modality_map' => self::validModalityMap($raw, $fail),
            'model' => $text === '' || preg_match('~^[A-Za-z0-9][A-Za-z0-9._:/@+-]{0,159}$~', $text) === 1 ? $text : $fail(),
            'temperature' => is_numeric($text) && (float) $text >= 0 && (float) $text <= 2 ? (float) $text : $fail(),
            'unit' => is_numeric($text) && (float) $text >= 0 && (float) $text <= 1 ? (float) $text : $fail(),
            'tokens' => ctype_digit($text) && (int) $text >= 0 && (int) $text <= 200000 ? (int) $text : $fail(),
            'top_k' => ctype_digit($text) && (int) $text >= 1 && (int) $text <= 1000 ? (int) $text : $fail(),
            'extra_json' => self::validExtra($text, $fail),
            'model_filter' => $text === '' || (mb_strlen($text) <= 200 && AiConfig::filterPattern($text) !== null) ? $text : $fail(),
            'seconds' => ctype_digit($text) && (int) $text >= 5 && (int) $text <= 600 ? (int) $text : $fail(),
            'slot' => ctype_digit($text) && (int) $text >= 1 && (int) $text <= AiConfig::SLOTS ? (int) $text : $fail(),
            'profile_name' => preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $text) === 1 ? $text : $fail(),
            'profile_name_or_none' => $text === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $text) === 1 ? $text : $fail(),
            // A bearer token: printable, no spaces, as a server hands it out
            'secret' => $text === '' || preg_match('/^[\x21-\x7e]{1,512}$/', $text) === 1 ? $text : $fail(),
            // Set by saveIcon(); a form can only clear it
            'icon' => $text === '' ? '' : $fail(),
            default => $fail(),
        };
    }

    /**
     * @return list<string>
     */
    private static function validNamespaces(mixed $raw, callable $fail): array
    {
        $list = \is_array($raw) ? $raw : preg_split('/[\s,]+/', \is_string($raw) ? $raw : '', -1, PREG_SPLIT_NO_EMPTY);
        $namespaces = [];
        foreach ($list ?: [] as $ns) {
            $ns = trim((string) $ns);
            if (preg_match('/^[a-z0-9][a-z0-9_-]*(:[a-z0-9][a-z0-9_-]*)*$/', $ns) !== 1) {
                $fail();
            }
            $namespaces[] = $ns;
        }

        return array_values(array_unique($namespaces));
    }

    /**
     * "MR = mri" lines (or a map) as modality code → namespace segment.
     *
     * @return array<string, string>
     */
    private static function validModalityMap(mixed $raw, callable $fail): array
    {
        $map = [];
        $lines = \is_array($raw) ? array_map(static fn ($k, $v): string => $k . '=' . $v, array_keys($raw), $raw) : preg_split('/\R/', \is_string($raw) ? $raw : '');
        foreach ($lines ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            [$modality, $ns] = array_map('trim', explode('=', $line, 2)) + [1 => ''];
            if (preg_match('/^[A-Z][A-Za-z0-9]{0,15}$/', $modality) !== 1 || preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $ns) !== 1) {
                $fail();
            }
            $map[$modality] = $ns;
        }

        return $map;
    }

    /**
     * The six AI server slots from the form (servers[i][name|endpoint|
     * api_key|remove_api_key|timeout|external_ack], and per alias
     * servers[i][tiers][lite|normal|expert][model|temperature|top_p|top_k|
     * min_p|max_tokens|extra] — phase 33a; the flat model and sampling
     * fields of before are dropped on save). A blank key keeps the slot's stored one; the box
     * clears it — the key is never shown, so never sent back.
     *
     * @param list<array<string, mixed>> $stored the slots as stored now
     *
     * @return list<array<string, mixed>>
     */
    private static function validServers(mixed $rows, array $stored): array
    {
        $rows = \is_array($rows) ? array_values($rows) : [];
        $servers = [];
        for ($i = 0; $i < AiConfig::SLOTS; ++$i) {
            $row = \is_array($rows[$i] ?? null) ? $rows[$i] : [];
            $in = static fn (string $key, string $type, mixed $raw): mixed => self::slotValue($i + 1, $key, $type, $raw);
            $key = \is_string($stored[$i]['api_key'] ?? null) ? $stored[$i]['api_key'] : '';
            if (($row['remove_api_key'] ?? '') === '1') {
                $key = '';
            } elseif (\is_string($row['api_key'] ?? null) && trim($row['api_key']) !== '') {
                $key = $in('ai.api_key', 'secret', $row['api_key']);
            }
            $tiers = [];
            foreach (AiConfig::TIERS as $tier) {
                $cells = \is_array($row['tiers'][$tier] ?? null) ? $row['tiers'][$tier] : [];
                $at = static fn (string $key, string $type, mixed $raw): mixed => self::slotValue($i + 1, $key, $type, $raw, $tier);
                // A blank field is kept blank: not sent, the server decides
                $opt = static fn (string $field, string $key, string $type): mixed => self::blank($cells[$field] ?? '') ? '' : $at($key, $type, $cells[$field]);
                $tiers[$tier] = [
                    'model' => $at('ai.model', 'model', $cells['model'] ?? ''),
                    'temperature' => $opt('temperature', 'ai.temperature', 'temperature'),
                    'top_p' => $opt('top_p', 'ai.top_p', 'unit'),
                    'top_k' => $opt('top_k', 'ai.top_k', 'top_k'),
                    'min_p' => $opt('min_p', 'ai.min_p', 'unit'),
                    'max_tokens' => $opt('max_tokens', 'ai.max_tokens', 'tokens'),
                    'extra' => self::blank($cells['extra'] ?? '') ? [] : $at('ai.extra', 'extra_json', $cells['extra']),
                ];
            }
            $servers[] = [
                'name' => mb_substr(trim(\is_string($row['name'] ?? null) ? $row['name'] : ''), 0, 40),
                'endpoint' => $in('ai.endpoint', 'url', $row['endpoint'] ?? ''),
                'api_key' => $key,
                'timeout' => $in('ai.timeout', 'seconds', ($row['timeout'] ?? '') === '' ? '120' : $row['timeout']),
                'external_ack' => $in('ai.external_ack', 'bool', $row['external_ack'] ?? ''),
                // Which models its listings show (phase 33c)
                'model_filter' => $in('ai.model_filter', 'model_filter', $row['model_filter'] ?? ''),
                // Per alias (phase 33a): its model and the parameters it sends
                'tiers' => $tiers,
            ];
        }

        return $servers;
    }

    private static function blank(mixed $raw): bool
    {
        return $raw === null || (\is_string($raw) && trim($raw) === '');
    }

    /** One server field, its message naming the slot (and the alias) */
    private static function slotValue(int $slot, string $key, string $type, mixed $raw, string $tier = ''): mixed
    {
        try {
            return self::valid($key, $type, $raw);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('Server ' . $slot . ($tier !== '' ? ', ' . $tier : '') . ': ' . $e->getMessage());
        }
    }

    /**
     * An alias's `extra`: a JSON object of request fields, ≤ 2 KB, plain
     * keys, none of the request's own (TierSettings::RESERVED)
     *
     * @return array<string, mixed>
     */
    private static function validExtra(string $text, \Closure $fail): array
    {
        if (\strlen($text) > 2048) {
            $fail();
        }
        try {
            $value = json_decode($text, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $fail();
        }
        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            $fail();
        }
        foreach (array_keys($value) as $key) {
            if (!\is_string($key) || preg_match('/^[a-z_][a-z0-9_]{0,63}$/', $key) !== 1 || \in_array($key, TierSettings::RESERVED, true)) {
                $fail();
            }
        }

        return $value;
    }

    /**
     * Rows from the form ({code, name, dept, address, phone, devices, remove?})
     * as the `sites` map the print view reads: code → fields + devices
     * (device code → name, one "CODE = name" per line in the form).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function validSites(mixed $rows): array
    {
        $sites = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row) || ($row['remove'] ?? '') === '1') {
                continue;
            }
            $code = strtolower(trim((string) ($row['code'] ?? '')));
            if ($code === '') {
                continue;
            }
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $code) !== 1 || isset($sites[$code])) {
                throw new InvalidArgumentException(t('admin.settings.err.site_code', [$code]));
            }
            $site = [];
            foreach (self::SITE_FIELDS as $field) {
                $site[$field] = mb_substr(trim((string) ($row[$field] ?? '')), 0, 200);
            }
            // D20 {SITE}: empty means the site code, upper-cased
            $site['accession_code'] = strtoupper($site['accession_code']);
            if ($site['accession_code'] !== '' && preg_match('/^[A-Z0-9]{1,12}$/', $site['accession_code']) !== 1) {
                throw new InvalidArgumentException(t('admin.settings.err.accession_code', [$code]));
            }
            $site['devices'] = [];
            foreach (preg_split('/\R/', (string) ($row['devices'] ?? '')) ?: [] as $line) {
                if (trim($line) === '') {
                    continue;
                }
                [$device, $name] = array_map('trim', explode('=', $line, 2)) + [1 => ''];
                if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,31}$/', $device) !== 1) {
                    throw new InvalidArgumentException(t('admin.settings.err.device', [$device]));
                }
                // "CODE = name | pacs: scanner; scanner" — the PACS scanner names linked to it (Support\Devices)
                $pacs = [];
                if (preg_match('/^(.*?)\s*\|\s*pacs:\s*(.*)$/iu', $name, $m) === 1) {
                    $name = $m[1];
                    $pacs = array_values(array_unique(array_filter(array_map(Devices::key(...), explode(';', $m[2])), static fn (string $k): bool => $k !== '')));
                }
                $site['devices'][$device] = Devices::dump(['name' => mb_substr($name, 0, 200), 'pacs' => $pacs]);
            }
            $sites[$code] = $site;
        }

        return $sites;
    }

    private static function isTimezone(string $name): bool
    {
        try {
            new DateTimeZone($name);

            return $name !== '';
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $settings */
    private function write(array $settings): void
    {
        if (!is_dir($this->dataRoot) && !mkdir($this->dataRoot, 0775, true) && !is_dir($this->dataRoot)) {
            throw new RuntimeException('Cannot create the data directory');
        }
        AtomicWriter::put(
            $this->file(),
            "# This instance's settings — edited from Admin → Settings (docs/FORMATS.md).\n"
            . "# Hand edits are fine; the next save from the admin screen rewrites the file.\n"
            . Yaml::dump($settings, 4, 2)
        );
        // It may hold the AI server's key: the web server's user and group only
        @chmod($this->file(), 0640);
    }

    private function file(): string
    {
        return $this->dataRoot . '/' . self::FILE;
    }
}
