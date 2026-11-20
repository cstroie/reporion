<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Websearch;

use Closure;
use Reporion\Auth\User;
use Reporion\Plugin\Container;
use Reporion\Plugin\Hooks;
use Reporion\Plugin\PluginInterface;
use RuntimeException;

/**
 * Ask the archive on the web (D67): hook `ask.sources`, its third argument
 * the web query the `ask-expand` prompt wrote (`web:` line) and AskController
 * checked for identifiers. No query: nothing (the question itself is never
 * sent). The engine's results (Brave or DuckDuckGo), the top `fetch_pages`
 * read for their best passage, else the snippet; each a passage with its URL.
 */
final class Plugin implements PluginInterface
{
    /** For tests: replaces the HTTP transport (see Http) */
    public static ?Closure $transport = null;

    private Search $search;
    private Fetch $fetch;
    private int $max = 5;
    private int $pages = 3;

    public function register(Hooks $hooks, Container $container): void
    {
        $settings = $container->settings();
        $http = new Http((int) ($settings['timeout'] ?? 8), self::$transport);
        $this->search = new Search($http, strtolower((string) ($settings['provider'] ?? 'ddg')), (string) ($settings['brave_key'] ?? ''));
        $this->fetch = new Fetch($http);
        $this->max = (int) ($settings['max_results'] ?? 5);
        $this->pages = (int) ($settings['fetch_pages'] ?? 3);
        $hooks->on('ask.sources', $this->askSources(...));
    }

    /** @return list<array<string, mixed>> */
    public function askSources(string $question, User $principal, ?string $web = null): array
    {
        if ($web === null || trim($web) === '') {
            return [];
        }
        try {
            $results = $this->search->search($web, $this->max);
        } catch (RuntimeException $e) {
            return [['notice' => $e->getMessage() === 'no_key' ? t('websearch.no_key') : t('websearch.failed', [$e->getMessage()])]];
        }
        $out = [];
        foreach ($results as $i => $result) {
            $text = $i < $this->pages ? $this->fetch->passage($result['url'], $web) : '';
            $text = $text !== '' ? $text : $result['snippet'];
            if ($text === '') {
                continue;
            }
            $out[] = [
                'text' => $text,
                'date' => null,
                'label' => t('websearch.label'),
                'heading' => mb_substr($result['title'], 0, 200),
                'url' => $result['url'],
                'names' => [],
                'cnp' => '',
            ];
        }

        return $out;
    }
}
