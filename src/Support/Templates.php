<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * The `templates:` namespace (D19): "new report" copies a page from under
 * here, per modality (`templates:{modality ns}:*`) or shared. One constant
 * so the prefix is declared once — `Service\Snippets::NS` (its own
 * `templates:snippets` sub-namespace) builds on it rather than repeating
 * the literal.
 */
final class Templates
{
    public const NS = 'templates';
}
