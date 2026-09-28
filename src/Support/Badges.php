<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * Which `.tag-*` CSS class a `status` or `visibility` value gets, so the
 * colour follows the value the same way on every screen (TODO 13: "colour
 * code statuses and visibility labels, in tone with the palette") — one
 * rule, not a per-template guess. Uses only the shades already defined in
 * assets/css/wiki.css; never invents a colour. Visibility steps up from
 * --color-accent as exposure grows: private stays the quiet .tag-outline
 * border, unlisted is a soft accent tint, public is full reverse contrast
 * (solid accent fill) with a thin border, so it stands out at a glance.
 */
final class Badges
{
    public static function statusTag(string $status): string
    {
        return match ($status) {
            'signed' => 'tag-signed',
            'archived' => 'tag-outline',
            default => 'tag-neutral', // draft
        };
    }

    public static function visibilityTag(string $visibility): string
    {
        return match ($visibility) {
            'public' => 'tag-visible',
            'unlisted' => 'tag-caution',
            default => 'tag-outline', // private
        };
    }
}
