<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin;

use InvalidArgumentException;

/**
 * `plugins/{id}/plugin.json`: who the plugin is, the hooks it uses, its
 * settings (each with a type and a default, edited in Admin → Plugins) and
 * the interface slots it fills. A manifest that does not parse, names a
 * different id than its directory or targets another api version is not a
 * plugin — the loader lists it as broken and never runs its code.
 *
 * Setting types: text, url, secret (never shown back), int, bool, enum
 * (with `values`), list (comma-separated in the form, a list on disk).
 *
 * Interface slots (`ui`), all optional:
 *   page_action  {label, icon, href}  the ⋯ menu of a report, for writers;
 *                                     `{pid}` in href is the page's pid
 *   page_tab     {label, icon, href}  a tab of a report, for writers, after
 *                                     the built-in ones; `{pid}` as above. The
 *                                     route marks it current with the header
 *                                     tab `plugin:{id}`
 *   new_report   {label, icon, href}  a button on the guided new-report form
 * `label` is a lang key from the plugin's lang/en.php.
 */
final class Manifest
{
    public const API = 1;

    public const SETTING_TYPES = ['text', 'url', 'secret', 'int', 'bool', 'enum', 'list'];

    public const SLOTS = ['page_action', 'page_tab', 'new_report'];

    /**
     * @param list<string>                                  $hooks
     * @param array<string, array<string, mixed>>           $settings key → {type, default, values?, label?}
     * @param array<string, array{label: string, icon: string, href: string}> $ui
     */
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $version,
        public readonly string $description,
        public readonly array $hooks,
        public readonly array $settings,
        public readonly array $ui,
    ) {
    }

    /**
     * @throws InvalidArgumentException when the manifest is not a valid one for $dirName
     */
    public static function fromFile(string $file, string $dirName): self
    {
        $raw = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!\is_array($raw)) {
            throw new InvalidArgumentException('plugin.json does not parse');
        }
        $id = \is_string($raw['id'] ?? null) ? $raw['id'] : '';
        if ($id !== $dirName || preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $id) !== 1) {
            throw new InvalidArgumentException('plugin.json id must be its directory name');
        }
        if (($raw['api'] ?? null) !== self::API) {
            throw new InvalidArgumentException('plugin.json targets another plugin api');
        }
        $hooks = array_values(array_filter((array) ($raw['hooks'] ?? []), 'is_string'));
        $settings = [];
        foreach (\is_array($raw['settings'] ?? null) ? $raw['settings'] : [] as $key => $spec) {
            if (!\is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1 || !\is_array($spec) || !\in_array($spec['type'] ?? null, self::SETTING_TYPES, true)) {
                throw new InvalidArgumentException('plugin.json has an invalid setting');
            }
            if ($spec['type'] === 'enum' && (!\is_array($spec['values'] ?? null) || $spec['values'] === [])) {
                throw new InvalidArgumentException('plugin.json has an enum setting without values');
            }
            $settings[$key] = $spec;
        }
        $ui = [];
        foreach (\is_array($raw['ui'] ?? null) ? $raw['ui'] : [] as $slot => $item) {
            if (!\in_array($slot, self::SLOTS, true) || !\is_array($item) || !\is_string($item['label'] ?? null) || !\is_string($item['href'] ?? null) || !str_starts_with($item['href'], '/x/' . $id . '/')) {
                // A slot links only into the plugin's own routes
                throw new InvalidArgumentException('plugin.json has an invalid ui slot');
            }
            $ui[$slot] = ['label' => $item['label'], 'icon' => \is_string($item['icon'] ?? null) ? $item['icon'] : 'puzzle-piece', 'href' => $item['href']];
        }

        return new self(
            $id,
            \is_string($raw['name'] ?? null) ? $raw['name'] : $id,
            \is_string($raw['version'] ?? null) ? $raw['version'] : '',
            \is_string($raw['description'] ?? null) ? $raw['description'] : '',
            $hooks,
            $settings,
            $ui,
        );
    }

    /**
     * Every setting's value: the default, then $stored over it where it
     * holds a value of the right type.
     *
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    public function settingValues(array $stored): array
    {
        $values = [];
        foreach ($this->settings as $key => $spec) {
            $values[$key] = $spec['default'] ?? self::emptyOf((string) $spec['type']);
            if (\array_key_exists($key, $stored)) {
                try {
                    $values[$key] = self::valid($spec, $stored[$key]);
                } catch (InvalidArgumentException) {
                }
            }
        }

        return $values;
    }

    /**
     * Validates one setting's value as posted from the admin form (strings)
     * or read from disk.
     *
     * @param array<string, mixed> $spec
     *
     * @throws InvalidArgumentException
     */
    public static function valid(array $spec, mixed $raw): mixed
    {
        $type = (string) $spec['type'];
        if ($type === 'bool') {
            return \is_bool($raw) ? $raw : \in_array($raw, ['1', 'on', 'true', 1], true);
        }
        if ($type === 'list') {
            $items = \is_array($raw) ? $raw : explode(',', \is_scalar($raw) ? (string) $raw : '');
            $items = array_map(static fn (mixed $v): string => trim(\is_scalar($v) ? (string) $v : ''), $items);

            return array_values(array_filter($items, static fn (string $v): bool => $v !== '' && preg_match('/^[\w.:-]{1,64}$/u', $v) === 1));
        }
        if (!\is_scalar($raw)) {
            throw new InvalidArgumentException('Invalid value');
        }
        $value = trim((string) $raw);
        switch ($type) {
            case 'int':
                if (preg_match('/^-?\d{1,9}$/', $value) !== 1) {
                    throw new InvalidArgumentException('Not a number');
                }
                $int = (int) $value;
                if ((isset($spec['min']) && $int < (int) $spec['min']) || (isset($spec['max']) && $int > (int) $spec['max'])) {
                    throw new InvalidArgumentException('Out of range');
                }

                return $int;
            case 'enum':
                if (!\in_array($value, array_map('strval', (array) $spec['values']), true)) {
                    throw new InvalidArgumentException('Not one of the choices');
                }

                return $value;
            case 'url':
                if ($value !== '' && (filter_var($value, FILTER_VALIDATE_URL) === false || !\in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true))) {
                    throw new InvalidArgumentException('Not an http(s) address');
                }

                return rtrim($value, '/');
            default: // text, secret
                if (mb_strlen($value) > 500 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                    throw new InvalidArgumentException('Invalid text');
                }

                return $value;
        }
    }

    private static function emptyOf(string $type): mixed
    {
        return match ($type) {
            'bool' => false,
            'int' => 0,
            'list' => [],
            default => '',
        };
    }
}
