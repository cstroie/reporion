<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ApiResponse;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Throttle;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Service\Guide\Guide;

/**
 * The imaging guide (D62), public: the Romanian "Ghid de indicații radioimagistice", signed by the
 * Ministry of Health, for anyone, signed in or not (owner, 2026-11-16).
 *
 * - `GET /guide[?q=&chapter=&tip=T,M&indication=indicated]`: search and the chapters' outline.
 * - `GET /guide/{key}`: one clinical situation with its exams.
 * - `GET /api/v1/guide?q=&chapter=&tip=&indication=&limit=&offset=` → `{data: [situation], page}`.
 * - `GET /api/v1/guide/{key}` → `{data: situation}`; an unknown key 404.
 * In the app's shell for a signed-in reader, in the public frame for anyone else (invariant 9:
 * a public route, not a CMS). An anonymous caller is throttled per address.
 */
final class GuideController
{
    /** Requests an anonymous address may make a minute */
    public const ANONYMOUS_PER_MINUTE = 60;

    public function __construct(
        private readonly Guide $guide,
        private readonly IndexInterface $index,
        private readonly ?Throttle $throttle = null,
    ) {
    }

    public function page(Request $request, ?User $principal): Response
    {
        if (($limited = $this->limited($request, $principal, false)) !== null) {
            return $limited;
        }
        $filters = self::filters($request);
        $q = mb_substr(trim((string) ($request->query['q'] ?? '')), 0, 200);
        $searched = $q !== '' || $filters !== [];

        return $this->render($request, $principal, [
            'q' => $q,
            'filters' => $filters,
            'results' => $searched ? $this->guide->search($q, $filters, 30) : [],
            'searched' => $searched,
            'outline' => $this->guide->outline(),
            'situation' => null,
        ], t('guide.title'));
    }

    public function situation(Request $request, string $key, ?User $principal): Response
    {
        if (($limited = $this->limited($request, $principal, false)) !== null) {
            return $limited;
        }
        $situation = $this->guide->situation($key) ?? throw new PageNotFoundException();

        return $this->render($request, $principal, ['q' => '', 'filters' => [], 'results' => [], 'searched' => false, 'outline' => [], 'situation' => $situation], $situation['title']);
    }

    public function search(Request $request, ?User $principal): Response
    {
        if (($limited = $this->limited($request, $principal, true)) !== null) {
            return $limited;
        }
        $limit = max(1, min(50, (int) ($request->query['limit'] ?? 10)));
        $offset = max(0, min(1000, (int) ($request->query['offset'] ?? 0)));
        $data = $this->guide->search(mb_substr(trim((string) ($request->query['q'] ?? '')), 0, 200), self::filters($request), $limit, $offset);

        return ApiResponse::json(['data' => $data, 'page' => ['limit' => $limit, 'offset' => $offset, 'next' => \count($data) === $limit ? $offset + $limit : null]]);
    }

    public function one(Request $request, string $key, ?User $principal): Response
    {
        if (($limited = $this->limited($request, $principal, true)) !== null) {
            return $limited;
        }
        $situation = $this->guide->situation($key);

        return $situation === null ? ApiResponse::error(404, 'not_found', 'Not found.') : ApiResponse::json(['data' => $situation]);
    }

    /** @return array{tip?: list<string>, chapter?: string, indication?: list<string>} */
    private static function filters(Request $request): array
    {
        $list = static fn (string $name): array => array_values(array_filter(array_map('trim', explode(',', (string) ($request->query[$name] ?? ''))), static fn (string $v): bool => $v !== ''));

        return array_filter([
            'tip' => $list('tip'),
            'chapter' => mb_substr(trim((string) ($request->query['chapter'] ?? '')), 0, 200),
            'indication' => $list('indication'),
        ], static fn (array|string $v): bool => $v !== [] && $v !== '');
    }

    private function limited(Request $request, ?User $principal, bool $api): ?Response
    {
        if ($principal !== null || $this->throttle === null || $this->throttle->hit('guide:' . $request->remoteAddr, 60) <= self::ANONYMOUS_PER_MINUTE) {
            return null;
        }

        return $api ? ApiResponse::error(429, 'throttled', t('guide.throttled')) : Response::html(t('guide.throttled'), 429);
    }

    /** @param array<string, mixed> $vars */
    private function render(Request $request, ?User $principal, array $vars, string $title): Response
    {
        $vars += ['basePath' => $request->basePath, 'info' => $this->guide->info()];
        $content = \dirname(__DIR__, 2) . '/templates/guide.php';
        if ($principal === null) {
            $vars += ChromeVars::theme($request);

            return Response::html(View::render(\dirname(__DIR__, 2) . '/templates/layout-guide-public.php', ['title' => $title, 'content' => View::render($content, $vars)] + $vars));
        }

        return Response::html(View::page($content, $vars + ChromeVars::shell($request, $principal, $this->index, ''), $title));
    }
}
