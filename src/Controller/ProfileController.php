<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Auth\AccessSummary;
use Reporion\Auth\ApiTokens;
use Reporion\Auth\GroupStoreInterface;
use Reporion\Auth\Pins;
use Reporion\Auth\SavedViews;
use Reporion\Auth\User;
use Reporion\Auth\UserStoreInterface;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Theme;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;

/**
 * GET /profile and its forms (design/mockup/WikiProfile.dc.html, the parts
 * D35 keeps): the signed-in user's own account: grants, read-only here;
 * their signature details (name and title on a printed report, POST
 * /profile/signature); their API tokens (POST /profile/tokens, POST
 * /profile/tokens/{id}/revoke: roadmap phase 13); and changing their own
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
        private readonly ?GroupStoreInterface $groups = null,
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

    /** POST /profile/signature: the names, title and stamp code a printed report shows for this account */
    public function saveSignature(Request $request, ?User $principal): Response
    {
        if ($principal === null) {
            throw new PageNotFoundException();
        }
        parse_str($request->body, $fields);
        $name = trim(\is_string($fields['display_name'] ?? null) ? $fields['display_name'] : '');
        $fullName = trim(\is_string($fields['full_name'] ?? null) ? $fields['full_name'] : '');
        $title = trim(\is_string($fields['title'] ?? null) ? $fields['title'] : '');
        $stamp = trim(\is_string($fields['stamp'] ?? null) ? $fields['stamp'] : '');
        if (mb_strlen($name) > 120 || mb_strlen($fullName) > 120 || mb_strlen($title) > 120 || mb_strlen($stamp) > 40) {
            return $this->render($request, $principal, error: null, saved: false, signatureError: t('profile.err_signature'));
        }
        $this->users->save($principal->with(displayName: $name, fullName: $fullName, title: $title, stamp: $stamp));
        $this->audit->record('profile.change', $principal->username, $request, extra: ['fields' => ['display_name', 'full_name', 'title', 'stamp']]);

        return Response::redirect($request->basePath . '/profile?done=signature#signature');
    }

    /**
     * POST /profile/pins: pin or unpin a namespace in the quick-navigation
     * menu (Http\QuickNav), then back to where the form was. Not audited:
     * like the theme, a pin changes what this account's menu shows, not
     * what it can reach.
     */
    public function togglePin(Request $request, ?User $principal): Response
    {
        if ($principal === null) {
            throw new PageNotFoundException();
        }
        parse_str($request->body, $fields);
        $back = Response::redirect($request->basePath . Theme::returnPath($fields['return_to'] ?? null));
        // Not a namespace that may be pinned, or the list is full (the menu
        // offers no Pin then): nothing to change
        $ns = Pins::normalize(\is_string($fields['ns'] ?? null) ? $fields['ns'] : '');
        if ($ns === null) {
            return $back;
        }
        $pins = array_values(array_filter($principal->pins, static fn (string $pin): bool => $pin !== $ns));
        if (($fields['pin'] ?? null) === '1') {
            if (\count($pins) >= Pins::MAX) {
                return $back;
            }
            $pins[] = $ns;
        }
        $this->users->save($principal->with(pins: $pins));

        return $back;
    }

    /**
     * POST /profile/views {name, query} saves a view (2026-10-19): a named
     * search in the quick-navigation menu, one of the same name replaced;
     * {name, remove=1} removes it; then back to where the form was. Only
     * `/search`'s own parameters are kept (Auth\SavedViews). Not audited,
     * like a pin: it changes this account's menu, not what it can reach.
     */
    public function saveView(Request $request, ?User $principal): Response
    {
        if ($principal === null) {
            throw new PageNotFoundException();
        }
        parse_str($request->body, $fields);
        $back = Response::redirect($request->basePath . Theme::returnPath($fields['return_to'] ?? null));
        $name = \is_string($fields['name'] ?? null) ? $fields['name'] : '';
        if (($fields['remove'] ?? null) === '1') {
            $views = SavedViews::remove($principal->views, $name);
        } else {
            $view = SavedViews::normalize($name, \is_string($fields['query'] ?? null) ? $fields['query'] : '');
            if ($view === null) {
                return $back;
            }
            $views = SavedViews::put($principal->views, $view);
        }
        if ($views !== $principal->views) {
            $this->users->save($principal->with(views: $views));
        }

        return $back;
    }

    /** POST /profile/tokens: a new API token, shown once on the page this returns */
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
                // Limited to namespaces (D56): one per line or comma; none, the whole account
                preg_split('/[\s,]+/', \is_string($fields['namespaces'] ?? null) ? $fields['namespaces'] : '') ?: [],
                // How long it works (hardening): a lifetime offered, or until revoked; 90 days when not sent
                self::lifetime($fields['days'] ?? null),
            );
        } catch (InvalidArgumentException) {
            return $this->render($request, $principal, error: null, saved: false, tokenError: t('profile.err_token'));
        }
        $this->audit->record('token.create', $principal->username, $request, extra: ['token' => $id]);

        // Rendered, not redirected: this response is the only place the token ever appears
        $response = $this->render($request, $saved, error: null, saved: false, newToken: $token);

        return new Response($response->status, $response->body, $response->headers + ['Cache-Control' => 'no-store']);
    }

    /** A token lifetime from the form: one of ApiTokens::LIFETIMES ('never' for none), else 0, which create() refuses */
    private static function lifetime(mixed $days): ?int
    {
        if ($days === 'never') {
            return null;
        }
        if ($days === null) {
            return 90;
        }

        return \is_string($days) && ctype_digit($days) && \in_array((int) $days, ApiTokens::LIFETIMES, true) ? (int) $days : 0;
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
        $errorField = self::passwordProblemField($new);
        if (!password_verify($current, $principal->passwordHash)) {
            $error = t('profile.err_current');
            $errorField = 'current';
            $this->audit->record('password.change', $principal->username, $request, outcome: 'denied');
        }
        if ($error !== null) {
            return $this->render($request, $principal, error: $error, saved: false, errorField: $errorField);
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

    /** The field a passwordProblem() belongs to: the new password if too short, else its repeat */
    public static function passwordProblemField(string $new): string
    {
        return mb_strlen($new) < self::MIN_PASSWORD_LENGTH ? 'new' : 'repeat';
    }

    private function render(Request $request, User $principal, ?string $error, bool $saved, ?string $notice = null, ?string $signatureError = null, ?string $tokenError = null, ?string $newToken = null, ?string $errorField = null): Response
    {
        $status = $error !== null || $signatureError !== null || $tokenError !== null ? 422 : 200;

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/profile.php', [
            'account' => $principal,
            // What the account reaches and why (D46, D47): only what counts: a report grant held by
            // someone who is not a radiologist is not shown as access
            'access' => array_values(array_filter(
                AccessSummary::rows($principal, AccessSummary::byName($this->groups?->all() ?? [])),
                static fn (array $row): bool => !$row['dropped']
            )),
            'error' => $error,
            'errorField' => $errorField,
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
