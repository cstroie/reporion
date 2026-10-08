<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

/**
 * Turns `&lt;` and `&gt;` in a streamed answer back into `<` and `>`:
 * Context escapes them in the text it sends (2026-10-08), and a model
 * rewriting "&lt; 5 mm" echoes it. An entity split across chunks is held
 * back until the next one says what it is.
 */
final class EntityFilter
{
    private string $pending = '';

    /** Text to show from this chunk (possibly less than it, while an entity may be starting) */
    public function push(string $chunk): string
    {
        $text = $this->pending . $chunk;
        $this->pending = '';
        // Hold back a trailing "&", "&l", "&lt", "&g" or "&gt"
        if (preg_match('/&(?:[lg]t?)?$/', $text, $m) === 1) {
            $this->pending = $m[0];
            $text = substr($text, 0, -\strlen($m[0]));
        }

        return strtr($text, ['&lt;' => '<', '&gt;' => '>']);
    }

    /** What is left at the end: a partial entity was just text */
    public function finish(): string
    {
        $rest = $this->pending;
        $this->pending = '';

        return $rest;
    }
}
