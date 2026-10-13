<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Auth\AccessSummary;
use Reporion\Auth\Group;
use Reporion\Auth\GroupStoreInterface;
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
 * Admin → Users, owner-only (D35: no self-service registration, so this screen and
 * `bin/reporion user:create` are the only two ways an account gets made).
 *
 * GET /admin/users lists the accounts and offers the create form; GET /admin/users/{username}
 * is one account's page — identity, access (owner, radiologist, groups, own grants, and what
 * that adds up to: D46, D47), password, status. Plain SSR forms with Post/Redirect/Get, no
 * island: a rarely-used admin form survives JavaScript being broken (docs/BUILD_LOG.md).
 *
 * The password arrives in plaintext here, unlike `user:create`'s `--password-hash`: an HTML
 * form POST body isn't shell history or `ps` output, so hashing it server-side is the correct
 * choice — do not "fix" this to match the CLI.
 *
 * A signed-in non-owner gets a bare 404 (invariant 9); nothing in the chrome links here for one.
 */
final class AdminUsersController
{
    public function __construct(
        private readonly UserStoreInterface $users,
        private readonly GroupStoreInterface $groups,
        private readonly IndexInterface $index,
        private readonly AuditLog $audit,
    ) {
    }

    public function index(Request $request, ?User $principal): Response
    {
        self::assertOwner($principal);

        return $this->renderList($request, $principal, null, []);
    }

    public function create(Request $request, ?User $principal): Response
    {
        self::assertOwner($principal);

        $f = self::fields($request);
        $username = trim(self::str($f, 'username'));
        $password = self::str($f, 'password');
        if ($username === '' || $password === '') {
            return $this->renderList($request, $principal, t('admin.users.err_required'), $f);
        }

        try {
            $grants = AccessSummary::parseLines(self::str($f, 'grants'));
            $account = $this->users->create($username, password_hash($password, \PASSWORD_ARGON2ID), self::checked($f, 'owner'), $grants, self::str($f, 'display_name'), self::str($f, 'title'));
            $groups = $this->knownGroups($f);
            $radiologist = self::checked($f, 'radiologist');
            if ($groups !== [] || $radiologist) {
                $this->users->save($account->with(groups: $groups, radiologist: $radiologist));
            }
        } catch (AuthException | InvalidArgumentException $e) {
            return $this->renderList($request, $principal, $e->getMessage(), $f);
        }
        $this->audit->record('user.create', $principal->username, $request, extra: ['account' => $username]);

        return Response::redirect($request->basePath . '/admin/users/' . rawurlencode($username) . '?saved=1');
    }

    public function show(Request $request, string $username, ?User $principal): Response
    {
        self::assertOwner($principal);

        return $this->renderAccount($request, $principal, $this->target($username), null, null);
    }

    /**
     * POST /admin/users/{username} — identity and access in one form: display name, title,
     * owner, radiologist, groups, own grants. Audited as `user.access` with the names of the
     * fields that changed, never their values. The last active owner cannot stop being one.
     */
    public function update(Request $request, string $username, ?User $principal): Response
    {
        self::assertOwner($principal);
        $target = $this->target($username);

        $f = self::fields($request);
        try {
            $grants = AccessSummary::parseLines(self::str($f, 'grants'));
        } catch (InvalidArgumentException $e) {
            return $this->renderAccount($request, $principal, $target, $e->getMessage(), $f);
        }
        $isOwner = self::checked($f, 'owner');
        if ($target->isOwner && !$isOwner && $this->isLastActiveOwner($target)) {
            return $this->renderAccount($request, $principal, $target, t('admin.users.err_last_owner'), $f);
        }

        $updated = $target->with(
            displayName: self::str($f, 'display_name'),
            title: self::str($f, 'title'),
            radiologist: self::checked($f, 'radiologist'),
            grants: $grants,
            groups: $this->knownGroups($f, $target->groups),
            isOwner: $isOwner,
        );
        $changed = self::changes($target, $updated);
        $this->users->save($updated);
        if ($changed !== []) {
            $this->audit->record('user.access', $principal->username, $request, extra: ['account' => $username, 'changed' => $changed]);
        }

        return Response::redirect($request->basePath . '/admin/users/' . rawurlencode($username) . '?saved=1');
    }

    /** POST /admin/users/{username}/profile — display name, title and radiologist only (kept for older forms) */
    public function profile(Request $request, string $username, ?User $principal): Response
    {
        self::assertOwner($principal);
        $target = $this->target($username);

        $f = self::fields($request);
        $this->users->save($target->with(
            displayName: self::str($f, 'display_name'),
            title: self::str($f, 'title'),
            radiologist: self::checked($f, 'radiologist'),
        ));

        return Response::redirect($request->basePath . '/admin/users');
    }

    public function deactivate(Request $request, string $username, ?User $principal): Response
    {
        self::assertOwner($principal);
        $target = $this->target($username);

        if ($target->isOwner && $target->active && $this->isLastActiveOwner($target)) {
            return $this->renderList($request, $principal, t('admin.users.err_last_owner'), []);
        }
        $this->users->save($target->with(active: false));
        $this->audit->record('user.deactivate', $principal->username, $request, extra: ['account' => $username]);

        return Response::redirect($request->basePath . '/admin/users');
    }

