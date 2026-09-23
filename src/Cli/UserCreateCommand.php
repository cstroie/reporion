<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use InvalidArgumentException;
use Reporion\Auth\GrantParser;
use Reporion\Auth\UserStoreInterface;
use Reporion\Exception\AuthException;

/**
 * bin/reporion user:create — the only way to create an account (D35: no
 * self-service registration). Takes a pre-computed --password-hash rather
 * than a plaintext --password: argv is visible in shell history and to
 * anyone on the box running `ps`, which a password never should be.
 *
 *   bin/reporion user:create --username=<u> --password-hash=<h> [--owner]
 *     [--grant=<namespace>:editor] [--grant=<namespace>:viewer] ...
 *
 * `bin/reporion doctor` prints the exact php -r one-liner that produces a
 * --password-hash value.
 */
final class UserCreateCommand implements CommandInterface
{
    public function __construct(
        private readonly UserStoreInterface $users,
    ) {
    }

    public function run(array $args, Output $output): int
    {
        $options = self::parseOptions($args);

        $username = $options['username'] ?? null;
        $passwordHash = $options['password-hash'] ?? null;
        if ($username === null || $passwordHash === null) {
            $output->error('Usage: bin/reporion user:create --username=<u> --password-hash=<h> [--owner] [--grant=<namespace>:editor|viewer]');

            return 1;
        }

        // Without this, a plaintext string typed in place of a real hash
        // (a plausible slip, since --password-hash looks like it could take
        // either) writes successfully and the account is then permanently
        // unusable — password_verify() can never match it, and login only
        // ever reports "incorrect username or password," with nothing at
        // creation time pointing back at the actual cause.
        if (password_get_info($passwordHash)['algo'] === null) {
            $output->error('--password-hash does not look like a password_hash() value (got a plaintext string?)');

            return 1;
        }

        $isOwner = \in_array('--owner', $args, true);

        $grants = [];
        foreach ($options['grant'] ?? [] as $grantSpec) {
            try {
                $grants[] = GrantParser::parse($grantSpec);
            } catch (InvalidArgumentException $e) {
                $output->error($e->getMessage());

                return 1;
            }
        }

        try {
            $this->users->create($username, $passwordHash, $isOwner, $grants);
        } catch (AuthException | InvalidArgumentException $e) {
            $output->error($e->getMessage());

            return 1;
        }

        $output->line("Created {$username}" . ($isOwner ? ' (owner)' : ''));

        return 0;
    }

    /**
     * @param list<string> $args
     *
     * @return array{username?: string, 'password-hash'?: string, grant?: list<string>}
     */
    private static function parseOptions(array $args): array
    {
        $options = [];
        foreach ($args as $arg) {
            if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
                continue;
            }
            [$key, $value] = explode('=', substr($arg, 2), 2);
            if ($key === 'grant') {
                $options['grant'][] = $value;
            } else {
                $options[$key] = $value;
            }
        }

        return $options;
    }
}
