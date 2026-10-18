<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * tags:review's proposals (2026-10-15): the assistant's answer read into
 * them, and the rule's and the assistant's put together. A proposal is
 * never applied by itself: the owner ticks it in Admin → Tags.
 *
 * The `tags-review` prompt answers one JSON object per line (a JSON array
 * is read too): `{"op": "merge|generalise|rename|drop", "from": [..],
 * "to": "..", "reason": ".."}`. A model slips, so each one is checked:
 * every `from` a tag in use (never `rads:…`), `to` a tag as TagList makes
 * them (lowercase, at most MAX_WORDS words), a `drop` never of a tag of
 * the dictionary (curated) or of an exam type (`irm`, `ct genunchi`),
 * `normal` and its spellings only ever merged into `normal` itself
 * (Similar reports leaves those reports out), and nothing the owner has said "never suggest"
 * to (TagDictionary::rejected()). What does not pass is left out.
 *
 * @phpstan-import-type Proposal from TagNormalize
 */
final class TagProposals
{
    public const OPS = ['merge', 'generalise', 'rename', 'drop'];

    /**
     * @param array<string, int> $counts   tag → pages, the tags in use
     * @param list<string>       $curated  the dictionary's tags: never dropped
     * @param list<string>       $rejected keys (self::key()) the owner said never to suggest
     *
     * @return list<Proposal>
     */
    public static function parse(string $answer, array $counts, array $curated = [], array $rejected = []): array
    {
        $text = trim((string) preg_replace('/^```[a-z]*\s*$|^```\s*$/mi', '', $answer));
        $decoded = json_decode($text, true);
        $objects = \is_array($decoded) && array_is_list($decoded) ? $decoded : [];
        if ($objects === []) {
            foreach (preg_split('/\R/u', $text) ?: [] as $line) {
                $line = trim(rtrim(trim($line), ','));
                if (str_starts_with($line, '{') && \is_array($one = json_decode($line, true))) {
                    $objects[] = $one;
                }
            }
        }

        $proposals = [];
        foreach ($objects as $object) {
            $proposal = \is_array($object) ? self::valid($object, $counts, $curated) : null;
            if ($proposal !== null && !\in_array(self::key($proposal), $rejected, true)) {
                $proposals[] = $proposal;
            }
        }

        return $proposals;
    }

    /**
     * The rule's proposals, then the assistant's that touch no tag the
     * rule's already do (one tag, one proposal: two would fight)
     *
     * @param list<Proposal> $rule
     * @param list<Proposal> $ai
     *
     * @return list<Proposal>
     */
    public static function combine(array $rule, array $ai): array
    {
        $taken = [];
        $out = [];
        foreach ([...$rule, ...$ai] as $proposal) {
            $tags = [...$proposal['from'], ...($proposal['to'] !== '' ? [$proposal['to']] : [])];
            if (array_intersect($proposal['from'], array_keys($taken)) !== []) {
                continue;
            }
            foreach ($tags as $tag) {
                $taken[$tag] = true;
            }
            $out[] = $proposal;
        }

        return $out;
    }

    /**
     * What a "never suggest" remembers of a proposal: its tags, not its reason
     *
     * @param array{op: string, from: list<string>, to: string} $proposal
     */
    public static function key(array $proposal): string
    {
        $from = $proposal['from'];
        sort($from);

        return rtrim(implode(', ', $from) . ' → ' . ($proposal['op'] === 'drop' ? '' : $proposal['to']));
    }

    /**
     * @param array<mixed>       $object
     * @param array<string, int> $counts
     * @param list<string>       $curated
     *
     * @return ?Proposal
     */
    private static function valid(array $object, array $counts, array $curated): ?array
    {
        $op = \is_string($object['op'] ?? null) ? strtolower(trim($object['op'])) : '';
        $op = $op === 'generalize' ? 'generalise' : $op;
        if (!\in_array($op, self::OPS, true)) {
            return null;
        }
        $from = [];
        foreach ((array) ($object['from'] ?? []) as $tag) {
            if (!\is_string($tag) || !isset($counts[$tag]) || str_starts_with($tag, Rads::PREFIX)) {
                return null;
            }
            $from[$tag] = true;
        }
        $from = array_keys($from);
        if ($from === []) {
            return null;
        }
        $reason = mb_substr(trim(\is_string($object['reason'] ?? null) ? $object['reason'] : ''), 0, 120);
        if ($op === 'drop') {
            if (array_intersect($from, $curated) !== [] || array_filter($from, TagList::isExamType(...)) !== [] || array_filter($from, self::isNormal(...)) !== []) {
                return null;
            }

            return ['op' => 'drop', 'from' => $from, 'to' => '', 'reason' => $reason, 'source' => 'ai'];
        }
        $to = self::tag(\is_string($object['to'] ?? null) ? $object['to'] : '');
        $from = array_values(array_diff($from, [$to]));
        if ($to === '' || $from === [] || str_starts_with($to, Rads::PREFIX) || ($op === 'rename' && \count($from) !== 1)) {
            return null;
        }
        // `normal` keeps its meaning: its spellings may become it, it becomes nothing else
        if ($to !== TagList::NORMAL && array_filter($from, self::isNormal(...)) !== []) {
            return null;
        }
        sort($from);

        return ['op' => $op, 'from' => $from, 'to' => $to, 'reason' => $reason, 'source' => 'ai'];
    }

    /** `normal`, or a spelling of it (`Normală`, `normal.`) */
    public static function isNormal(string $tag): bool
    {
        return preg_match('/^normal[aăe]?$/u', Slug::fold($tag)) === 1;
    }

    /** A proposed tag as TagList writes one; '' when it is no tag */
    public static function tag(string $tag): string
    {
        $tag = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $tag), " \t\"'„“”«»`.!:"));
        if ($tag === '' || mb_strlen($tag) > 64 || \count(explode(' ', $tag)) > TagList::MAX_WORDS || preg_match('/[,\[\]{}\x00-\x1F\x7F"]/u', $tag) === 1) {
            return '';
        }

        return $tag;
    }
}
