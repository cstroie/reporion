<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use Reporion\Exception\AiException;

/**
 * The one embedding model of *Similar reports* (roadmap phase 34e,
 * 2026-10-08): a server from Admin → AI (`ai.embed_server`, 1–6) and the
 * model on it (`ai.embed_model`) — one for the whole instance, not one per
 * server. Its `POST /embeddings` under that server's own address, key, time
 * limit and egress rule. Vectors come back at unit length, so similarity is
 * a dot product (Index\Sqlite::similar()).
 */
final class Embedder
{
    public function __construct(
        private readonly OpenAiCompatibleProvider $provider,
        public readonly string $model,
        public readonly string $serverName,
    ) {
    }

    /**
     * The embedder Admin → AI sets up; null when the assistant is off, or no
     * server or no model is chosen, or that server has no address
     *
     * @param array<string, mixed> $config the effective config
     */
    public static function fromConfig(array $config): ?self
    {
        $ai = \is_array($config['ai'] ?? null) ? $config['ai'] : [];
        $slot = is_numeric($ai['embed_server'] ?? null) ? (int) $ai['embed_server'] : 0;
        $model = \is_string($ai['embed_model'] ?? null) ? trim($ai['embed_model']) : '';
        if (($ai['enabled'] ?? false) !== true || $slot < 1 || $slot > AiConfig::SLOTS || $model === '') {
            return null;
        }
        $server = AiConfig::fromConfig($config, $slot);
        if ($server->endpoint === '') {
            return null;
        }

        return new self(new OpenAiCompatibleProvider($server, new EgressGuard()), $model, $server->serverName);
    }

    /**
     * @param list<string> $texts
     *
     * @return list<list<float>> one unit-length vector per text
     *
     * @throws AiException
     */
    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        return array_map(self::unit(...), $this->provider->embeddings($texts, $this->model));
    }

    /**
     * @param list<float> $vector
     *
     * @return list<float>
     */
    public static function unit(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $vector)));

        return $norm > 0 ? array_map(static fn (float $v): float => $v / $norm, $vector) : $vector;
    }
}
