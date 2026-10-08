<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\ProfileTable;

/**
 * A prompt profile's own page: its first markdown table defines the
 * Assistant rail's actions and their order (TODO idea 12 follow-up,
 * 2026-09-28); any table after it is never reached.
 */
final class ProfileTableTest extends TestCase
{
    public function testParsesTheFirstTablesRowsInOrder(): void
    {
        $body = "# Default profile\n\nSome text.\n\n"
            . "| ID | Label | Tooltip | Icon | Result | Model |\n|---|---|---|---|---|---|\n"
            . "| summarize | Summarize | Create a summary | summary.png | show |\n"
            . "| expand | Expand | Expand the text | expand.png | replace | expert |\n";

        self::assertSame([
            ['id' => 'summarize', 'label' => 'Summarize', 'tooltip' => 'Create a summary', 'icon' => 'summary.png', 'result' => 'show', 'model' => ''],
            ['id' => 'expand', 'label' => 'Expand', 'tooltip' => 'Expand the text', 'icon' => 'expand.png', 'result' => 'replace', 'model' => 'expert'],
        ], ProfileTable::parse($body), 'the Model column is optional');
    }

    public function testASecondTableIsNeverReached(): void
    {
        $body = "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n"
            . "| summarize | Summarize | Create a summary | summary.png | show |\n\n"
            . "## Disabled Actions\n\n"
            . "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n"
            . "| custom | Custom | Custom prompt | ✏️ | replace |\n";

        self::assertSame(['summarize'], array_column(ProfileTable::parse($body), 'id'));
    }

    public function testADokuwikiLinkIdIsReadFromItsLastColonSegment(): void
    {
        $body = "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n"
            . "| [[.:reports:create]] | Create | Create report | ✨ | insert |\n"
            . "| [[.:reports:summarize]] | Summarize | Summarize text | 📋 | show |\n";

        $ids = array_column(ProfileTable::parse($body), 'id');
        self::assertSame(['create', 'summarize'], $ids);
    }

    public function testIdsAreLowercased(): void
    {
        $body = "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n"
            . "| Summarize | Summarize | Create a summary | summary.png | show |\n";

        self::assertSame('summarize', ProfileTable::parse($body)[0]['id']);
    }

    public function testARowWithNoIdIsSkipped(): void
    {
        $body = "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n"
            . "|  | Summarize | Create a summary | summary.png | show |\n"
            . "| expand | Expand | Expand the text | expand.png | replace |\n";

        self::assertSame(['expand'], array_column(ProfileTable::parse($body), 'id'));
    }

    public function testNoTableIsAnEmptyList(): void
    {
        self::assertSame([], ProfileTable::parse("# Just a heading\n\nSome text.\n"));
    }
}
