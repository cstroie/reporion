<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Markdown;

use League\CommonMark\Node\Block\AbstractBlock;

/** `*[MRI]: Magnetic resonance imaging`: read by AbbreviationExpander, then removed; never rendered */
final class AbbreviationDefinition extends AbstractBlock
{
    public function __construct(public readonly string $abbreviation, public readonly string $title)
    {
        parent::__construct();
    }
}
