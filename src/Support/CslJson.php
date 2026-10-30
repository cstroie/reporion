<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * CSL-JSON, the record format itself (Zotero, Mendeley, pandoc): a list of items, one item or
 * {items: [...]}. The item's `id` is the key. An entry is {key, csl, tags}. Pure.
 */
final class CslJson
{
    /** @return list<array{key: string, csl: array<string, mixed>, tags: list<string>}> */
    public static function parse(string $text): array
    {
        $data = json_decode((string) preg_replace('/^\xEF\xBB\xBF/', '', $text), true);
        if (!\is_array($data)) {
            return [];
        }
        $items = isset($data['items']) && \is_array($data['items']) ? $data['items'] : (array_is_list($data) ? $data : [$data]);
        $entries = [];
        foreach ($items as $item) {
            if (!\is_array($item) || !\is_string($item['title'] ?? null)) {
                continue;
            }
            $key = \is_string($item['id'] ?? null) ? $item['id'] : (\is_string($item['citation-key'] ?? null) ? $item['citation-key'] : '');
            $tags = \is_string($item['keyword'] ?? null) ? array_values(array_filter(array_map('trim', preg_split('/[;,]/', $item['keyword']) ?: []))) : [];
            if (!isset($item['issued']['date-parts']) && \is_array($item['issued'] ?? null) && \is_string($item['issued']['raw'] ?? null) && preg_match('/\b(1[5-9]\d\d|20\d\d)\b/', $item['issued']['raw'], $y) === 1) {
                $item['issued'] = ['date-parts' => [[(int) $y[1]]]];
            }
            $entries[] = ['key' => $key, 'csl' => array_diff_key($item, ['id' => 1, 'citation-key' => 1, 'keyword' => 1]), 'tags' => $tags];
        }

        return $entries;
    }

    /** @param list<array{key: string, csl: array<string, mixed>, tags?: list<string>}> $entries */
    public static function write(array $entries): string
    {
        $items = array_map(static fn (array $e): array => ['id' => $e['key']] + $e['csl'] + (($e['tags'] ?? []) !== [] ? ['keyword' => implode(', ', $e['tags'])] : []), $entries);

        return (string) json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
