<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

/**
 * The assistant's settings (roadmap phase 15): Admin → AI, kept in
 * `data/settings.yaml` with the rest of the instance's settings — API keys
 * too (2026-09-27), never shown back to a browser.
 *
 * Up to six **servers** (`ai.servers`, each with its own address, key, time
 * limit, egress acknowledgement and, per model alias, a model and the
 * parameters it sends — `tiers`, phase 33a) and the one in use
 * (`ai.server`, 1–6); one **prompt profile** in use (`ai.prompt_profile`,
 * the pages under `ai:profiles:{profile}`) on the namespaces it serves
 * (`ai.namespaces`), and optionally a **fallback profile** for every other
 * page (`ai.fallback_profile`, 2026-10-08). The flat keys of before (`ai.endpoint`, `ai.model`, …,
 * `ai.profiles`) are read as server 1 and the `reports` profile until the
 * next save; so is whatever `conf/local.php` still carries under `ai`.
 */
final class AiConfig
{
    public const SLOTS = 6;

    /** What a server carries */
    public const SERVER_FIELDS = ['name', 'endpoint', 'model', 'model_lite', 'model_expert', 'api_key', 'temperature', 'top_p', 'max_tokens', 'timeout', 'external_ack', 'tiers', 'model_filter', 'fallback'];

    /** What each alias of a server carries (`ai.servers[i].tiers.{alias}`), in the form's row order */
    public const TIER_FIELDS = ['model', 'temperature', 'top_p', 'top_k', 'min_p', 'max_tokens', 'extra'];

    /**
     * The model aliases an action's `Model` column names; each server defines
     * what they mean. `normal` is the server's `model` (the one every server
     * has had since the start); an empty `lite` or `expert` falls back to it.
     */
    public const TIERS = ['lite', 'normal', 'expert'];

    public const DEFAULT_TIER = 'normal';

