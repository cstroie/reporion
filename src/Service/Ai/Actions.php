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
 * Since 2026-11-01 (the `research` profile): the library's features
 * (self::LIBRARY) take the profile and server of the library namespace
 * wherever their page is, since a notebook may be any page; a page may name
 * its prompts' profile in its frontmatter (`prompts: research`: the prompts
 * only, never the server); and a prompt page a profile lacks (a rail row's or
 * a reserved one, not the table or the system prompt) is the fallback
 * profile's, when one is set.
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
    /** The library's (docs/library.md, phases 5 and 6): one analysis of a source each, the condensing of one part of a long source, Ask the notebook and its search words, Studio's texts of a notebook, and flashcards (phase 8) */
    public const LIBRARY = ['source-part', 'source-points', 'source-pie','source-definitions', 'source-classification', 'source-design', 'source-limitations', 'source-questions', 'ask-notebook', 'notebook-terms', 'studio-overview', 'studio-glossary', 'studio-faq', 'studio-timeline', 'studio-compare', 'studio-check', 'studio-template', 'source-flashcards', 'studio-flashcards', 'studio-audio'];

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

    /** @var array<string, list<Action>> forPage()'s actions, by profile and server */
    private array $byProfile = [];

    /** @var array<string, ?string> a page's own `prompts:` profile, by path (the value only: a maintenance run asks for every report) */
    private array $pageProfiles = [];

    /** @var ?list<string> profiles(), asked once */
    private ?array $profileNames = null;

    public function __construct(
        private readonly AiConfig $config,
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
        /** Where the library's features take their profile and server from (Kernel::libraryNamespace()) */
        private readonly string $libraryNamespace = 'library',
    ) {
        $this->instance = new User('instance', '', true, [], true, '', '');
    }

    /** @return list<Action> the actions for a page, in the profile's own table order */
    public function forPage(string $path): array
    {
        if (!$this->config->isConfigured()) {
            return [];
        }
        $profile = $this->profileOf($path);
        if ($profile === null) {
            return [];
        }

        // The namespace's server (ai.server_map) for the actions whose Model cell names none
        $server = $this->config->serverFor($path);

        // A server a row or a prompt page names applies only where the namespace's routing chose
        // the profile and the page is its own: a borrowed prompt (`prompts:`, the fallback's page)
        // never moves the page's text to another server
        $routed = $profile === $this->config->profileFor($path);

        return $this->byProfile[$profile . '|' . ($server ?? '') . '|' . (int) $routed] ??= $this->build($profile, $server, $routed);
    }

    /** @return list<Action> a profile's rail actions, in its table's order, on $server unless a row names its own (only when $routed) */
    private function build(string $profile, ?string $server, bool $routed = true): array
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
            // The profile's own prompt page, else the fallback profile's
            $at = $this->promptPage($profile, $id);
            if ($at === null) {
                continue;
            }
            $prompt = (string) $this->body($at);
            $result = strtolower($row['result']);
            // The table's Model cell, else the prompt page's own `model:`
            $own = $this->pageSettings($at);
            $model = AiConfig::parseModel($row['model'] !== '' ? $row['model'] : $own['model']);
            $named = $routed && ($row['model'] !== '' || str_starts_with($at, $ns . ':'));
            $actions[] = new Action(
                $id,
                $row['label'] !== '' ? $row['label'] : $id,
                $row['tooltip'],
                $row['icon'],
                \in_array($result, Action::RESULTS, true) ? $result : 'show',
                $prompt,
                // The appendage of the profile the prompt came from
                $this->systemFor(self::under($at, $id), $system, $id),
                $model['tier'],
                $named ? ($model['server'] ?? $server) : $server,
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
        return $this->profileNames ??= array_values(array_map(static fn (array $row): string => $row['name'], $this->index->listSubnamespaces(self::NS, $this->instance)));
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
     * A prompt page the profile lacks but the fallback profile has counts as
     * present, with `from` naming the fallback.
     *
     * @return array{path: string, table: bool, rail: list<array<string, mixed>>, reserved: list<array{id: string, path: string, present: bool, from: string}>, system: array{path: string, present: bool, fallback: bool}, other: list<array{id: string, label: string, path: string}>}
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
            $at = $this->promptPage($profile, $row['id']);
            $rail[] = [
                'break' => false,
                'id' => $row['id'],
                'label' => $row['label'] !== '' ? $row['label'] : $row['id'],
                'result' => \in_array(strtolower($row['result']), Action::RESULTS, true) ? strtolower($row['result']) : 'show',
                'model' => $row['model'],
                'path' => $at ?? $ns . ':' . $row['id'],
                'present' => $at !== null,
                // The fallback profile's page serves it: that profile's name, else ''
                'from' => $this->fromFallback($ns, $at),
                'ownSystem' => $this->body(($at !== null ? self::under($at, $row['id']) : $ns) . ':' . self::SYSTEM . ':' . $row['id']) !== null,
            ];
        }
        $reserved = [];
        foreach (self::SPECIAL as $id) {
            $named[self::SPECIAL_NS . ':' . $id] = true;
            $at = $this->promptPage($profile, self::SPECIAL_NS . ':' . $id);
            $reserved[] = ['id' => $id, 'path' => $at ?? $this->specialPath($ns, $id), 'present' => $at !== null, 'from' => $this->fromFallback($ns, $at)];
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
        return $this->reserved($path, $id, true);
    }

    /** special(), with or without the fallback profile's page when the profile has none */
    private function reserved(string $path, string $id, bool $fallback): ?Action
    {
        if (!\in_array($id, self::SPECIAL, true) || !$this->config->isConfigured()) {
            return null;
        }
        // The library's features follow the library namespace's profile and server wherever they run
        $library = \in_array($id, self::LIBRARY, true);
        $serving = $library ? $this->libraryNamespace : $path;
        $profile = $library ? $this->config->profileFor($serving) : $this->profileOf($path);
        if ($profile === null) {
            return null;
        }
        $at = $this->promptPage($profile, self::SPECIAL_NS . ':' . $id, $fallback);
        if ($at === null) {
            return null;
        }
        $prompt = (string) $this->body($at);

        $own = $this->pageSettings($at);
        $commit = $id === 'commit';
        // A list of what is missing, and the words to search for, are a small model's work too
        $model = AiConfig::parseModel($own['model'] === '' && ($commit || $id === 'recall-missing' || $id === 'ask-expand') ? self::COMMIT_TIER : $own['model']);
        $timeout = $own['timeout'] === 0 && $commit ? self::COMMIT_TIMEOUT : $own['timeout'];

        // A server the page names only when it is the routed profile's own page: a borrowed one
        // (`prompts:`, the fallback's) runs on the namespace's server
        $named = $profile === $this->config->profileFor($serving) && str_starts_with($at, self::NS . ':' . $profile . ':');

        return new Action($id, $id, '', '', 'show', $prompt, '', $model['tier'], ($named ? $model['server'] : null) ?? $this->config->serverFor($serving), $own['max_tokens'], timeout: $timeout);
    }

    /**
     * A reserved prompt about no one page (`tags-review`, `ask-archive`):
     * the one of the profile serving $ns, else of the first of its
     * sub-namespaces (`reports:mri`, …) whose profile has it: an instance
     * may map only those (ai.profile_map); the fallback profile's page only
     * when none of them has one of its own
     */
    public function specialUnder(string $ns, string $id): ?Action
    {
        $action = $this->reserved($ns, $id, false);
        if ($action !== null) {
            return $action;
        }
        foreach ($this->index->listSubnamespaces($ns, $this->instance) as $row) {
            $action = $this->reserved($ns . ':' . $row['name'], $id, false);
            if ($action !== null) {
                return $action;
            }
        }

        return $this->reserved($ns, $id, true);
    }

    /**
     * The profile whose prompts serve a page: the one its frontmatter names
     * (`prompts:`), when that is a profile there is, else its namespace's
     * (AiConfig::profileFor())
     */
    private function profileOf(string $path): ?string
    {
        if (!\array_key_exists($path, $this->pageProfiles)) {
            $this->pageProfiles[$path] = null;
            // From the index row's frontmatter (meta_json): one lookup, no history read on a page view;
            // no row (a namespace, a page not saved yet): its namespace's
            $row = $this->index->findByPath($path, $this->instance);
            $meta = $row !== null ? json_decode((string) ($row['meta_json'] ?? ''), true) : null;
            $named = \is_array($meta) ? ($meta['prompts'] ?? null) : null;
            if (\is_string($named) && preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $named) === 1
                && ($this->page(self::NS . ':' . $named) !== null || \in_array($named, $this->profiles(), true))) {
                $this->pageProfiles[$path] = $named;
            }
        }

        return $this->pageProfiles[$path] ?? $this->config->profileFor($path);
    }

    /**
     * The page holding a profile's prompt `{rel}` (`grammar`,
     * `special:summary`): its own, else, with $fallback, the fallback
     * profile's when that is another profile; null when neither has text
     */
    private function promptPage(string $profile, string $rel, bool $fallback = true): ?string
    {
        $own = self::NS . ':' . $profile . ':' . $rel;
        if ($this->body($own) !== null) {
            return $own;
        }
        $other = $this->config->fallbackProfile;
        if (!$fallback || $other === '' || $other === $profile) {
            return null;
        }
        $there = self::NS . ':' . $other . ':' . $rel;

        return $this->body($there) !== null ? $there : null;
    }

    /** The namespace a prompt page found for $id sits in (`ai:profiles:{profile}`) */
    private static function under(string $at, string $id): string
    {
        return substr($at, 0, -\strlen(':' . $id));
    }

    /** The fallback profile's name when $at, the prompt page found, is not under $ns, else '' */
    private function fromFallback(string $ns, ?string $at): string
    {
        return $at !== null && !str_starts_with($at, $ns . ':') ? $this->config->fallbackProfile : '';
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
