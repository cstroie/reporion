<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use Reporion\Auth\User;
use Reporion\Index\IndexInterface;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\MetaText;
use Reporion\Support\PageKind;
use Reporion\Support\ProfileTable;
use RuntimeException;

/**
 * The assistant's actions for a page (roadmap phase 15b; row-sourced since
 * 2026-09-28): the prompt profile in use (`ai.prompt_profile`), on the
 * namespaces it serves (`ai.namespaces`): its own page's (`ai:profiles:{profile}`)
 * first markdown table (`Support\ProfileTable`) says which actions exist,
 * their order, and their rail details (label, tooltip, icon, result); each
 * row's id names a page `ai:profiles:{profile}:{id}` whose body is the
 * prompt. A row with no such page, or an empty one, contributes nothing
 * same as a row simply not being in the table. `…:system` is the profile's
 * system prompt (`ai:profiles:default:system` when the profile has none),
 * and `…:system:{id}` an action's own appendage (DokuLLM's layout). The
 * model alias is the row's Model cell, else the prompt page's frontmatter
 * `model:`. The reserved prompts (self::SPECIAL) live apart, under
 * `ai:profiles:{profile}:special:{id}` (2026-10-14): never rail actions,
 * their model is the page's `model:`, and they run with no system prompt. Pages outside the profile's namespaces use the
 * fallback profile, if one is set (`ai.fallback_profile`).
 *
 * Prompts are the instance's configuration, like its settings: they are
 * read whatever the caller's grants (an editor under reports: need not
 * read ai:); who may *change* them is the ordinary page rule.
 *
 * Each prompt page is read once per object, and each profile's actions are
 * built once: a page view asks special() twice, and a maintenance run asks
 * it once per report, all for the same few pages. The object lives for one
 * request or one CLI run, so a prompt edited meanwhile applies from the next.
 */
final class Actions
{
    /** The namespace prompt profiles live under (`self::NS . ':{profile}'`) */
    public const NS = PageKind::PROMPTS_NS;

    private const SYSTEM = 'system';

    /** The subnamespace of a profile holding the reserved prompts: `ai:profiles:{profile}:special:{id}` */
    public const SPECIAL_NS = 'special';

    /**
     * Reserved prompt ids (2026-10-07): their page under the profile's
     * `special:` turns on
     * a feature outside the editor rail:`summary` a Summarize button in the
     * report's metadata panel, `tags` a Suggest tags button beside it
     * (2026-10-08), `evolution` the patient timeline's AI panel, `commit`
     * the revision note a Save writes when *What changed?* is left empty
     * (2026-10-10, Service\Ai\CommitNote), `tags-review` tags:review's
     * proposals for the tag vocabulary (2026-10-15, the profile serving
     * `reports`), `recall-expand` and `recall-missing` the editor's Recall
     * buttons (D50, 2026-10-18: one line, with {recall}; named so that no
     * rail action already called `expand` is taken), `ask-archive` the
     * *Ask the archive* screen (D53, 2026-10-19, the profile serving
     * `reports`: a question and its sources), `ask-expand` its first step
     * (D53, 2026-10-19: the question made standalone from the earlier turns,
     * and the words a report would use for it; lite). No page, no button.
     * These ids and `special` itself cannot be rail actions.
     */
    /** The library's (docs/library.md, phases 5 and 6): one analysis of a source each, the condensing of one part of a long source, Ask the notebook and its search words, and Studio's texts of a notebook */
    public const LIBRARY = ['source-part', 'source-points', 'source-definitions', 'source-classification', 'source-design', 'source-limitations', 'source-questions', 'ask-notebook', 'notebook-terms', 'studio-overview', 'studio-glossary', 'studio-faq', 'studio-timeline', 'studio-compare', 'studio-check', 'studio-template'];

    public const SPECIAL = ['summary', 'tags', 'evolution', 'presign', 'commit', 'tags-review', 'recall-expand', 'recall-missing', 'ask-archive', 'ask-expand', ...self::LIBRARY];

