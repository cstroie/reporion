<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Websearch;

use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * One web search: Brave Search's JSON API or DuckDuckGo's HTML page, as
 * cstroie/tusk's WebSearchTool does. Only the query is sent.
 */
final class Search
{
    public function __construct(
        private readonly Http $http,
        private readonly string $provider,
        private readonly string $braveKey,
    ) {
    }

    /**
     * @return list<array{title: string, url: string, snippet: string}>
     *
     * @throws RuntimeException the engine did not answer, or no key for Brave
     */
    public function search(string $query, int $max): array
    {
        if ($this->provider === 'brave') {
            if ($this->braveKey === '') {
                throw new RuntimeException('no_key');
            }
            [$status, $body] = $this->http->get(
                'https://api.search.brave.com/res/v1/web/search?q=' . rawurlencode($query) . '&count=' . max(1, min($max, 20)),
                ['Accept: application/json', 'X-Subscription-Token: ' . $this->braveKey],
            );
            if ($status !== 200) {
                throw new RuntimeException('HTTP ' . $status);
            }

            return self::parseBrave($body, $max);
        }
        [$status, $body] = $this->http->get('https://html.duckduckgo.com/html/?q=' . rawurlencode($query), ['Accept-Language: en-US,en;q=0.9']);
        if ($status !== 200) {
            throw new RuntimeException('HTTP ' . $status);
        }

        return self::parseDdg($body, $max);
    }

    /** @return list<array{title: string, url: string, snippet: string}> */
    public static function parseBrave(string $json, int $max): array
    {
        $data = json_decode($json, true);
        if (!\is_array($data)) {
            throw new RuntimeException('unreadable answer');
        }
        $results = [];
        foreach (\is_array($data['web']['results'] ?? null) ? $data['web']['results'] : [] as $entry) {
            if (\count($results) >= $max) {
                break;
            }
            if (!\is_array($entry)) {
                continue;
            }
            $title = html_entity_decode(trim(strip_tags((string) ($entry['title'] ?? ''))), ENT_QUOTES | ENT_HTML5);
            $url = trim((string) ($entry['url'] ?? ''));
            $snippet = html_entity_decode(strip_tags((string) ($entry['description'] ?? '')), ENT_QUOTES | ENT_HTML5);
            if ($title !== '' && str_starts_with($url, 'https://')) {
                $results[] = ['title' => $title, 'url' => $url, 'snippet' => trim($snippet)];
            }
        }

        return $results;
    }

    /** @return list<array{title: string, url: string, snippet: string}> */
    public static function parseDdg(string $html, int $max): array
    {
        if (trim($html) === '') {
            return [];
        }
        $dom = new DOMDocument();
        $errors = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($errors);
        $xpath = new DOMXPath($dom);
        $results = [];
        foreach ($xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' result ')]") ?: [] as $node) {
            if (\count($results) >= $max) {
                break;
            }
            $titleNode = $xpath->query(".//a[contains(concat(' ', normalize-space(@class), ' '), ' result__a ')]", $node)?->item(0);
            $snippetNode = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' result__snippet ')]", $node)?->item(0);
            if (!$titleNode instanceof \DOMElement) {
                continue;
            }
            $title = trim($titleNode->textContent);
            $url = self::ddgUrl(trim($titleNode->getAttribute('href')));
            if ($title !== '' && str_starts_with($url, 'https://')) {
                $results[] = ['title' => $title, 'url' => $url, 'snippet' => $snippetNode !== null ? trim($snippetNode->textContent) : ''];
            }
        }

        return $results;
    }

    /** DuckDuckGo wraps a result's link as (//duckduckgo.com)/l/?uddg=<target> */
    private static function ddgUrl(string $href): string
    {
        if (str_starts_with((string) parse_url($href, PHP_URL_PATH), '/l/')) {
            parse_str((string) parse_url($href, PHP_URL_QUERY), $params);
            if (\is_string($params['uddg'] ?? null)) {
                return $params['uddg'];
            }
        }

        return str_starts_with($href, '//') ? 'https:' . $href : $href;
    }
}
