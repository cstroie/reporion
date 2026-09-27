<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

/**
 * The assistant's settings (roadmap phase 15): Admin → Settings → AI
 * (`data/settings.yaml`), laid over `conf/local.php`, whose `ai.api_key` is
 * the one secret and never shown or stored anywhere else.
 */
final class AiConfig
{
    /**
     * @param array<string, string> $profiles     namespace → prompt profile (`*` for the rest)
     * @param list<string>          $allowEgressTo hosts beyond the private network it may reach
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $endpoint,
        public readonly string $model,
        public readonly float $temperature,
        public readonly float $topP,
        public readonly int $maxTokens,
        public readonly int $timeout,
        public readonly array $profiles,
        public readonly array $allowEgressTo,
        public readonly bool $externalAck,
        public readonly string $apiKey,
    ) {
    }

    /** @param array<string, mixed> $config the effective config */
    public static function fromConfig(array $config): self
    {
        $ai = \is_array($config['ai'] ?? null) ? $config['ai'] : [];
        $profiles = \is_array($ai['profiles'] ?? null) && $ai['profiles'] !== [] ? $ai['profiles'] : ['reports' => 'reports', '*' => 'default'];

        return new self(
            enabled: ($ai['enabled'] ?? false) === true,
            endpoint: rtrim(\is_string($ai['endpoint'] ?? null) ? trim($ai['endpoint']) : '', '/'),
            model: \is_string($ai['model'] ?? null) ? trim($ai['model']) : '',
            temperature: is_numeric($ai['temperature'] ?? null) ? (float) $ai['temperature'] : 0.3,
            topP: is_numeric($ai['top_p'] ?? null) ? (float) $ai['top_p'] : 0.8,
            maxTokens: is_numeric($ai['max_tokens'] ?? null) ? (int) $ai['max_tokens'] : 0,
            timeout: is_numeric($ai['timeout'] ?? null) ? max(5, (int) $ai['timeout']) : 120,
            profiles: array_map('strval', $profiles),
            allowEgressTo: array_values(array_map('strval', \is_array($ai['allow_egress_to'] ?? null) ? $ai['allow_egress_to'] : [])),
            externalAck: ($ai['external_ack'] ?? false) === true,
            apiKey: \is_string($ai['api_key'] ?? null) ? $ai['api_key'] : '',
        );
    }

    /** Whether the assistant is switched on and has somewhere to go */
    public function isConfigured(): bool
    {
        return $this->enabled && $this->endpoint !== '' && $this->model !== '';
    }

    /** The prompt profile for a page: the longest configured namespace that holds it, else `*` */
    public function profileFor(string $path): ?string
    {
        $best = null;
        $bestLength = -1;
        foreach ($this->profiles as $ns => $profile) {
            if ($ns === '*') {
                continue;
            }
            if (($path === $ns || str_starts_with($path, $ns . ':')) && \strlen($ns) > $bestLength) {
                $best = $profile;
                $bestLength = \strlen($ns);
            }
        }

        return $best ?? ($this->profiles['*'] ?? null);
    }
}
