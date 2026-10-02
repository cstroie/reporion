<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * How a page's visibility looks, the same on every screen: one icon, one
 * label and one sentence per level, and the badge built from them (its
 * colour from Badges::visibilityTag()). The levels' meaning is
 * Service\Publishing's; this is presentation only.
 */
final class Visibility
{
    public const LEVELS = ['private', 'unlisted', 'public'];

    private const ICONS = ['private' => 'ph-lock-simple', 'unlisted' => 'ph-link-simple', 'public' => 'ph-globe-simple'];

    /** An unknown or empty value reads as private, as Storage treats it */
    public static function normal(string $visibility): string
    {
        return \in_array($visibility, self::LEVELS, true) ? $visibility : 'private';
    }

    public static function icon(string $visibility): string
    {
        return self::ICONS[self::normal($visibility)];
    }

    public static function label(string $visibility): string
    {
        return t('vis.' . self::normal($visibility));
    }

    public static function explain(string $visibility): string
    {
        return t('vis.explain_' . self::normal($visibility));
    }

    /**
     * The badge: icon and label in the level's tag colour, the sentence as
     * its tooltip. $iconOnly for tight rows (the drawer): the icon alone,
     * the label kept for screen readers.
     */
    public static function badge(string $visibility, bool $iconOnly = false): string
    {
        $v = self::normal($visibility);
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
        $title = $e(self::label($v) . ' — ' . self::explain($v));
        if ($iconOnly) {
            return '<span class="wk-vis wk-vis-' . $v . '" role="img" aria-label="' . $e(self::label($v)) . '" title="' . $title . '"><i class="ph ' . self::icon($v) . '" aria-hidden="true"></i></span>';
        }

        return '<span class="tag tag-vis ' . Badges::visibilityTag($v) . '" title="' . $title . '"><i class="ph ' . self::icon($v) . '" aria-hidden="true"></i>' . $e(self::label($v)) . '</span>';
    }
}
