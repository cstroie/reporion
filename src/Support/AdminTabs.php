<?php

/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Reporion\Support;

/**
 * The admin screens' tabs and which tab each maintenance task belongs to
 * (docs/design-rules.md: an admin tab owns its settings and its tasks; Data
 * holds what is about storage). templates/admin-tabs.php draws the row from
 * it; a task run comes back to the tab whose card started it.
 */
final class AdminTabs
{
    /** Tab key => [route, lang key], in display order */
    public const TABS = [
        'people' => ['/admin/users', 'admin.people.title'],
        'general' => ['/admin/settings', 'admin.general.title'],
        'reports' => ['/admin/reports', 'admin.reports.title'],
        'library' => ['/admin/library', 'admin.library.title'],
        'ai' => ['/admin/ai', 'admin.ai.title'],
        'tags' => ['/admin/tags', 'admin.tags.title'],
        'data' => ['/admin/data', 'admin.data.title'],
        'integrations' => ['/admin/integrations', 'admin.integrations.title'],
    ];

    /** A tab's own row of screens: sub key => [route, lang key] */
    public const SUBTABS = [
        'people' => [
            'users' => ['/admin/users', 'admin.users.title'],
            'groups' => ['/admin/groups', 'admin.groups.title'],
        ],
    ];

    /** Maintenance task => the tab that shows its card; a task not named here (a plugin's) is on Data */
    public const TASK_TAB = [
        'journal:replay' => 'data',
        'index:verify' => 'data',
        'integrity:verify' => 'data',
        'pages:repair-paths' => 'data',
        'trash:purge' => 'data',
        'backup:create' => 'data',
        'backup:rsync' => 'data',
        'pages:summarize' => 'reports',
        'pages:apply-patient-csv' => 'reports',
        'library:abstracts' => 'library',
        'library:digest' => 'library',
        'library:notices' => 'library',
        'index:vectors' => 'ai',
        'ai:prompts-relocate' => 'ai',
        'pages:tag' => 'tags',
        'tags:prune' => 'tags',
        'tags:review' => 'tags',
    ];

    /** The top tab a screen key belongs to: itself, or the tab whose sub-row names it */
    public static function parentOf(string $key): string
    {
        foreach (self::SUBTABS as $tab => $subs) {
            if (isset($subs[$key])) {
                return $tab;
            }
        }

        return $key;
    }

    public static function taskTab(string $task): string
    {
        return self::TASK_TAB[$task] ?? 'data';
    }

    /** The route of a tab ('' for an unknown key) */
    public static function route(string $tab): string
    {
        return self::TABS[$tab][0] ?? '';
    }

    /** The route a task's run comes back to */
    public static function taskRoute(string $task): string
    {
        return self::route(self::taskTab($task));
    }

    /**
     * The tasks whose cards a tab shows, in the runner's order.
     *
     * @template T
     *
     * @param array<string, T> $tasks
     *
     * @return array<string, T>
     */
    public static function tasksFor(array $tasks, string $tab): array
    {
        return array_filter($tasks, static fn (string $name): bool => self::taskTab($name) === $tab, ARRAY_FILTER_USE_KEY);
    }
}
