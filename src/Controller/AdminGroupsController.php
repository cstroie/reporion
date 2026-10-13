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
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;

/**
 * Admin → Groups, owner-only (D47): named sets of grants, optionally making their members
 * radiologists. GET /admin/groups lists them with their members and offers the create form;
 * GET /admin/groups/{name} is one group's page. Membership is set on the account (Admin → Users),
 * shown here. A group with members cannot be deleted: take them out first, so nobody loses
 * access by a click on another screen. Audited as `group.create` / `group.update` / `group.delete`.
 */
final class AdminGroupsController
{
    public function __construct(
        private readonly GroupStoreInterface $groups,
        private readonly UserStoreInterface $users,
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

        parse_str($request->body, $f);
        $name = strtolower(trim(self::str($f, 'name')));
        if (!Group::isValidName($name)) {
            return $this->renderList($request, $principal, t('admin.groups.err_name'), $f);
        }
        if ($this->groups->find($name) !== null) {
            return $this->renderList($request, $principal, t('admin.groups.err_exists'), $f);
        }
        try {
            $grants = AccessSummary::parseLines(self::str($f, 'grants'));
        } catch (InvalidArgumentException $e) {
            return $this->renderList($request, $principal, $e->getMessage(), $f);
        }

        $this->groups->save(new Group($name, self::str($f, 'display_name'), $grants, self::checked($f, 'radiologist'), '', ''));
        $this->audit->record('group.create', $principal->username, $request, extra: ['group' => $name]);

        return Response::redirect($request->basePath . '/admin/groups/' . rawurlencode($name) . '?saved=1');
    }

    public function show(Request $request, string $name, ?User $principal): Response
    {
        self::assertOwner($principal);

        return $this->renderGroup($request, $principal, $this->target($name), null, null);
    }

    public function update(Request $request, string $name, ?User $principal): Response
    {
        self::assertOwner($principal);
        $group = $this->target($name);

        parse_str($request->body, $f);
        try {
            $grants = AccessSummary::parseLines(self::str($f, 'grants'));
        } catch (InvalidArgumentException $e) {
            return $this->renderGroup($request, $principal, $group, $e->getMessage(), $f);
        }

        $updated = $group->with(displayName: self::str($f, 'display_name'), grants: $grants, radiologist: self::checked($f, 'radiologist'));
        $changed = array_keys(array_filter([
            'display_name' => $group->displayName !== trim($updated->displayName),
            'grants' => AccessSummary::lines($group->grants) !== AccessSummary::lines($updated->grants),
            'radiologist' => $group->radiologist !== $updated->radiologist,
        ]));
        $this->groups->save($updated);
        if ($changed !== []) {
            $this->audit->record('group.update', $principal->username, $request, extra: ['group' => $name, 'changed' => $changed]);
        }

        return Response::redirect($request->basePath . '/admin/groups/' . rawurlencode($name) . '?saved=1');
    }

    public function delete(Request $request, string $name, ?User $principal): Response
    {
        self::assertOwner($principal);
        $group = $this->target($name);

        if ($this->members($group->name) !== []) {
            return $this->renderGroup($request, $principal, $group, t('admin.groups.err_members'), null);
        }
        $this->groups->delete($group->name);
        $this->audit->record('group.delete', $principal->username, $request, extra: ['group' => $name]);

        return Response::redirect($request->basePath . '/admin/groups');
    }

    /** @param array<string, mixed> $old */
    private function renderList(Request $request, User $principal, ?string $error, array $old): Response
    {
        $members = [];
        foreach ($this->users->all() as $user) {
            foreach ($user->groups as $g) {
                $members[$g][] = $user;
            }
        }

        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/admin-groups.php',
            [
                'groups' => $this->groups->all(),
                'members' => $members,
                'error' => $error,
                'old' => $old,
                'adminTab' => 'groups',
                'basePath' => $request->basePath,
            ] + ChromeVars::shell($request, $principal, $this->index, ''),
            t('admin.groups.title'),
        ), $error === null ? 200 : 422);
    }

    /** @param ?array<string, mixed> $old */
    private function renderGroup(Request $request, User $principal, Group $group, ?string $error, ?array $old): Response
    {
        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/admin-group.php',
            [
                'group' => $group,
                'members' => $this->members($group->name),
                'error' => $error,
                'old' => $old,
                'saved' => ($request->query['saved'] ?? '') === '1',
                'adminTab' => 'groups',
                'basePath' => $request->basePath,
            ] + ChromeVars::shell($request, $principal, $this->index, ''),
            '@' . $group->name . ' · ' . t('admin.groups.title'),
        ), $error === null ? 200 : 422);
    }

    /** @return list<User> */
    private function members(string $name): array
    {
        $out = [];
        foreach ($this->users->all() as $user) {
            if (\in_array($name, $user->groups, true)) {
                $out[] = $user;
            }
        }

        return $out;
    }

    private function target(string $name): Group
    {
        return $this->groups->find($name) ?? throw new PageNotFoundException();
    }

    private static function assertOwner(?User $principal): void
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }
    }

    /** @param array<string, mixed> $f */
    private static function str(array $f, string $key): string
    {
        return \is_string($f[$key] ?? null) ? $f[$key] : '';
    }

    /** @param array<string, mixed> $f */
    private static function checked(array $f, string $key): bool
    {
        return \in_array($f[$key] ?? null, ['1', 'on'], true);
    }
}