    /**
     * What `commit` runs on when its page says nothing: a Save waits for it,
     * so the smallest alias and a short wait (2026-10-10)
     */
    private const COMMIT_TIER = 'lite';
    private const COMMIT_TIMEOUT = 10;


    private const DEFAULT_PROFILE = 'default';

    private readonly User $instance;

    /** @var array<string, ?PageRecord> prompt pages read so far, by path (null: none, or not indexed) */
    private array $pages = [];

    /** @var array<string, list<Action>> forPage()'s actions, by profile */
    private array $byProfile = [];

    public function __construct(
        private readonly AiConfig $config,
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
    ) {
        $this->instance = new User('instance', '', true, [], true, '', '');
    }

    /** @return list<Action> the actions for a page, in the profile's own table order */
    public function forPage(string $path): array
    {
        $profile = $this->config->profileFor($path);
        if (!$this->config->isConfigured() || $profile === null) {
            return [];
        }

        return $this->byProfile[$profile] ??= $this->build($profile);
    }

    /** @return list<Action> a profile's rail actions, in its table's order */
    private function build(string $profile): array
    {
        $ns = self::NS . ':' . $profile;
        $index = $this->body($ns);
        if ($index === null) {
            return [];
        }
        $system = $this->system($ns);
        $actions = [];
        // A `---` row: a line before the next action there is: none at the
        // top, none twice, none at the end. One with a Label names the section
        // instead (a heading, also at the top); the last label before an action wins
        $break = null;
        foreach (ProfileTable::parse($index) as $row) {
            $id = $row['id'];
            if ($id === ProfileTable::BREAK) {
                if ($row['label'] !== '') {
                    $break = [$row['label'], $row['icon']];
                } elseif ($actions !== [] && $break === null) {
                    $break = ['', ''];
                }
                continue;
            }
            if ($id === self::SYSTEM || $id === self::SPECIAL_NS || \in_array($id, self::SPECIAL, true)) {
                continue;
            }
            $prompt = $this->body($ns . ':' . $id);
            if ($prompt === null) {
                continue;
            }
            $result = strtolower($row['result']);
            // The table's Model cell, else the prompt page's own `model:`
            $own = $this->pageSettings($ns . ':' . $id);
            $model = AiConfig::parseModel($row['model'] !== '' ? $row['model'] : $own['model']);
            $actions[] = new Action(
                $id,
                $row['label'] !== '' ? $row['label'] : $id,
                $row['tooltip'],
                $row['icon'],
                \in_array($result, Action::RESULTS, true) ? $result : 'show',
                $prompt,
                $this->systemFor($ns, $system, $id),
                $model['tier'],
                $model['server'],
                $own['max_tokens'],
                $break !== null,
                $own['timeout'],
                $break[0] ?? '',
                $break[1] ?? '',
            );
            $break = null;
        }

        return $actions;
    }

    /**
     * The prompt profiles there are: each namespace under `ai:profiles`
     * holding pages, for Admin → AI to choose from.
     *
     * @return list<string>
     */
    public function profiles(): array
    {
        return array_values(array_map(static fn (array $row): string => $row['name'], $this->index->listSubnamespaces(self::NS, $this->instance)));
    }

    /**
     * Every page of a profile, disabled ones too, for Admin → AI: id,
     * label, whether it is on, and its path.
     *
     * @return list<array{id: string, label: string, enabled: bool, path: string}>
     */
    public function pages(string $profile): array
    {
        $ns = self::NS . ':' . $profile;
        $pages = [];
        foreach ($this->index->listNamespace($ns, $this->instance) as $row) {
            $path = (string) $row['path'];
            try {
                $fm = $this->storage->read($path)->frontmatter;
            } catch (RuntimeException) {
                continue;
            }
            $id = substr($path, \strlen($ns) + 1);
            $pages[] = [
                'id' => $id,
                'label' => MetaText::text($fm['label'] ?? null) ?: (MetaText::text($fm['title'] ?? null) ?: ((string) ($row['title'] ?? '') ?: $id)),
                'enabled' => ($fm['enabled'] ?? true) !== false,
                'path' => $path,
                'order' => is_numeric($fm['order'] ?? null) ? (int) $fm['order'] : ($id === self::SYSTEM ? 0 : 100),
            ];
        }
        usort($pages, static fn (array $a, array $b): int => [$a['order'], $a['id']] <=> [$b['order'], $b['id']]);

        return array_map(static fn (array $p): array => array_diff_key($p, ['order' => 0]), $pages);
    }

