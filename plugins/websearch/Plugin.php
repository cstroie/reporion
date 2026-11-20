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

    /** @var list<string> */
    private array $prefer = [];

    /** Results asked of the engine, so preferred sites further down can come first */
    private const POOL = 10;

    public function register(Hooks $hooks, Container $container): void
    {
        $settings = $container->settings();
        $http = new Http((int) ($settings['timeout'] ?? 8), self::$transport);
        $this->search = new Search($http, strtolower((string) ($settings['provider'] ?? 'ddg')), (string) ($settings['brave_key'] ?? ''));
        $this->fetch = new Fetch($http);
        $this->max = (int) ($settings['max_results'] ?? 5);
        $this->pages = (int) ($settings['fetch_pages'] ?? 3);
        $this->prefer = array_values(array_filter(array_map(static fn (mixed $d): string => strtolower(trim((string) $d, " .\t")), (array) ($settings['prefer_domains'] ?? [])), static fn (string $d): bool => $d !== ''));
        $hooks->on('ask.sources', $this->askSources(...));
    }

    /** @return list<array<string, mixed>> */
    public function askSources(string $question, User $principal, ?string $web = null): array
    {
        if ($web === null || trim($web) === '') {
            return [];
        }
        try {
            $results = \array_slice(self::preferred($this->search->search($web, max($this->max, $this->prefer !== [] ? self::POOL : 0)), $this->prefer), 0, $this->max);
        } catch (RuntimeException $e) {
            return [['notice' => $e->getMessage() === 'no_key' ? t('websearch.no_key') : t('websearch.failed', [$e->getMessage()])]];
        }
        if ($results === []) {
            return [['notice' => t('websearch.none', [$web])]];
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

    /**
     * $results with those on a preferred domain (or a subdomain of one) first, each group in
     * the engine's order
     *
     * @param list<array{title: string, url: string, snippet: string}> $results
     * @param list<string> $domains
     *
     * @return list<array{title: string, url: string, snippet: string}>
     */
    public static function preferred(array $results, array $domains): array
    {
        $first = $rest = [];
        foreach ($results as $result) {
            $host = strtolower((string) parse_url($result['url'], PHP_URL_HOST));
            $hit = false;
            foreach ($domains as $domain) {
                if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                    $hit = true;
                    break;
                }
            }
            if ($hit) {
                $first[] = $result;
            } else {
                $rest[] = $result;
            }
        }

        return [...$first, ...$rest];
    }
}
