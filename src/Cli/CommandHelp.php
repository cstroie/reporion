<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

/**
 * What a bin/reporion command does and every option it takes — printed by
 * `bin/reporion <command> --help`, and the first line of every run (the
 * summary), so a run says what it is about to do before it does it.
 */
final class CommandHelp
{
    /**
     * @param string                $summary what the command does, one sentence
     * @param string                $usage   the arguments after the command name, e.g. "<from> <to> [--actor=<username>]"
     * @param array<string, string> $options option as written => what it does, in the order shown
     * @param string                $details anything else: exit codes, what is never written, where output goes
     */
    public function __construct(
        public readonly string $summary,
        public readonly string $usage,
        public readonly array $options = [],
        public readonly string $details = '',
    ) {
    }

    public function render(string $command): string
    {
        $lines = ['Usage: bin/reporion ' . $command . ($this->usage !== '' ? ' ' . $this->usage : ''), '', $this->summary];
        if ($this->details !== '') {
            $lines[] = '';
            $lines[] = $this->details;
        }
        if ($this->options !== []) {
            $lines[] = '';
            $lines[] = 'Options:';
            $width = max(array_map('strlen', array_keys($this->options))) + 2;
            foreach ($this->options as $option => $text) {
                $lines[] = '  ' . str_pad($option, $width) . $text;
            }
        }

        return implode("\n", $lines) . "\n";
    }
}
