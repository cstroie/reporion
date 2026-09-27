<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Exception;

/**
 * An API bearer token that cannot be used (roadmap phase 13): malformed,
 * unknown, revoked or its account inactive (401 invalid_token), or a
 * read-scope token asked to write (403 insufficient_scope). Http\ErrorMapper
 * answers it; the message never carries the token.
 */
final class InvalidTokenException extends AuthException
{
    public function __construct(public readonly bool $scope = false)
    {
        parent::__construct($scope ? 'insufficient_scope' : 'invalid_token');
    }
}
