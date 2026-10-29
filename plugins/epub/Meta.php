<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Epub;

use DateTimeInterface;

/**
 * The book's descriptive metadata, from the front page's own front matter:
 * `author` (a name or a list), `summary` (the description), `language`,
 * `publisher`, `rights`, `date` (the publishing year or day: `2026`, `2026-10`,
 * `2026-10-29`), and `tags` (the subjects). A missing or malformed
 * value is left out, never an error.
 */
final class Meta
{
    private const MAX = 500;

    /**
     * @param array<string, mixed> $frontmatter
     * @return array{authors: list<string>, publisher: string, description: string, language: string, rights: string, date: string, subjects: list<string>}
     */
    public static function from(array $frontmatter): array
    {
        $language = self::text($frontmatter['language'] ?? null);
        $date = $frontmatter['date'] ?? null;
        $date = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : self::text($date);

        return [
            'authors' => self::list($frontmatter['author'] ?? null),
            'publisher' => self::text($frontmatter['publisher'] ?? null),
            'description' => self::text($frontmatter['summary'] ?? null),
            'language' => preg_match('#^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$#', $language) === 1 ? $language : '',
            'rights' => self::text($frontmatter['rights'] ?? null),
            'date' => preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', $date) === 1 ? $date : '',
            'subjects' => self::list($frontmatter['tags'] ?? null),
        ];
    }

    private static function text(mixed $value): string
    {
        if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
            return '';
        }
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value));

        return mb_substr($text, 0, self::MAX);
    }

    /** @return list<string> a string, or a list of them (at most 20) */
    private static function list(mixed $value): array
    {
        $items = \is_array($value) ? $value : [$value];
        $out = [];
        foreach ($items as $item) {
            $text = self::text($item);
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return \array_slice(array_values(array_unique($out)), 0, 20);
    }
}
