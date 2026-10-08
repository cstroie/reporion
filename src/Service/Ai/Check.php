<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use Reporion\Exception\AiException;

/**
 * What `bin/reporion ai:check` and Admin → AI say about the assistant: its
 * settings, the egress verdict for its server, and — when asked to reach
 * it — the models the server offers. Never sends report text, only
 * `GET {endpoint}/models`; never shows the key, only whether there is one.
 */
final class Check
{
    public function __construct(private readonly EgressGuard $egress = new EgressGuard())
    {
    }

    /**
     * @return array{enabled: bool, endpoint: string, model: string, tiers: array<string, string>, api_key: string, external: ?bool, egress: ?string, models: list<string>, error: ?string, ok: bool}
     */
    public function run(AiConfig $ai, bool $reachServer = true): array
    {
        $report = [
            'enabled' => $ai->enabled,
            'endpoint' => $ai->endpoint,
            'model' => $ai->model,
            // The model each alias stands for here, an empty lite/expert being the normal one
            'tiers' => array_combine(AiConfig::TIERS, array_map(static fn (string $tier): string => $ai->modelFor($tier), AiConfig::TIERS)),
            'api_key' => $ai->apiKey !== '' ? 'set' : 'none',
            'external' => null,
            'egress' => null,
            'models' => [],
            'error' => null,
            'ok' => false,
        ];
        try {
            if (!$ai->isConfigured()) {
                throw new AiException('not_configured', 'Not configured: enable it and set the server address and model (Admin → AI)');
            }
            $report['external'] = $this->egress->isExternal($ai->endpoint);
            $this->egress->assertAllowed($ai->endpoint, $ai->externalAck);
            $report['egress'] = 'allowed';
            if ($reachServer) {
                $report['models'] = (new OpenAiCompatibleProvider($ai, $this->egress))->models();
                // It answered, but not with an OpenAI-style list: most likely not the …/v1 base
                // (LM Studio's own API is /api/v1, its OpenAI-compatible one /v1)
                if ($report['models'] === []) {
                    throw new AiException('no_models', 'The server answered but listed no models the OpenAI way — is the address its OpenAI-compatible …/v1 base (for LM Studio: http://host:1234/v1)?');
                }
                foreach ($report['tiers'] as $tier => $model) {
                    if (!\in_array($model, $report['models'], true)) {
                        throw new AiException('unknown_model', 'The server does not list the model "' . $model . '"' . ($tier !== AiConfig::DEFAULT_TIER ? ' (' . $tier . ')' : ''));
                    }
                }
            }
            $report['ok'] = true;
        } catch (AiException $e) {
            $report['egress'] ??= $e->reason === 'egress_denied' ? 'denied' : null;
            // No report text went out here, so the server's own words are safe to show
            $report['error'] = $e->getMessage() . ($e->detail !== '' ? ' (' . $e->detail . ')' : '');
        }

        return $report;
    }
}
