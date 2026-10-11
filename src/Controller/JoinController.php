<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use InvalidArgumentException;
use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Service\Joins;

/**
 * POST /join (roadmap phase 29): the reports ticked on a namespace index or
 * a patient timeline (`paths[]`), joined into one multi-exam report.
 * Every post but `action=join` shows the check screen — the exams in order
 * (↑ ↓ are submit buttons: it works without JavaScript), the report
 * fields to pick where the parents differ, the path (its last segment
 * editable); `action=join` writes
 * it (Service\Joins) and opens the joined report's editor.
 */
final class JoinController
{
    public function __construct(
        private readonly Joins $joins,
        private readonly IndexInterface $index,
    ) {
    }

    public function post(Request $request, ?User $principal): Response
    {
        if ($principal === null) {
            throw new PageNotFoundException();
        }
        parse_str($request->body, $fields);
        $paths = array_values(array_filter((array) ($fields['paths'] ?? []), 'is_string'));
        $order = array_values(array_filter((array) ($fields['order'] ?? []), 'is_string'));
        // One value per field, but summary: a list of the ticked (summary_set: the list was posted, maybe empty)
        $choices = array_filter((array) ($fields['choice'] ?? []), static fn (mixed $v, string $k): bool => \is_string($v) || ($k === 'summary' && \is_array($v)), ARRAY_FILTER_USE_BOTH);
        if (isset($fields['summary_set'])) {
            $choices['summary_set'] = '1';
        }
        $ns = \is_string($fields['ns'] ?? null) ? $fields['ns'] : '';
        $leaf = \is_string($fields['leaf'] ?? null) ? $fields['leaf'] : '';
        $action = \is_string($fields['action'] ?? null) ? $fields['action'] : '';

        // ↑ / ↓ on an exam: its key moved one place in the posted order
        if (preg_match('/^(up|down):(\d+\.\d+)$/', $action, $m) === 1 && ($at = array_search($m[2], $order, true)) !== false) {
            $to = $at + ($m[1] === 'up' ? -1 : 1);
            if (isset($order[$to])) {
                [$order[$at], $order[$to]] = [$order[$to], $order[$at]];
            }
        }

        $plan = $this->joins->plan($paths, $principal, $order, $choices, $ns, $leaf);
        $error = null;
        if ($action === 'join') {
            $revs = array_map('intval', array_filter((array) ($fields['rev'] ?? []), static fn (mixed $r): bool => \is_string($r) && ctype_digit($r)));
            try {
                $joined = $this->joins->apply($plan, $revs, $principal, $request);

                return Response::redirect($request->basePath . '/' . $joined->path . '/edit');
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
                $plan = $this->joins->plan($paths, $principal, $order, $choices, $ns, $leaf);
            }
        }

        $back = \is_string($fields['back'] ?? null) && str_starts_with($fields['back'], '/') && !str_starts_with($fields['back'], '//') ? $fields['back'] : '/';

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/join.php', [
            'plan' => $plan,
            'error' => $error,
            'back' => $back,
            'basePath' => $request->basePath,
        ] + ChromeVars::shell($request, $principal, $this->index, 'reports'), t('join.title')), $error !== null || ($plan['problems'] !== [] && $action === 'join') ? 422 : 200);
    }
}
