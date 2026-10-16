<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Markdown;

use League\CommonMark\Node\Inline\AbstractInline;

/** An occurrence of a defined abbreviation: rendered `<abbr title>` */
final class Abbreviation extends AbstractInline
{
    public function __construct(public readonly string $title)
    {
        parent::__construct();
    }
}
