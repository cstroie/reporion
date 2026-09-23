<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Auth;

/**
 * The two roles a namespace grant can carry (D35). `owner` is deliberately
 * not a case here — it is instance-wide and never paired with a namespace
 * (CLAUDE.md "Naming": "editor/viewer are always paired with a namespace in
 * a grant, never bare"), so it lives as `User::$isOwner` instead.
 */
enum GrantRole: string
{
    case Editor = 'editor';
    case Viewer = 'viewer';
}