    /**
     * A profile at a glance, for Admin → AI's *Prompts* panel (phase 33e):
     * its table page; the rail's rows in order:`---` sections as breaks,
     * each action with its result, model and whether its prompt page has a
     * body; the reserved prompts present or missing; its system prompt (or
     * `default`'s it falls back to); and the profile's other pages, which
     * nothing reads as an action. Reads the same pages forPage() does.
     *
     * @return array{path: string, table: bool, rail: list<array<string, mixed>>, reserved: list<array{id: string, path: string, present: bool}>, system: array{path: string, present: bool, fallback: bool}, other: list<array{id: string, label: string, path: string}>}
     */
    public function overview(string $profile): array
    {
        $ns = self::NS . ':' . $profile;
        $index = $this->body($ns);
        $rail = [];
        $named = [self::SYSTEM => true];
        foreach ($index !== null ? ProfileTable::parse($index) : [] as $row) {
            if ($row['id'] === ProfileTable::BREAK) {
                $rail[] = ['break' => true, 'label' => $row['label'], 'icon' => $row['icon']];
                continue;
            }
            $named[$row['id']] = true;
            $rail[] = [
                'break' => false,
                'id' => $row['id'],
                'label' => $row['label'] !== '' ? $row['label'] : $row['id'],
                'result' => \in_array(strtolower($row['result']), Action::RESULTS, true) ? strtolower($row['result']) : 'show',
                'model' => $row['model'],
                'path' => $ns . ':' . $row['id'],
                'present' => $this->body($ns . ':' . $row['id']) !== null,
                'ownSystem' => $this->body($ns . ':' . self::SYSTEM . ':' . $row['id']) !== null,
            ];
        }
        $reserved = [];
        foreach (self::SPECIAL as $id) {
            $named[self::SPECIAL_NS . ':' . $id] = true;
            $reserved[] = ['id' => $id, 'path' => $this->specialPath($ns, $id), 'present' => $this->body($this->specialPath($ns, $id)) !== null];
        }
        $systemPresent = $this->body($ns . ':' . self::SYSTEM) !== null;
        $other = [];
        foreach ($this->pages($profile) as $page) {
            if (!isset($named[$page['id']])) {
                $other[] = ['id' => $page['id'], 'label' => $page['label'], 'path' => $page['path']];
            }
        }

        return [
            'path' => $ns,
            'table' => $index !== null,
            'rail' => $rail,
            'reserved' => $reserved,
            'system' => [
                'path' => $systemPresent ? $ns . ':' . self::SYSTEM : self::NS . ':' . self::DEFAULT_PROFILE . ':' . self::SYSTEM,
                'present' => $systemPresent || $this->body(self::NS . ':' . self::DEFAULT_PROFILE . ':' . self::SYSTEM) !== null,
                'fallback' => !$systemPresent,
            ],
            'other' => $other,
        ];
    }

    /** One action for a page: a rail action or a special one, or null */
    public function find(string $path, string $id): ?Action
    {
        if (\in_array($id, self::SPECIAL, true)) {
            return $this->special($path, $id);
        }
        foreach ($this->forPage($path) as $action) {
            if ($action->id === $id) {
                return $action;
            }
        }

        return null;
    }

