<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Exception;

/**
 * A status change refused because the page carries a signature (D65): a
 * signed report is corrected and signed again (D3), never archived.
 */
final class SignedPageException extends StorageException
{
    public function __construct()
    {
        parent::__construct('The page carries a signature');
    }
}
