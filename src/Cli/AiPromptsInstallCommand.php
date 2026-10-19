<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Service\Ai\DefaultPrompts;

/**
 * bin/reporion ai:prompts-install --profile=<name> [--only=<id,id>] [--actor=<username>] [--list] [--dry-run] [--json]
 *
 * Makes the prompts the app ships (resources/prompts) pages of an AI
 * profile, whenever the owner wants: after the install, for a second
 * profile, or for a prompt added by a later release. A page that is there,
 * edited or not, is never touched. Service\Ai\DefaultPrompts, the code the
 * installer and Admin → AI use.
 */
final class AiPromptsInstallCommand implements CommandInterface
{
    public function __construct(
        private readonly DefaultPrompts $prompts,
        private readonly AuditLog $audit,
    ) {
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Makes the shipped example prompts pages of an AI profile (ai:profiles:<name>), skipping any page that is there already. Sends nothing to a model.',
            usage: '--profile=<name> [--only=<id,id>] [--actor=<username>] [--list] [--dry-run] [--json]',
            options: [
                '--profile=<name>' => 'the profile to complete (a new name makes it); default: reports',
                '--only=<id,id>' => 'only these ids (summary, tags, ask-archive, grammar, table, …)',
                '--actor=<username>' => 'author of the pages and of the audit line (default: cli)',
                '--list' => 'show which shipped prompts the profile has and which it lacks; write nothing',
                '--dry-run' => 'say which pages would be made; write nothing',
                '--json' => 'print the result as JSON',
            ],
        );
    }

    public function run(array $args, Output $output): int
    {
        $profile = 'reports';
        $actor = 'cli';
        $only = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--profile=')) {
                $profile = substr($arg, 10);
            } elseif (str_starts_with($arg, '--actor=') && \strlen($arg) > 8) {
                $actor = substr($arg, 8);
            } elseif (str_starts_with($arg, '--only=')) {
                $only = array_values(array_filter(explode(',', substr($arg, 7)), static fn (string $id): bool => $id !== ''));
            }
        }
        $json = \in_array('--json', $args, true);
        $list = \in_array('--list', $args, true);
        $dryRun = \in_array('--dry-run', $args, true);
        if (!$json) {
            $output->line(\sprintf('ai:prompts-install %s (%s)', $profile, $list ? 'list' : ($dryRun ? 'dry run' : 'writes')));
        }
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $profile) !== 1) {
            $output->error('A profile name is lowercase letters, digits, - and _, up to 32 characters.');

            return 1;
        }
        $unknown = array_values(array_filter($only ?? [], static fn (string $id): bool => !isset(DefaultPrompts::CATALOGUE[$id])));
        if ($unknown !== []) {
            $output->error('Unknown id(s): ' . implode(', ', $unknown) . '. Known: ' . implode(', ', array_keys(DefaultPrompts::CATALOGUE)));

            return 1;
        }

        try {
            if ($list) {
                $status = $this->prompts->status($profile);
                if ($json) {
                    $output->line((string) json_encode(['profile' => $profile, 'status' => $status], JSON_UNESCAPED_SLASHES));
                } else {
                    foreach ($status as $id => $state) {
                        $output->line(\sprintf('%-15s %-8s %s', $id, $state, DefaultPrompts::pathOf($profile, $id)));
                    }
                }

                return 0;
            }
            $result = $this->prompts->install($profile, $actor, $only, $dryRun);
        } catch (InvalidArgumentException $e) {
            $output->error($e->getMessage());

            return 1;
        }
        if (!$dryRun && $result['created'] !== []) {
            $this->audit->record('settings.change', $actor, null, extra: ['section' => 'ai.prompts', 'profile' => $profile, 'created' => implode(',', $result['created'])]);
        }
        if ($json) {
            $output->line((string) json_encode(['profile' => $profile, 'dry_run' => $dryRun] + $result, JSON_UNESCAPED_SLASHES));

            return 0;
        }
        foreach ($result['created'] as $id) {
            $output->line(($dryRun ? 'would make ' : 'made ') . DefaultPrompts::pathOf($profile, $id));
        }
        $output->line(\sprintf('%d %s, %d already there', \count($result['created']), $dryRun ? 'to make' : 'made', \count($result['skipped'])));

        return 0;
    }
}
