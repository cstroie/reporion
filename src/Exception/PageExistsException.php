<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Exception;

/**
 * An exclusive create (StorageInterface::create(..., exclusive: true)) found
 * a page already at the path: the create of a page by its path — the API's
 * POST /pages, the editor, /new — never quietly lands on `{path}-2`. The
 * path is a property, never the message (invariant 8).
 */
final class PageExistsException extends StorageException
{
    public function __construct(public readonly string $path)
    {
        parent::__construct('A page already exists at that path');
    }
}
