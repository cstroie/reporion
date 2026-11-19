<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Controller\SettingsPanels;
use Reporion\Support\AdminTabs;

/**
 * The admin tabs' registry: every task and every settings section has
 * exactly one tab, and every tab it names exists.
 */
final class AdminTabsTest extends TestCase
{
    public function testEveryTaskAndSectionNamesARealTab(): void
    {
        foreach (AdminTabs::TASK_TAB as $task => $tab) {
            self::assertArrayHasKey($tab, AdminTabs::TABS, $task);
        }
        foreach (SettingsPanels::SECTION_TAB as $section => $tab) {
            self::assertArrayHasKey($tab, AdminTabs::TABS, $section);
        }
        self::assertSame(array_keys(SettingsPanels::SECTIONS), array_keys(array_intersect_key(SettingsPanels::SECTIONS, SettingsPanels::SECTION_TAB)), 'every section is shown somewhere');
        self::assertSame([], array_diff_key(SettingsPanels::SECTION_TAB, SettingsPanels::SECTIONS), 'no tab for a section that does not exist');
    }

    public function testAKeyIsSavedOnlyOnce(): void
    {
        $keys = array_merge(...array_values(SettingsPanels::SECTIONS));
        self::assertSame($keys, array_values(array_unique($keys)));
    }

    public function testTasksAreSplitByTabAndAPluginTaskIsOnData(): void
    {
        $tasks = ['index:verify' => 1, 'library:digest' => 2, 'pages:tag' => 3, 'pacs:link' => 4, 'index:vectors' => 5];
        self::assertSame(['index:verify' => 1, 'pacs:link' => 4], AdminTabs::tasksFor($tasks, 'data'));
        self::assertSame(['library:digest' => 2], AdminTabs::tasksFor($tasks, 'library'));
        self::assertSame(['pages:tag' => 3], AdminTabs::tasksFor($tasks, 'tags'));
        self::assertSame(['index:vectors' => 5], AdminTabs::tasksFor($tasks, 'ai'));
        self::assertSame('/admin/data', AdminTabs::taskRoute('pacs:link'));
    }

    public function testASubTabBelongsToItsParent(): void
    {
        self::assertSame('people', AdminTabs::parentOf('users'));
        self::assertSame('people', AdminTabs::parentOf('groups'));
        self::assertSame('data', AdminTabs::parentOf('data'));
        self::assertSame('/admin/users', AdminTabs::route('people'));
        self::assertSame('', AdminTabs::route('nosuch'));
    }
}
