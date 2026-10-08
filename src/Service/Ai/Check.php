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
     * @return array{enabled: bool, endpoint: string, model: string, tiers: array<string, string>, params: array<string, array<string, mixed>>, api_key: string, external: ?bool, egress: ?string, models: list<string>, error: ?string, ok: bool}
     */
    public function run(AiConfig $ai, bool $reachServer = true): array
    {
        $report = [
            'enabled' => $ai->enabled,
            'endpoint' => $ai->endpoint,
            'model' => $ai->model,
            // The model each alias stands for here, an empty lite/expert being the normal one
            'tiers' => array_combine(AiConfig::TIERS, array_map(static fn (string $tier): string => $ai->modelFor($tier), AiConfig::TIERS)),
            // What each alias sends besides its model (phase 33a): blank ones are left out
            'params' => array_combine(AiConfig::TIERS, array_map(static fn (string $tier): array => $ai->settingsFor($tier)->params(), AiConfig::TIERS)),
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
                $all = (new OpenAiCompatibleProvider($ai, $this->egress))->models();
                // Shown through the server's filter; the aliases are looked up in all of them
                $report['models'] = $ai->filterModels($all);
                // It answered, but not with an OpenAI-style list: most likely not the …/v1 base
                // (LM Studio's own API is /api/v1, its OpenAI-compatible one /v1)
                if ($all === []) {
                    throw new AiException('no_models', 'The server answered but listed no models the OpenAI way — is the address its OpenAI-compatible …/v1 base (for LM Studio: http://host:1234/v1)?');
                }
                foreach ($report['tiers'] as $tier => $model) {
                    if (!\in_array($model, $all, true)) {
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

    /**
     * One server's models, through its filter, for Admin → AI's *Get models*
     * (phase 33c) — never report text, only `GET {endpoint}/models`
     *
     * @return array{data: list<string>, total: int, error: ?string}
     */
    public function models(AiConfig $ai): array
    {
        try {
            $all = $this->listAll($ai);

            return ['data' => $ai->filterModels($all), 'total' => \count($all), 'error' => null];
        } catch (AiException $e) {
            return ['data' => [], 'total' => 0, 'error' => self::said($e)];
        }
    }

    /**
     * Admin → AI's *Test* (phase 33c): is each alias's model among the
     * server's, and does one fixed one-line request (Context::probe(), no
     * report text) come back with that alias's parameters — the server's own
     * error words when not, so a refused parameter shows here
     *
     * @return list<array{tier: string, model: string, listed: ?bool, ok: bool, ms: int, answer: string, error: ?string}>
     */
    public function test(AiConfig $ai): array
    {
        try {
            $all = $this->listAll($ai);
        } catch (AiException $e) {
            return [['tier' => '', 'model' => '', 'listed' => null, 'ok' => false, 'ms' => 0, 'answer' => '', 'error' => self::said($e)]];
        }
        $rows = [];
        $seen = [];
        foreach (AiConfig::TIERS as $tier) {
            $settings = $ai->settingsFor($tier);
            if ($settings->model === '' || isset($seen[spl_object_id($settings)])) {
                continue; // an alias with no model of its own is the normal one: tested once
            }
            $seen[spl_object_id($settings)] = true;
            $started = hrtime(true);
            $row = ['tier' => $tier, 'model' => $settings->model, 'listed' => $all === [] ? null : \in_array($settings->model, $all, true), 'ok' => false, 'ms' => 0, 'answer' => '', 'error' => null];
            try {
                $answer = '';
                foreach ((new OpenAiCompatibleProvider($ai, $this->egress))->stream(Context::probe($tier)) as $piece) {
                    $answer .= $piece;
                }
                $row['ok'] = true;
                $row['answer'] = mb_substr(trim($answer), 0, 80);
            } catch (AiException $e) {
                $row['error'] = self::said($e);
            }
            $row['ms'] = intdiv(hrtime(true) - $started, 1_000_000);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return list<string>
     *
     * @throws AiException
     */
    private function listAll(AiConfig $ai): array
    {
        if ($ai->endpoint === '') {
            throw new AiException('not_configured', 'This server has no address yet — fill it in and save');
        }
        $this->egress->assertAllowed($ai->endpoint, $ai->externalAck);

        return (new OpenAiCompatibleProvider($ai, $this->egress))->models();
    }

    /** No report text went out, so the server's own words are safe to show the owner */
    private static function said(AiException $e): string
    {
        return $e->getMessage() . ($e->detail !== '' ? ' (' . $e->detail . ')' : '');
    }
}
