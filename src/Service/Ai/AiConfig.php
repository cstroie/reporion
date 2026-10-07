<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

/**
 * The assistant's settings (roadmap phase 15): Admin → AI, kept in
 * `data/settings.yaml` with the rest of the instance's settings — API keys
 * too (2026-09-27), never shown back to a browser.
 *
 * Up to three **servers** (`ai.servers`, each with its own address, model,
 * key, sampling, time limit and egress acknowledgement) and the one in use
 * (`ai.server`, 1–3); one **prompt profile** in use (`ai.prompt_profile`,
 * the pages under `ai:profiles:{profile}`) on the namespaces it serves
 * (`ai.namespaces`). The flat keys of before (`ai.endpoint`, `ai.model`, …,
 * `ai.profiles`) are read as server 1 and the `reports` profile until the
 * next save; so is whatever `conf/local.php` still carries under `ai`.
 */
final class AiConfig
{
    public const SLOTS = 3;

    /** What a server carries */
    public const SERVER_FIELDS = ['name', 'endpoint', 'model', 'model_lite', 'model_expert', 'api_key', 'temperature', 'top_p', 'max_tokens', 'timeout', 'external_ack'];

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
    ) {
    }

    /** A tier name as written in an action's table: lite, normal or expert; anything else is normal */
    public static function tier(string $value): string
    {
        $value = strtolower(trim($value));

        return \in_array($value, self::TIERS, true) ? $value : self::DEFAULT_TIER;
    }

    /** The model an alias stands for on this server: the tier's own, else `normal`'s */
    public function modelFor(string $tier): string
    {
        $own = match (self::tier($tier)) {
            'lite' => $this->modelLite,
            'expert' => $this->modelExpert,
            default => '',
        };

        return $own !== '' ? $own : $this->model;
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

    /** @param array<string, mixed> $config the effective config */
    public static function fromConfig(array $config): self
    {
        $ai = \is_array($config['ai'] ?? null) ? $config['ai'] : [];
        $slot = self::slot($ai);
        $server = self::servers($ai)[$slot - 1];

        return new self(
            enabled: ($ai['enabled'] ?? false) === true,
            endpoint: self::base(\is_string($server['endpoint'] ?? null) ? $server['endpoint'] : ''),
            model: \is_string($server['model'] ?? null) ? trim($server['model']) : '',
            temperature: self::sampling($server, 'temperature', 0.3),
            topP: self::sampling($server, 'top_p', 0.8),
            maxTokens: is_numeric($server['max_tokens'] ?? null) ? (int) $server['max_tokens'] : 0,
            timeout: is_numeric($server['timeout'] ?? null) ? max(5, (int) $server['timeout']) : 120,
            promptProfile: self::promptProfile($ai),
            namespaces: self::namespaces($ai),
            externalAck: ($server['external_ack'] ?? false) === true,
            apiKey: \is_string($server['api_key'] ?? null) ? $server['api_key'] : '',
            server: $slot,
            serverName: self::serverName($server, $slot),
            modelLite: \is_string($server['model_lite'] ?? null) ? trim($server['model_lite']) : '',
            modelExpert: \is_string($server['model_expert'] ?? null) ? trim($server['model_expert']) : '',
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

    /** The prompt profile for a page: the one in use, where it serves; else none */
    public function profileFor(string $path): ?string
    {
        foreach ($this->namespaces as $ns) {
            if ($path === $ns || str_starts_with($path, $ns . ':')) {
                return $this->promptProfile;
            }
        }

        return null;
    }
}
