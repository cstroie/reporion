<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Exception;

/**
 * auth.session_secret is empty: a cookie signed with an empty HMAC key is
 * one anyone can forge, so Http\Session refuses to issue one (and verifies
 * none). Http\ErrorMapper answers it as a generic 500; bin/reporion doctor
 * names the missing setting.
 */
final class SessionSecretMissingException extends AuthException
{
    public function __construct()
    {
        parent::__construct('auth.session_secret is not configured');
    }
}
