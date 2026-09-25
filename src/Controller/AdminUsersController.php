<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Auth\GrantParser;
use Reporion\Auth\User;
use Reporion\Auth\UserStoreInterface;
use Reporion\Exception\AuthException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;

/**
 * GET/POST /admin/users — account management, owner-only (D35: no
 * self-service registration, so this screen and the CLI's
 * `bin/reporion user:create` are the only two ways an account gets made).
 *
 * Plain SSR forms (classic POST + redirect, same shape as AuthController),
 * not a JS island — despite docs/architecture-api.md's route table listing
 * `/admin/*` as "island". Deliberate deviation, not an oversight: this
 * project's own SSR-vs-island rule ("if it should survive JavaScript being
 * broken... it is server-rendered") fits a rarely-used admin form better
 * than the mockup's tabbed-island design does, and building a whole
 * island-mounting subsystem for one CRUD screen is out of proportion to
 * what it needs — see docs/BUILD_LOG.md.
 *
 * The password arrives in plaintext here, unlike `user:create`'s
 * `--password-hash`: an HTML form POST body isn't shell history or `ps`
 * output, so hashing it server-side with password_hash() is the correct,
 * not merely convenient, choice — do not "fix" this to match the CLI.
 *
 * A signed-in non-owner gets a bare 404 here, same as every write endpoint
 * (invariant 9) — and critically, nothing in the app's chrome links here
 * for a non-owner, so this is never a visible dead end, only an
 * unreachable-by-navigation route.
 */
final class AdminUsersController
{
    public function __construct(
        private readonly UserStoreInterface $users,
        private readonly IndexInterface $index,
        private readonly AuditLog $audit,
    ) {
    }

    public function index(Request $request, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }

        return $this->render($request, $principal, error: null, oldUsername: '', oldGrants: '');
    }

    public function create(Request $request, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }

        parse_str($request->body, $fields);
        $username = \is_string($fields['username'] ?? null) ? trim($fields['username']) : '';
        $password = \is_string($fields['password'] ?? null) ? $fields['password'] : '';
        $isOwner = ($fields['owner'] ?? null) === 'on';
        $grantsText = \is_string($fields['grants'] ?? null) ? $fields['grants'] : '';
        $displayName = \is_string($fields['display_name'] ?? null) ? $fields['display_name'] : '';
        $title = \is_string($fields['title'] ?? null) ? $fields['title'] : '';

        if ($username === '' || $password === '') {
            return $this->render($request, $principal, error: t('admin.users.err_required'), oldUsername: $username, oldGrants: $grantsText);
        }

        $grants = [];
        foreach (self::grantLines($grantsText) as $line) {
            try {
                $grants[] = GrantParser::parse($line);
            } catch (InvalidArgumentException $e) {
                return $this->render($request, $principal, error: $e->getMessage(), oldUsername: $username, oldGrants: $grantsText);
            }
        }

        try {
            $this->users->create($username, password_hash($password, \PASSWORD_ARGON2ID), $isOwner, $grants, $displayName, $title);
        } catch (AuthException | InvalidArgumentException $e) {
            return $this->render($request, $principal, error: $e->getMessage(), oldUsername: $username, oldGrants: $grantsText);
        }

        return Response::redirect($request->basePath . '/admin/users');
    }

    public function deactivate(Request $request, string $username, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }

        $target = $this->users->find($username);
        if ($target === null) {
            throw new PageNotFoundException();
        }

        if ($this->wouldRemoveTheLastActiveOwner($target)) {
            return $this->render($request, $principal, error: t('admin.users.err_last_owner'), oldUsername: '', oldGrants: '');
        }

        $this->users->save($target->with(active: false));

        return Response::redirect($request->basePath . '/admin/users');
    }

    public function reactivate(Request $request, string $username, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }

        $target = $this->users->find($username);
        if ($target === null) {
            throw new PageNotFoundException();
        }

        $this->users->save($target->with(active: true));

        return Response::redirect($request->basePath . '/admin/users');
    }

    /**
     * POST /admin/users/{username}/profile — display name and title, the
     * signer block a printed report shows for this account.
     */
    public function profile(Request $request, string $username, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }

        $target = $this->users->find($username);
        if ($target === null) {
            throw new PageNotFoundException();
        }

        parse_str($request->body, $fields);
        $this->users->save($target->with(
            displayName: \is_string($fields['display_name'] ?? null) ? $fields['display_name'] : '',
            title: \is_string($fields['title'] ?? null) ? $fields['title'] : '',
        ));

        return Response::redirect($request->basePath . '/admin/users');
    }

    /**
     * POST /admin/users/{username}/password — an owner sets someone's
     * password (a forgotten one, a new starter). Same rules as a user
     * changing their own (ProfileController::passwordProblem()); audited
     * with the target account.
     */
    public function setPassword(Request $request, string $username, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }

        $target = $this->users->find($username);
        if ($target === null) {
            throw new PageNotFoundException();
        }

        parse_str($request->body, $fields);
        $new = \is_string($fields['new'] ?? null) ? $fields['new'] : '';
        $repeat = \is_string($fields['repeat'] ?? null) ? $fields['repeat'] : '';
        $problem = ProfileController::passwordProblem($new, $repeat);
        if ($problem !== null) {
            return $this->render($request, $principal, error: $username . ': ' . $problem, oldUsername: '', oldGrants: '');
        }

        $this->users->save($target->with(passwordHash: password_hash($new, \PASSWORD_ARGON2ID)));
        $this->audit->record('password.reset', $principal->username, $request, extra: ['account' => $username]);

        return Response::redirect($request->basePath . '/admin/users');
    }

    private function render(Request $request, ?User $principal, ?string $error, string $oldUsername, string $oldGrants): Response
    {
        $accounts = iterator_to_array($this->users->all());

        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/admin-users.php',
            [
                'accounts' => $accounts,
                'error' => $error,
                'oldUsername' => $oldUsername,
                'oldGrants' => $oldGrants,
                'basePath' => $request->basePath,
            ] + ChromeVars::shell($request, $principal, $this->index, ''),
            t('admin.users.title'),
        ));
    }

    /**
     * True only if deactivating $target would leave zero active owner
     * accounts — a second active owner deactivating themselves is fine;
     * it's specifically "no active owner would remain" that's refused,
     * matching what bin/reporion doctor's own owner-account check looks
     * for.
     */
    private function wouldRemoveTheLastActiveOwner(User $target): bool
    {
        if (!$target->isOwner || !$target->active) {
            return false;
        }

        foreach ($this->users->all() as $user) {
            if ($user->username !== $target->username && $user->isOwner && $user->active) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function grantLines(string $text): array
    {
        $lines = array_map(trim(...), explode("\n", $text));

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }
}
