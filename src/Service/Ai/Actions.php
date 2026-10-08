<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use Reporion\Auth\User;
use Reporion\Index\IndexInterface;
use Reporion\Storage\StorageInterface;
use Reporion\Support\MetaText;
use Reporion\Support\ProfileTable;
use Throwable;

/**
 * The assistant's actions for a page (roadmap phase 15b; row-sourced since
 * 2026-09-28): the prompt profile in use (`ai.prompt_profile`), on the
 * namespaces it serves (`ai.namespaces`) — its own page's (`ai:profiles:{profile}`)
 * first markdown table (`Support\ProfileTable`) says which actions exist,
 * their order, and their rail details (label, tooltip, icon, result); each
 * row's id names a page `ai:profiles:{profile}:{id}` whose body is the
 * prompt. A row with no such page, or an empty one, contributes nothing —
 * same as a row simply not being in the table. `…:system` is the profile's
 * system prompt (`ai:profiles:default:system` when the profile has none),
 * and `…:system:{id}` an action's own appendage (DokuLLM's layout). The
 * model alias is the row's Model cell, else the prompt page's frontmatter
 * `model:` — the only way to set one for a reserved prompt kept out of the
 * rail (2026-10-08). Pages outside the profile's namespaces use the
 * fallback profile, if one is set (`ai.fallback_profile`).
 *
 * Prompts are the instance's configuration, like its settings: they are
 * read whatever the caller's grants (an editor under reports: need not
 * read ai:); who may *change* them is the ordinary page rule.
 */
final class Actions
{
    /** The namespace prompt profiles live under (`self::NS . ':{profile}'`) */
    public const NS = 'ai:profiles';

    private const SYSTEM = 'system';

    /**
     * Reserved prompt ids (2026-10-07): their page under the profile turns on
     * a feature outside the editor rail — `summary` a Summarize button in the
     * report's metadata panel, `tags` a Suggest tags button beside it
     * (2026-10-08), `evolution` the patient timeline's AI panel —
     * whether or not the table also lists them for the rail. No page, no
     * button.
     */
    public const SPECIAL = ['summary', 'tags', 'evolution', 'presign'];


    private const DEFAULT_PROFILE = 'default';

    private readonly User $instance;

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
        $ns = self::NS . ':' . $profile;
        $index = $this->body($ns);
        if ($index === null) {
            return [];
        }
        $system = $this->system($ns);
        $actions = [];
        // A `---` row: a line before the next action there is — none at the
        // top, none twice, none at the end
        $break = false;
        foreach (ProfileTable::parse($index) as $row) {
            $id = $row['id'];
            if ($id === ProfileTable::BREAK) {
                $break = $actions !== [];
                continue;
            }
            if ($id === self::SYSTEM) {
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
                $break,
            );
            $break = false;
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
            } catch (Throwable) {
                continue;
            }
            $id = substr($path, \strlen($ns) + 1);
            $pages[] = [
                'id' => $id,
                'label' => MetaText::text($fm['label'] ?? null) ?: (MetaText::text($fm['title'] ?? null) ?: $id),
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
     * its table page; the rail's rows in order — `---` sections as breaks,
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
                $rail[] = ['break' => true];
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
            $named[$id] = true;
            $reserved[] = ['id' => $id, 'path' => $ns . ':' . $id, 'present' => $this->body($ns . ':' . $id) !== null];
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

    /** One action for a page — a rail action or a special one — or null */
    public function find(string $path, string $id): ?Action
    {
        foreach ($this->forPage($path) as $action) {
            if ($action->id === $id) {
                return $action;
            }
        }

        return $this->special($path, $id);
    }

    /**
     * A reserved action (self::SPECIAL) for a page, when the profile serving
     * it has its prompt page: the table's row for it, if the owner listed it
     * in the rail too (its label and Model cell), else the bare prompt on its
     * page's `model:` (normal when none); null when the feature is off.
     */
    public function special(string $path, string $id): ?Action
    {
        if (!\in_array($id, self::SPECIAL, true)) {
            return null;
        }
        foreach ($this->forPage($path) as $action) {
            if ($action->id === $id) {
                return $action;
            }
        }
        $profile = $this->config->profileFor($path);
        if (!$this->config->isConfigured() || $profile === null) {
            return null;
        }
        $ns = self::NS . ':' . $profile;
        $prompt = $this->body($ns . ':' . $id);
        if ($prompt === null) {
            return null;
        }

        $own = $this->pageSettings($ns . ':' . $id);
        $model = AiConfig::parseModel($own['model']);

        return new Action($id, $id, '', '', 'show', $prompt, $this->systemFor($ns, $this->system($ns), $id), $model['tier'], $model['server'], $own['max_tokens']);
    }

    /**
     * A prompt page's own `model:` (`lite`, `2:expert`, …; '' when it names
     * none) and `max_tokens:` (0 when it sets none: the server's own cap —
     * no built-in one, since a reasoning model spends a small cap on its
     * thinking and answers nothing, 2026-10-08)
     *
     * @return array{model: string, max_tokens: int}
     */
    private function pageSettings(string $path): array
    {
        try {
            $fm = $this->storage->read($path)->frontmatter;
        } catch (Throwable) {
            $fm = [];
        }

        return [
            'model' => MetaText::text($fm['model'] ?? null),
            'max_tokens' => is_numeric($fm['max_tokens'] ?? null) && (int) $fm['max_tokens'] > 0 ? (int) $fm['max_tokens'] : 0,
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
        if ($this->index->findByPath($path, $this->instance) === null) {
            return null;
        }
        try {
            $body = trim($this->storage->read($path)->body);
        } catch (Throwable) {
            return null;
        }

        return $body !== '' ? $body : null;
    }
}
