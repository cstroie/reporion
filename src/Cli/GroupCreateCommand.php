<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use InvalidArgumentException;
use Reporion\Auth\GrantParser;
use Reporion\Auth\Group;
use Reporion\Auth\GroupStoreInterface;

/**
 * `bin/reporion group:create --name=<g> [--grant=<ns>:editor|viewer]... [--radiologist]`: a
 * group (D47) from the command line, as Admin → Groups makes one; members are added with
 * `user:create --group=<g>` or on the account's admin page.
 */
final class GroupCreateCommand implements CommandInterface
{
    public function __construct(
        private readonly GroupStoreInterface $groups,
    ) {
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Creates a group: a named set of grants its members get, and optionally the radiologist flag (D47).',
            usage: '--name=<name> [--radiologist] [--grant=<ns>:editor|viewer]... [--display-name=<text>]',
            options: [
                '--name=<name>' => 'the group name, shown as @name (required): lowercase letters, digits, . _ -',
                '--radiologist' => 'its members are radiologists: they see patient pages and use the report features',
                '--grant=<ns>:editor|viewer' => 'a namespace grant every member gets; repeat for more',
                '--display-name=<text>' => 'a description shown beside the name',
            ],
            details: 'Members are added with user:create --group=<name>, or in Admin → Users.',
        );
    }

    public function run(array $args, Output $output): int
    {
        $name = '';
        $displayName = '';
        $grants = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--name=')) {
                $name = substr($arg, 7);
            } elseif (str_starts_with($arg, '--display-name=')) {
                $displayName = substr($arg, 15);
            } elseif (str_starts_with($arg, '--grant=')) {
                try {
                    $grants[] = GrantParser::parse(substr($arg, 8));
                } catch (InvalidArgumentException $e) {
                    $output->error($e->getMessage());

                    return 1;
                }
            }
        }

        if (!Group::isValidName($name)) {
            $output->error('Usage: bin/reporion group:create --name=<name> [--radiologist] [--grant=<namespace>:editor|viewer]: a name is lowercase letters, digits, . _ -');

            return 1;
        }
        if ($this->groups->find($name) !== null) {
            $output->error("A group named {$name} already exists");

            return 1;
        }

        $radiologist = \in_array('--radiologist', $args, true);
        $this->groups->save(new Group($name, $displayName, $grants, $radiologist, '', ''));
        $output->line("Created @{$name}" . ($radiologist ? ' (radiologists)' : ''));

        return 0;
    }
}
