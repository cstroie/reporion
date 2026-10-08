<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Service\Ai\AiConfig;
use Reporion\Service\Ai\Check;

/**
 * bin/reporion ai:check [--json]
 *
 * The AI assistant's settings as this instance reads them (phase 15), the
 * egress verdict for its server, and the models the server offers
 * (Service\Ai\Check). Never sends report text — only `GET {endpoint}/models`.
 */
final class AiCheckCommand implements CommandInterface
{
    /** @param array<string, mixed> $config the effective config */
    public function __construct(private readonly array $config, private readonly Check $check = new Check())
    {
    }

    public function run(array $args, Output $output): int
    {
        $report = $this->check->run(AiConfig::fromConfig($this->config));
        $exit = $report['ok'] ? 0 : 1;
        unset($report['ok']);

        if (\in_array('--json', $args, true)) {
            $output->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exit;
        }
        $output->line('enabled:  ' . ($report['enabled'] ? 'yes' : 'no'));
        $output->line('endpoint: ' . ($report['endpoint'] !== '' ? $report['endpoint'] : '—') . ($report['external'] === true ? ' (outside this network)' : ''));
        $output->line('model:    ' . ($report['model'] !== '' ? $report['model'] : '—') . ' · api key: ' . $report['api_key']);
        $output->line('aliases:  ' . implode(' · ', array_map(static fn (string $tier, string $model): string => $tier . ' = ' . ($model !== '' ? $model : '—'), array_keys($report['tiers']), $report['tiers'])));
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