    /**
     * A reserved action (self::SPECIAL) for a page, when the profile serving
     * it has its prompt page (`{profile}:special:{id}`): the bare prompt on
     * its page's `model:` (normal when none:`commit`: lite, and a 10 s
     * `timeout:` when its page sets none), with no system prompt; null when
     * the feature is off.
     */
    public function special(string $path, string $id): ?Action
    {
        if (!\in_array($id, self::SPECIAL, true)) {
            return null;
        }
        $profile = $this->config->profileFor($path);
        if (!$this->config->isConfigured() || $profile === null) {
            return null;
        }
        $ns = self::NS . ':' . $profile;
        $at = $this->specialPath($ns, $id);
        $prompt = $this->body($at);
        if ($prompt === null) {
            return null;
        }

        $own = $this->pageSettings($at);
        $commit = $id === 'commit';
        // A list of what is missing, and the words to search for, are a small model's work too
        $model = AiConfig::parseModel($own['model'] === '' && ($commit || $id === 'recall-missing' || $id === 'ask-expand') ? self::COMMIT_TIER : $own['model']);
        $timeout = $own['timeout'] === 0 && $commit ? self::COMMIT_TIMEOUT : $own['timeout'];

        return new Action($id, $id, '', '', 'show', $prompt, '', $model['tier'], $model['server'], $own['max_tokens'], timeout: $timeout);
    }

    /**
     * A reserved prompt about no one page (`tags-review`, `ask-archive`):
     * the one of the profile serving $ns, else of the first of its
     * sub-namespaces (`reports:mri`, …) whose profile has it: an instance
     * may map only those (ai.profile_map)
     */
    public function specialUnder(string $ns, string $id): ?Action
    {
        $action = $this->special($ns, $id);
        if ($action !== null) {
            return $action;
        }
        foreach ($this->index->listSubnamespaces($ns, $this->instance) as $row) {
            $action = $this->special($ns . ':' . $row['name'], $id);
            if ($action !== null) {
                return $action;
            }
        }

        return null;
    }

    /** The page of a reserved prompt under a profile's namespace */
    private function specialPath(string $ns, string $id): string
    {
        return $ns . ':' . self::SPECIAL_NS . ':' . $id;
    }

    /**
     * A prompt page's own `model:` (`lite`, `2:expert`, …; '' when it names
     * none) and `max_tokens:` (0 when it sets none: the server's own cap
     * no built-in one, since a reasoning model spends a small cap on its
     * thinking and answers nothing, 2026-10-08) and `timeout:` (seconds,
     * 0 when it sets none: the server's own, 2026-10-10)
     *
     * @return array{model: string, max_tokens: int, timeout: int}
     */
    private function pageSettings(string $path): array
    {
        $fm = $this->page($path)?->frontmatter ?? [];

        return [
            'model' => MetaText::text($fm['model'] ?? null),
            'max_tokens' => is_numeric($fm['max_tokens'] ?? null) && (int) $fm['max_tokens'] > 0 ? (int) $fm['max_tokens'] : 0,
            'timeout' => is_numeric($fm['timeout'] ?? null) && (int) $fm['timeout'] > 0 ? (int) $fm['timeout'] : 0,
        ];
    }

    /** The profile's system prompt, else the default profile's */
    private function system(string $ns): string
    {
        return $this->body($ns . ':' . self::SYSTEM) ?? $this->body(self::NS . ':' . self::DEFAULT_PROFILE . ':' . self::SYSTEM) ?? '';
    }

    /** The system prompt with an action's own appendage (`…:system:{id}`), if any */
    private function systemFor(string $ns, string $system, string $id): string
    {
        $own = $this->body($ns . ':' . self::SYSTEM . ':' . $id);

        return trim($system . ($own !== null ? "\n" . $own : ''));
    }

    private function body(string $path): ?string
    {
        $body = trim($this->page($path)->body ?? '');

        return $body !== '' ? $body : null;
    }

    /** A prompt page, read once: null when the index has no such page or the read fails */
    private function page(string $path): ?PageRecord
    {
        if (!\array_key_exists($path, $this->pages)) {
            $this->pages[$path] = null;
            if ($this->index->findByPath($path, $this->instance) !== null) {
                try {
                    $this->pages[$path] = $this->storage->read($path);
                } catch (RuntimeException) {
                    // Unreadable: no prompt, as before
                }
            }
        }

        return $this->pages[$path];
    }
}
