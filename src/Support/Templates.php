<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * The `templates:` namespace (D19): "new report" copies a page from under
 * here, per modality (`templates:{modality ns}:*`) or shared. One constant
 * so the prefix is declared once — `Service\Snippets::NS` (its own
 * `templates:snippets` sub-namespace) builds on it rather than repeating
 * the literal. `templates:pages:*` holds the templates for ordinary pages
 * (PAGES_NS).
 */
final class Templates
{
    public const NS = 'templates';

    /**
     * Templates for ordinary pages (D47): offered on the plain new-page form to anyone who can
     * read them — writers too, through a viewer grant here — never on the guided report form.
     */
    public const PAGES_NS = self::NS . ':pages';
}
