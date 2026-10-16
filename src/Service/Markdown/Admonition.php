<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Markdown;

use League\CommonMark\Node\Block\AbstractBlock;

/** `::: warning Title` … `:::`: a callout holding any blocks */
final class Admonition extends AbstractBlock
{
    public function __construct(public readonly string $type, public readonly string $title)
    {
        parent::__construct();
    }
}