    /**
     * @param list<string> $namespaces where the prompt profile serves (prefix match)
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $endpoint,
        public readonly string $model,
        public readonly ?float $temperature,
        public readonly ?float $topP,
        public readonly int $maxTokens,
        public readonly int $timeout,
        public readonly string $promptProfile,
        public readonly array $namespaces,
        public readonly bool $externalAck,
        public readonly string $apiKey,
        public readonly int $server = 1,
        public readonly string $serverName = '',
        public readonly string $modelLite = '',
        public readonly string $modelExpert = '',
        /** The profile for pages outside $namespaces; '' for none (no assistant there) */
        public readonly string $fallbackProfile = '',
        /** @var array<string, TierSettings> per alias; empty: made from the flat fields above */
        public readonly array $tiers = [],
        /** Which of the server's models its listings show (phase 33c): a pattern, '' for all */
        public readonly string $modelFilter = '',
        /** The slot to try when this server cannot answer (phase 34f); null for none */
        public readonly ?int $fallback = null,
    ) {
    }

    /**
     * A model filter as a PHP regular expression: `/…/flags` as written, any
     * other text as a case-insensitive pattern (`free`, `qwen|llama`); null
     * when it does not compile
     */
    public static function filterPattern(string $filter): ?string
    {
        $filter = trim($filter);
        if ($filter === '') {
            return null;
        }
        $pattern = preg_match('~^/.+/[imsux]*$~s', $filter) === 1 ? $filter : '~' . str_replace('~', '\\~', $filter) . '~i';

        return @preg_match($pattern, '') === false ? null : $pattern;
    }

    /**
     * @param list<string> $models
     *
     * @return list<string> those the server's filter lets through (all when it has none)
     */
    public function filterModels(array $models): array
    {
        $pattern = self::filterPattern($this->modelFilter);

        return $pattern === null ? $models : array_values(array_filter($models, static fn (string $m): bool => preg_match($pattern, $m) === 1));
    }

    /**
     * What an alias sends on this server: its own settings when it names a
     * model, else the normal alias's — model and parameters together
     */
    public function settingsFor(string $tier): TierSettings
    {
        $tiers = $this->tiers !== [] ? $this->tiers : [
            'lite' => new TierSettings($this->modelLite, $this->temperature, $this->topP, null, null, $this->maxTokens),
            'normal' => new TierSettings($this->model, $this->temperature, $this->topP, null, null, $this->maxTokens),
            'expert' => new TierSettings($this->modelExpert, $this->temperature, $this->topP, null, null, $this->maxTokens),
        ];
        $own = $tiers[self::tier($tier)] ?? null;

        return $own !== null && $own->model !== '' ? $own : ($tiers['normal'] ?? new TierSettings($this->model));
    }

    /**
     * A server's aliases as stored, for the form and for reading: `tiers`
     * when the server has been saved since phase 33a, else the flat fields
     * of before — `model`/`model_lite`/`model_expert` as the aliases'
     * models and the server-wide `temperature`/`top_p`/`max_tokens` on each
     * (a key never written keeps the old defaults, 0.3 and 0.8)
     *
     * @param array<string, mixed> $server
     *
     * @return array<string, array<string, mixed>> alias → field → value ('' when blank)
     */
    public static function tierRows(array $server): array
    {
        $rows = [];
        foreach (self::TIERS as $tier) {
            if (\is_array($server['tiers'] ?? null)) {
                $stored = \is_array($server['tiers'][$tier] ?? null) ? $server['tiers'][$tier] : [];
                $row = [];
                foreach (self::TIER_FIELDS as $field) {
                    $row[$field] = $stored[$field] ?? '';
                }
            } else {
                $model = $server[['lite' => 'model_lite', 'normal' => 'model', 'expert' => 'model_expert'][$tier]] ?? '';
                $row = [
                    'model' => \is_string($model) ? trim($model) : '',
                    'temperature' => self::sampling($server, 'temperature', 0.3) ?? '',
                    'top_p' => self::sampling($server, 'top_p', 0.8) ?? '',
                    'top_k' => '',
                    'min_p' => '',
                    'max_tokens' => is_numeric($server['max_tokens'] ?? null) && (int) $server['max_tokens'] > 0 ? (int) $server['max_tokens'] : '',
                    'extra' => [],
                ];
            }
            $rows[$tier] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $server
     *
     * @return array<string, TierSettings>
     */
    private static function tierSettings(array $server): array
    {
        $number = static fn (mixed $v): ?float => is_numeric($v) ? (float) $v : null;
        $settings = [];
        foreach (self::tierRows($server) as $tier => $row) {
            $settings[$tier] = new TierSettings(
                \is_string($row['model']) ? trim($row['model']) : '',
                $number($row['temperature']),
                $number($row['top_p']),
                is_numeric($row['top_k']) ? (int) $row['top_k'] : null,
                $number($row['min_p']),
                is_numeric($row['max_tokens']) ? max(0, (int) $row['max_tokens']) : 0,
                \is_array($row['extra']) ? $row['extra'] : [],
            );
        }

        return $settings;
    }

    /** A tier name as written in an action's table: lite, normal or expert; anything else is normal */
    public static function tier(string $value): string
    {
        $value = strtolower(trim($value));

        return \in_array($value, self::TIERS, true) ? $value : self::DEFAULT_TIER;
    }

    /** The model an alias stands for on this server: the alias's own, else `normal`'s */
    public function modelFor(string $tier): string
    {
        return $this->settingsFor($tier)->model;
    }

    /**
     * A sampling setting: a number is sent; a blank one ('' or null — the
     * owner emptied the field) is not sent, the server's own default then
     * applies; a key never written at all keeps $default.
     *
     * @param array<string, mixed> $server
     */
    private static function sampling(array $server, string $key, float $default): ?float
    {
        if (!\array_key_exists($key, $server)) {
            return $default;
        }

        return is_numeric($server[$key]) ? (float) $server[$key] : null;
    }

    /**
     * What an action's `Model` cell says (2026-10-07): `{server}:{alias}` — a
     * server by the name Admin → AI gives it — or, with no colon, just the
     * alias (`lite`, `normal`, `expert`; blank or unknown is normal) on the
     * server in use.
     *
     * @return array{server: ?string, tier: string}
     */
    public static function parseModel(string $value): array
    {
        $value = trim($value);
        $colon = strrpos($value, ':');
        if ($colon === false) {
            return ['server' => null, 'tier' => self::tier($value)];
        }
        $server = trim(substr($value, 0, $colon));

        return ['server' => $server !== '' ? $server : null, 'tier' => self::tier(substr($value, $colon + 1))];
    }

    /**
     * The slot (1–6) of the server called $name, case aside; null when none is
     *
     * @param array<string, mixed> $config the effective config
     */
    public static function slotByName(array $config, string $name): ?int
    {
        $ai = \is_array($config['ai'] ?? null) ? $config['ai'] : [];
        foreach (self::servers($ai) as $i => $server) {
            if (strcasecmp(self::serverName($server, $i + 1), trim($name)) === 0) {
                return $i + 1;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config the effective config
     * @param ?int                 $server the slot to read; the one in use when null
     */
    public static function fromConfig(array $config, ?int $server = null): self
    {
        $ai = \is_array($config['ai'] ?? null) ? $config['ai'] : [];
        $slot = $server ?? self::slot($ai);
        $server = self::servers($ai)[$slot - 1];

        $tiers = self::tierSettings($server);
        $normal = $tiers['normal'];

        return new self(
            enabled: ($ai['enabled'] ?? false) === true,
            endpoint: self::base(\is_string($server['endpoint'] ?? null) ? $server['endpoint'] : ''),
            model: $normal->model,
            temperature: $normal->temperature,
            topP: $normal->topP,
            maxTokens: $normal->maxTokens,
            timeout: is_numeric($server['timeout'] ?? null) ? max(5, (int) $server['timeout']) : 120,
            promptProfile: self::promptProfile($ai),
            namespaces: self::namespaces($ai),
            externalAck: ($server['external_ack'] ?? false) === true,
            apiKey: \is_string($server['api_key'] ?? null) ? $server['api_key'] : '',
            server: $slot,
            serverName: self::serverName($server, $slot),
            modelLite: $tiers['lite']->model,
            modelExpert: $tiers['expert']->model,
            fallbackProfile: self::fallbackProfile($ai),
            tiers: $tiers,
            modelFilter: \is_string($server['model_filter'] ?? null) ? $server['model_filter'] : '',
            fallback: is_numeric($server['fallback'] ?? null) && (int) $server['fallback'] >= 1 && (int) $server['fallback'] <= self::SLOTS && (int) $server['fallback'] !== $slot ? (int) $server['fallback'] : null,
        );
    }

    /**
     * The three server slots, each a map of SERVER_FIELDS (empty for an
     * unused slot); before `ai.servers` existed, the flat keys are server 1.
     *
     * @param array<string, mixed> $ai the config's `ai` section
     *
     * @return list<array<string, mixed>>
     */
    public static function servers(array $ai): array
    {
        if (\is_array($ai['servers'] ?? null) && $ai['servers'] !== []) {
            $rows = array_values(array_map(static fn (mixed $row): array => \is_array($row) ? $row : [], $ai['servers']));
        } else {
            $legacy = array_intersect_key($ai, array_flip(self::SERVER_FIELDS));
            $rows = [$legacy];
        }

        return \array_slice(array_pad($rows, self::SLOTS, []), 0, self::SLOTS);
    }

    /** @param array<string, mixed> $ai */
    public static function slot(array $ai): int
    {
        $slot = is_numeric($ai['server'] ?? null) ? (int) $ai['server'] : 1;

        return $slot >= 1 && $slot <= self::SLOTS ? $slot : 1;
    }

    /** @param array<string, mixed> $server */
    public static function serverName(array $server, int $slot): string
    {
        $name = \is_string($server['name'] ?? null) ? trim($server['name']) : '';

        return $name !== '' ? $name : 'Server ' . $slot;
    }

    /** @param array<string, mixed> $ai */
    public static function promptProfile(array $ai): string
    {
        if (\is_string($ai['prompt_profile'] ?? null) && $ai['prompt_profile'] !== '') {
            return $ai['prompt_profile'];
        }
        // The map of before: the profile the reports namespace used
        $map = \is_array($ai['profiles'] ?? null) ? $ai['profiles'] : [];
        foreach ($map as $ns => $profile) {
            if ($ns !== '*' && \is_string($profile) && $profile !== '') {
                return $profile;
            }
        }

        return 'reports';
    }

    /**
     * The profile for every page outside `ai.namespaces`: `ai.fallback_profile`,
     * else the old map's `*` entry, else none
     *
     * @param array<string, mixed> $ai
     */
    public static function fallbackProfile(array $ai): string
    {
        if (\array_key_exists('fallback_profile', $ai)) {
            return \is_string($ai['fallback_profile']) ? $ai['fallback_profile'] : '';
        }
        $map = \is_array($ai['profiles'] ?? null) ? $ai['profiles'] : [];

        return \is_string($map['*'] ?? null) ? $map['*'] : '';
    }

    /**
     * @param array<string, mixed> $ai
     *
     * @return list<string>
     */
    public static function namespaces(array $ai): array
    {
        if (\is_array($ai['namespaces'] ?? null) && $ai['namespaces'] !== []) {
            return array_values(array_map('strval', $ai['namespaces']));
        }
        $map = \is_array($ai['profiles'] ?? null) ? $ai['profiles'] : [];
        $namespaces = array_values(array_filter(array_map('strval', array_keys($map)), static fn (string $ns): bool => $ns !== '*'));

        return $namespaces !== [] ? $namespaces : ['reports'];
    }

    /**
     * The `…/v1` base the provider adds its paths to: a full
     * `…/chat/completions` (or `…/models`) address pasted from a server's
     * docs is taken back to it.
     */
    public static function base(string $endpoint): string
    {
        return (string) preg_replace('~/(?:chat/completions|completions|models)$~i', '', rtrim(trim($endpoint), '/'));
    }

    /** Whether the assistant is switched on and has somewhere to go */
    public function isConfigured(): bool
    {
        return $this->enabled && $this->endpoint !== '' && $this->model !== '';
    }

    /** The prompt profile for a page: the one in use, where it serves; else the fallback, if any */
    public function profileFor(string $path): ?string
    {
        foreach ($this->namespaces as $ns) {
            if ($path === $ns || str_starts_with($path, $ns . ':')) {
                return $this->promptProfile;
            }
        }

        return $this->fallbackProfile !== '' ? $this->fallbackProfile : null;
    }
}
