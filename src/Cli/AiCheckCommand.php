<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Exception\AiException;
use Reporion\Service\Ai\AiConfig;
use Reporion\Service\Ai\EgressGuard;
use Reporion\Service\Ai\OpenAiCompatibleProvider;

/**
 * bin/reporion ai:check [--json]
 *
 * The AI assistant's settings as this instance reads them (phase 15), the
 * egress verdict for its server, and the models the server offers. Never
 * sends report text — only `GET {endpoint}/models`.
 */
final class AiCheckCommand implements CommandInterface
{
    /** @param array<string, mixed> $config the effective config */
    public function __construct(private readonly array $config, private readonly EgressGuard $egress = new EgressGuard())
    {
    }

    public function run(array $args, Output $output): int
    {
        $ai = AiConfig::fromConfig($this->config);
        $report = [
            'enabled' => $ai->enabled,
            'endpoint' => $ai->endpoint,
            'model' => $ai->model,
            'api_key' => $ai->apiKey !== '' ? 'set' : 'none',
            'external' => null,
            'egress' => null,
            'models' => [],
            'error' => null,
        ];
        $exit = 0;
        try {
            if (!$ai->isConfigured()) {
                throw new AiException('not_configured', 'Not configured: enable it and set the server address and model (Admin → Settings → AI)');
            }
            $report['external'] = $this->egress->isExternal($ai->endpoint);
            $this->egress->assertAllowed($ai->endpoint, $ai->allowEgressTo, $ai->externalAck);
            $report['egress'] = 'allowed';
            $report['models'] = (new OpenAiCompatibleProvider($ai, $this->egress))->models();
            if ($report['models'] !== [] && !\in_array($ai->model, $report['models'], true)) {
                $report['error'] = 'The server does not list the model "' . $ai->model . '"';
                $exit = 1;
            }
        } catch (AiException $e) {
            $report['egress'] ??= $e->reason === 'egress_denied' ? 'denied' : null;
            $report['error'] = $e->getMessage();
            $exit = 1;
        }

        if (\in_array('--json', $args, true)) {
            $output->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exit;
        }
        $output->line('enabled:  ' . ($report['enabled'] ? 'yes' : 'no'));
        $output->line('endpoint: ' . ($report['endpoint'] !== '' ? $report['endpoint'] : '—') . ($report['external'] === true ? ' (outside this network)' : ''));
        $output->line('model:    ' . ($report['model'] !== '' ? $report['model'] : '—') . ' · api key: ' . $report['api_key']);
        if ($report['egress'] !== null) {
            $output->line('egress:   ' . $report['egress']);
        }
        if ($report['models'] !== []) {
            $output->line('models:   ' . implode(', ', $report['models']));
        }
        if ($report['error'] !== null) {
            $output->error($report['error']);
        } else {
            $output->line('ok');
        }

        return $exit;
    }
}
