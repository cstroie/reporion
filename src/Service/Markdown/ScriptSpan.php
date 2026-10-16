<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Markdown;

use League\CommonMark\Node\Inline\AbstractInline;

/** `^up^` (superscript) or `~down~` (subscript): cm^2^, H~2~O */
final class ScriptSpan extends AbstractInline
{
    public function __construct(public readonly string $tag)
    {
        parent::__construct();
    }
}
