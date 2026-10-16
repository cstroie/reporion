<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Markdown;

use League\CommonMark\Node\Block\AbstractBlock;

/** `Table: text` on the line after a table: its `<caption>` */
final class TableCaption extends AbstractBlock
{
}
