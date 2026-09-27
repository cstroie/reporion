<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Auth\ApiTokens;
use Reporion\Auth\User;
use Reporion\Auth\UserStoreInterface;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;

/**
 * GET /profile and its forms (design/mockup/WikiProfile.dc.html, the parts
 * D35 keeps): the signed-in user's own account — grants, read-only here;
 * their signature details (name and title on a printed report, POST
 * /profile/signature); their API tokens (POST /profile/tokens, POST
 * /profile/tokens/{id}/revoke — roadmap phase 13); and changing their own
 * password, which needs the current one.
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
        private readonly ApiTokens $tokens,
    ) {
    }

    public function show(Request $request, ?User $principal): Response
    {
        if ($principal === null) {
            throw new PageNotFoundException();
        }

        return $this->render($request, $principal, error: null, saved: ($request->query['saved'] ?? null) === '1', notice: match ($request->query['done'] ?? null) {
            'signature' => t('profile.signature_saved'),
            'revoked' => t('profile.token_revoked'),
            default => null,
        });
    }

    /** POST /profile/signature — the name and title a printed report shows for this account */
    public function saveSignature(Request $request, ?User $principal): Response
    {
        if ($principal === null) {
            throw new PageNotFoundException();
        }
        parse_str($request->body, $fields);
        $name = trim(\is_string($fields['display_name'] ?? null) ? $fields['display_name'] : '');
        $title = trim(\is_string($fields['title'] ?? null) ? $fields['title'] : '');
        if (mb_strlen($name) > 120 || mb_strlen($title) > 120) {
            return $this->render($request, $principal, error: null, saved: false, signatureError: t('profile.err_signature'));
        }
        $this->users->save($principal->with(displayName: $name, title: $title));
        $this->audit->record('profile.change', $principal->username, $request, extra: ['fields' => ['display_name', 'title']]);

        return Response::redirect($request->basePath . '/profile?done=signature#signature');
    }

    /** POST /profile/tokens — a new API token, shown once on the page this returns */
    public function createToken(Request $request, ?User $principal): Response
    {
        if ($principal === null) {
            throw new PageNotFoundException();
        }
        parse_str($request->body, $fields);
        try {
            [$saved, $token, $id] = $this->tokens->create(
                $principal,
                \is_string($fields['name'] ?? null) ? $fields['name'] : '',
                \is_string($fields['scope'] ?? null) ? $fields['scope'] : '',
            );
        } catch (InvalidArgumentException) {
            return $this->render($request, $principal, error: null, saved: false, tokenError: t('profile.err_token'));
        }
        $this->audit->record('token.create', $principal->username, $request, extra: ['token' => $id]);

        // Rendered, not redirected: this response is the only place the token ever appears
        $response = $this->render($request, $saved, error: null, saved: false, newToken: $token);

        return new Response($response->status, $response->body, $response->headers + ['Cache-Control' => 'no-store']);
    }

    /** POST /profile/tokens/{id}/revoke */
    public function revokeToken(Request $request, string $id, ?User $principal): Response
    {
        if ($principal === null) {
            throw new PageNotFoundException();
        }
        if ($this->tokens->revoke($principal, $id) === null) {
            throw new PageNotFoundException();
        }
        $this->audit->record('token.revoke', $principal->username, $request, extra: ['token' => $id]);

        return Response::redirect($request->basePath . '/profile?done=revoked#tokens');
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

    private function render(Request $request, User $principal, ?string $error, bool $saved, ?string $notice = null, ?string $signatureError = null, ?string $tokenError = null, ?string $newToken = null): Response
    {
        $status = $error !== null || $signatureError !== null || $tokenError !== null ? 422 : 200;

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/profile.php', [
            'account' => $principal,
            'error' => $error,
            'saved' => $saved,
            'notice' => $notice,
            'signatureError' => $signatureError,
            'tokenError' => $tokenError,
            'newToken' => $newToken,
            'scopes' => ApiTokens::SCOPES,
            'minLength' => self::MIN_PASSWORD_LENGTH,
            'basePath' => $request->basePath,
        ] + ChromeVars::shell($request, $principal, $this->index, ''), t('profile.title')), $status);
    }
}
