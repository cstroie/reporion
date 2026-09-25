<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Auth\UserStoreInterface;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;

/**
 * GET /profile and POST /profile/password (design/mockup/WikiProfile.dc.html,
 * the parts D35 keeps): the signed-in user's own account — grants and
 * signature details, read-only here (an owner edits them in /admin/users) —
 * and changing their own password, which needs the current one.
 *
 * Sessions are signed cookies with no password version in them, so a
 * password change does not end other open sessions; they expire on their
 * own lifetime.
 */
final class ProfileController
{
    public const MIN_PASSWORD_LENGTH = 10;

    public function __construct(
        private readonly UserStoreInterface $users,
        private readonly IndexInterface $index,
        private readonly AuditLog $audit,
    ) {
    }

    public function show(Request $request, ?User $principal): Response
    {
        if ($principal === null) {
            throw new PageNotFoundException();
        }

        return $this->render($request, $principal, error: null, saved: ($request->query['saved'] ?? null) === '1');
    }

    public function changePassword(Request $request, ?User $principal): Response
    {
        if ($principal === null) {
            throw new PageNotFoundException();
        }

        parse_str($request->body, $fields);
        $current = \is_string($fields['current'] ?? null) ? $fields['current'] : '';
        $new = \is_string($fields['new'] ?? null) ? $fields['new'] : '';
        $repeat = \is_string($fields['repeat'] ?? null) ? $fields['repeat'] : '';

        $error = self::passwordProblem($new, $repeat);
        if (!password_verify($current, $principal->passwordHash)) {
            $error = t('profile.err_current');
            $this->audit->record('password.change', $principal->username, $request, outcome: 'denied');
        }
        if ($error !== null) {
            return $this->render($request, $principal, error: $error, saved: false);
        }

        $this->users->save($principal->with(passwordHash: password_hash($new, \PASSWORD_ARGON2ID)));
        $this->audit->record('password.change', $principal->username, $request);

        return Response::redirect($request->basePath . '/profile?saved=1');
    }

    /** Why a new password is not acceptable, or null when it is */
    public static function passwordProblem(string $new, string $repeat): ?string
    {
        if (mb_strlen($new) < self::MIN_PASSWORD_LENGTH) {
            return t('profile.err_short', [self::MIN_PASSWORD_LENGTH]);
        }
        if (!hash_equals($new, $repeat)) {
            return t('profile.err_repeat');
        }

        return null;
    }

    private function render(Request $request, User $principal, ?string $error, bool $saved): Response
    {
        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/profile.php', [
            'account' => $principal,
            'error' => $error,
            'saved' => $saved,
            'minLength' => self::MIN_PASSWORD_LENGTH,
            'basePath' => $request->basePath,
        ] + ChromeVars::shell($request, $principal, $this->index, ''), t('profile.title')), $error !== null ? 422 : 200);
    }
}
