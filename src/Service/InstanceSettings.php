<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use DateTimeZone;
use InvalidArgumentException;
use Reporion\Storage\AtomicWriter;
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
    ];

    /** Fields of each entry under `sites` (letterhead and devices, per site code) */
    public const SITE_FIELDS = ['name', 'dept', 'address', 'phone'];

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

        return $config;
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
        if ($changed !== []) {
            $this->write($settings);
        }

        return $changed;
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
            $site['devices'] = [];
            foreach (preg_split('/\R/', (string) ($row['devices'] ?? '')) ?: [] as $line) {
                if (trim($line) === '') {
                    continue;
                }
                [$device, $name] = array_map('trim', explode('=', $line, 2)) + [1 => ''];
                if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,31}$/', $device) !== 1) {
                    throw new InvalidArgumentException(t('admin.settings.err.device', [$device]));
                }
                $site['devices'][$device] = mb_substr($name, 0, 200);
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
    }

    private function file(): string
    {
        return $this->dataRoot . '/' . self::FILE;
    }
}
