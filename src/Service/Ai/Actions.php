<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use Reporion\Auth\User;
use Reporion\Index\IndexInterface;
use Reporion\Storage\StorageInterface;
use Reporion\Support\MetaText;
use Throwable;

/**
 * The assistant's actions for a page (roadmap phase 15b): the pages under
 * `ai:profiles:{profile}` for the profile the page's namespace uses
 * (`ai.profiles`), each with its rail details in its frontmatter — label,
 * tooltip, icon, result (show|append|replace|insert), order, enabled — and
 * its prompt as its body. `…:system` is the profile's system prompt
 * (`ai:profiles:default:system` when the profile has none), and
 * `…:system:{id}` an action's own appendage (DokuLLM's layout).
 *
 * Prompts are the instance's configuration, like its settings: they are
 * read whatever the caller's grants (an editor under reports: need not
 * read ai:); who may *change* them is the ordinary page rule.
 */
final class Actions
{
    private const SYSTEM = 'system';

    private readonly User $instance;

    public function __construct(
        private readonly AiConfig $config,
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
    ) {
        $this->instance = new User('instance', '', true, [], true, '', '');
    }

    /** @return list<Action> the enabled actions for a page, in their order */
    public function forPage(string $path): array
    {
        $profile = $this->config->profileFor($path);
        if (!$this->config->isConfigured() || $profile === null) {
            return [];
        }
        $ns = 'ai:profiles:' . $profile;
        $system = $this->body($ns . ':' . self::SYSTEM) ?? $this->body('ai:profiles:default:' . self::SYSTEM) ?? '';
        $actions = [];
        foreach ($this->index->listNamespace($ns, $this->instance) as $row) {
            $path = (string) $row['path'];
            $id = substr($path, \strlen($ns) + 1);
            if ($id === self::SYSTEM) {
                continue;
            }
            try {
                $page = $this->storage->read($path);
            } catch (Throwable) {
                continue;
            }
            $fm = $page->frontmatter;
            if (($fm['enabled'] ?? true) === false || trim($page->body) === '') {
                continue;
            }
            $result = MetaText::text($fm['result'] ?? null);
            $own = $this->body($ns . ':' . self::SYSTEM . ':' . $id);
            $actions[] = new Action(
                $id,
                MetaText::text($fm['label'] ?? null) ?: (MetaText::text($fm['title'] ?? null) ?: $id),
                MetaText::text($fm['tooltip'] ?? null),
                MetaText::text($fm['icon'] ?? null),
                \in_array($result, Action::RESULTS, true) ? $result : 'show',
                is_numeric($fm['order'] ?? null) ? (int) $fm['order'] : 100,
                $page->body,
                trim($system . ($own !== null ? "\n" . $own : '')),
            );
        }
        usort($actions, static fn (Action $a, Action $b): int => [$a->order, $a->label] <=> [$b->order, $b->label]);

        return $actions;
    }

    /** One action for a page, or null */
    public function find(string $path, string $id): ?Action
    {
        foreach ($this->forPage($path) as $action) {
            if ($action->id === $id) {
                return $action;
            }
        }

        return null;
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