    public function reactivate(Request $request, string $username, ?User $principal): Response
    {
        self::assertOwner($principal);
        $target = $this->target($username);

        $this->users->save($target->with(active: true));
        $this->audit->record('user.reactivate', $principal->username, $request, extra: ['account' => $username]);

        return Response::redirect($request->basePath . '/admin/users');
    }

    /**
     * POST /admin/users/{username}/password — an owner sets someone's password (a forgotten
     * one, a new starter). Same rules as a user changing their own
     * (ProfileController::passwordProblem()); audited with the target account.
     */
    public function setPassword(Request $request, string $username, ?User $principal): Response
    {
        self::assertOwner($principal);
        $target = $this->target($username);

        $f = self::fields($request);
        $problem = ProfileController::passwordProblem(self::str($f, 'new'), self::str($f, 'repeat'));
        if ($problem !== null) {
            return $this->renderAccount($request, $principal, $target, null, null, $problem, ProfileController::passwordProblemField(self::str($f, 'new')));
        }

        $this->users->save($target->with(passwordHash: password_hash(self::str($f, 'new'), \PASSWORD_ARGON2ID)));
        $this->audit->record('password.reset', $principal->username, $request, extra: ['account' => $username]);

        return Response::redirect($request->basePath . '/admin/users/' . rawurlencode($username) . '?password=1');
    }

    /** @param array<string, mixed> $old */
    private function renderList(Request $request, User $principal, ?string $error, array $old): Response
    {
        $groups = $this->groups->all();

        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/admin-users.php',
            [
                'accounts' => iterator_to_array($this->users->all(), false),
                'groups' => $groups,
                'error' => $error,
                'old' => $old,
                'adminTab' => 'users',
                'basePath' => $request->basePath,
            ] + ChromeVars::shell($request, $principal, $this->index, ''),
            t('admin.users.title'),
        ), $error === null ? 200 : 422);
    }

    /** @param ?array<string, mixed> $old the posted form, to show again after an error */
    private function renderAccount(Request $request, User $principal, User $account, ?string $error, ?array $old, ?string $passwordError = null, ?string $passwordField = null): Response
    {
        $groups = $this->groups->all();

        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/admin-user.php',
            [
                'account' => $account,
                'groups' => $groups,
                'access' => AccessSummary::rows($account, AccessSummary::byName($groups)),
                'error' => $error,
                'passwordError' => $passwordError,
                'passwordField' => $passwordField,
                'old' => $old,
                'saved' => ($request->query['saved'] ?? '') === '1',
                'passwordSaved' => ($request->query['password'] ?? '') === '1',
                'isSelf' => $account->username === $principal->username,
                'adminTab' => 'users',
                'basePath' => $request->basePath,
            ] + ChromeVars::shell($request, $principal, $this->index, ''),
            $account->username . ' · ' . t('admin.users.title'),
        ), $error === null && $passwordError === null ? 200 : 422);
    }

    private function target(string $username): User
    {
        try {
            $user = $this->users->find($username);
        } catch (InvalidArgumentException) {
            $user = null;
        }
        if ($user === null) {
            throw new PageNotFoundException();
        }

        return $user;
    }

    /**
     * The groups ticked on the form that exist; a name the account already has but whose group
     * is gone is kept as it was (it resolves to nothing, and doctor names it)
     *
     * @param array<string, mixed> $f
     * @param list<string> $current
     *
     * @return list<string>
     */
    private function knownGroups(array $f, array $current = []): array
    {
        $known = array_map(static fn (Group $g): string => $g->name, $this->groups->all());
        $picked = array_values(array_filter((array) ($f['groups'] ?? []), 'is_string'));

        $out = [];
        foreach ([...$picked, ...array_diff($current, $known)] as $name) {
            if ((\in_array($name, $known, true) || \in_array($name, $current, true)) && !\in_array($name, $out, true)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /** True if no other active owner would remain without $target */
    private function isLastActiveOwner(User $target): bool
    {
        foreach ($this->users->all() as $user) {
            if ($user->username !== $target->username && $user->isOwner && $user->active) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> the names of the fields that differ */
    private static function changes(User $a, User $b): array
    {
        $grants = static fn (User $u): string => AccessSummary::lines($u->grants);

        return array_keys(array_filter([
            'display_name' => $a->displayName !== trim($b->displayName),
            'title' => $a->title !== trim($b->title),
            'owner' => $a->isOwner !== $b->isOwner,
            'radiologist' => $a->radiologist !== $b->radiologist,
            'groups' => $a->groups !== $b->groups,
            'grants' => $grants($a) !== $grants($b),
        ]));
    }

    private static function assertOwner(?User $principal): void
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }
    }

    /** @return array<string, mixed> */
    private static function fields(Request $request): array
    {
        parse_str($request->body, $fields);

        return $fields;
    }

    /** @param array<string, mixed> $f */
    private static function str(array $f, string $key): string
    {
        return \is_string($f[$key] ?? null) ? $f[$key] : '';
    }

    /** @param array<string, mixed> $f a checkbox: `1` (or a browser's `on`) */
    private static function checked(array $f, string $key): bool
    {
        return \in_array($f[$key] ?? null, ['1', 'on'], true);
    }
}
