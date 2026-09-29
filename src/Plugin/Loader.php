<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin;

use InvalidArgumentException;
use Throwable;

/**
 * Plugin discovery and registration (docs/architecture-api.md §5). A plugin
 * is `plugins/{id}/` with a `plugin.json` and a `Plugin.php` declaring
 * `Reporion\Plugin\{Id}\Plugin` (the id in StudlyCase); its other classes
 * autoload from the same directory, PSR-4 style. Discovery is a directory
 * scan at boot — a handful of manifests, no cache needed. Enabling is
 * configuration (`plugins.enabled`), never code.
 *
 * A plugin that throws while registering is skipped, never fatal: the rest
 * of the site keeps working, and Admin → Plugins says which one failed.
 * Logged by exception class only — a message might carry patient data
 * (invariant 8).
 */
final class Loader
{
    public function __construct(private readonly string $pluginsDir)
    {
    }

    /**
     * @param list<string>                        $enabled
     * @param array<class-string, object>         $services what plugins may ask the container for
     * @param array<string, array<string, mixed>> $stored   plugin id → its stored settings
     */
    public function load(array $enabled, array $services, array $stored, Hooks $hooks): Registry
    {
        [$manifests, $invalid] = $this->discover();
        $loaded = [];
        $failed = [];
        foreach ($enabled as $id) {
            $manifest = $manifests[$id] ?? null;
            if ($manifest === null) {
                continue;
            }
            try {
                self::registerAutoload($this->pluginsDir, $id);
                $class = self::pluginClass($id);
                if (!class_exists($class)) {
                    require_once $this->pluginsDir . '/' . $id . '/Plugin.php';
                }
                $plugin = new $class();
                if (!$plugin instanceof PluginInterface) {
                    throw new InvalidArgumentException('Not a plugin');
                }
                $hooks->forPlugin($id);
                $plugin->register($hooks, new Container($services, $manifest->settingValues($stored[$id] ?? []), $this->pluginsDir . '/' . $id));
                $loaded[] = $id;
            } catch (Throwable $e) {
                $failed[$id] = $e::class;
                error_log('reporion: plugin ' . $id . ' failed to register: ' . $e::class);
            } finally {
                $hooks->forPlugin('');
            }
        }

        return new Registry($manifests, $invalid, array_values(array_intersect($enabled, array_keys($manifests))), $loaded, $failed);
    }

    /**
     * Every plugin directory: its manifest, or why it has none.
     *
     * @return array{0: array<string, Manifest>, 1: array<string, string>}
     */
    public function discover(): array
    {
        $manifests = [];
        $invalid = [];
        foreach (glob($this->pluginsDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            try {
                $manifests[$name] = Manifest::fromFile($dir . '/plugin.json', $name);
            } catch (InvalidArgumentException $e) {
                $invalid[$name] = $e->getMessage();
            }
        }
        ksort($manifests);

        return [$manifests, $invalid];
    }

    /** `Reporion\Plugin\{Id}\X` → `plugins/{id}/X.php` */
    public static function registerAutoload(string $pluginsDir, string $id): void
    {
        static $registered = [];
        $prefix = 'Reporion\\Plugin\\' . self::studly($id) . '\\';
        $base = $pluginsDir . '/' . $id . '/';
        if (isset($registered[$prefix . $base])) {
            return;
        }
        $registered[$prefix . $base] = true;
        spl_autoload_register(static function (string $class) use ($prefix, $base): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $file = $base . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    public static function pluginClass(string $id): string
    {
        return 'Reporion\\Plugin\\' . self::studly($id) . '\\Plugin';
    }

    /**
     * The plugin's interface strings, `plugins/{id}/lang/en.php` (D26) —
     * only keys under its own `{id}.` prefix, so a plugin cannot reword the
     * core's chrome.
     *
     * @param list<string> $ids
     *
     * @return array<string, string>
     */
    public function strings(array $ids): array
    {
        $strings = [];
        foreach ($ids as $id) {
            $file = $this->pluginsDir . '/' . $id . '/lang/en.php';
            $own = is_file($file) ? require $file : [];
            foreach (\is_array($own) ? $own : [] as $key => $value) {
                if (\is_string($key) && \is_string($value) && str_starts_with($key, $id . '.')) {
                    $strings[$key] = $value;
                }
            }
        }

        return $strings;
    }

    private static function studly(string $id): string
    {
        return str_replace(' ', '', ucwords(str_replace('-', ' ', $id)));
    }
}
