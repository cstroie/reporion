<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

/**
 * Drops a reasoning model's `<think>…</think>` from a streamed answer, even
 * when a tag is split across chunks (DokuLLM stripped it after the fact;
 * here the answer streams, so the filter keeps state).
 */
final class ThinkFilter
{
    private string $pending = '';
    private bool $inside = false;

    /** Text to show from this chunk (possibly less than it, while a tag may be starting) */
    public function push(string $chunk): string
    {
        $this->pending .= $chunk;
        $out = '';
        while (true) {
            $tag = $this->inside ? '</think>' : '<think>';
            $at = stripos($this->pending, $tag);
            if ($at !== false) {
                if (!$this->inside) {
                    $out .= substr($this->pending, 0, $at);
                }
                $this->pending = substr($this->pending, $at + \strlen($tag));
                $this->inside = !$this->inside;
                continue;
            }
            // Keep back what could be the start of the tag
            $keep = 0;
            for ($n = min(\strlen($tag) - 1, \strlen($this->pending)); $n > 0; --$n) {
                if (strcasecmp(substr($this->pending, -$n), substr($tag, 0, $n)) === 0) {
                    $keep = $n;
                    break;
                }
            }
            $ready = substr($this->pending, 0, \strlen($this->pending) - $keep);
            if (!$this->inside) {
                $out .= $ready;
            }
            $this->pending = substr($this->pending, \strlen($this->pending) - $keep);

            return $out;
        }
    }

    /** What is left at the end: a dangling partial tag was just text */
    public function finish(): string
    {
        $rest = $this->inside ? '' : $this->pending;
        $this->pending = '';

        return $rest;
    }
}
