<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

/**
 * A few dozen lines, on purpose (CLAUDE.md: "no framework"). `{name}`
 * placeholders match one path segment by default; `{name:regex}` overrides
 * that. Colon paths (docs/FORMATS.md §9) contain no `/`, so the default
 * `[^/]+` constraint is exactly right for `{path}`.
 */
final class Router
{
    /**
     * @var list<array{method: string, regex: string, params: list<string>, handler: callable(Request, array<string, string>): Response}>
     */
    private array $routes = [];

    /**
     * @param callable(Request, array<string, string>): Response $handler
     */
    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    /**
     * @param callable(Request, array<string, string>): Response $handler
     */
    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    /**
     * @param callable(Request, array<string, string>): Response $handler
     */
    private function add(string $method, string $pattern, callable $handler): void
    {
        [$regex, $params] = self::compile($pattern);
        $this->routes[] = ['method' => $method, 'regex' => $regex, 'params' => $params, 'handler' => $handler];
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }
            if (preg_match($route['regex'], $request->path, $matches) !== 1) {
                continue;
            }

            $params = [];
            foreach ($route['params'] as $name) {
                $params[$name] = $matches[$name] ?? '';
            }

            return ($route['handler'])($request, $params);
        }

        return Response::notFound();
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private static function compile(string $pattern): array
    {
        $params = [];
        $regex = preg_replace_callback(
            '/\{(\w+)(:[^}]+)?\}/',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                $constraint = isset($m[2]) ? substr($m[2], 1) : '[^/]+';

                return '(?P<' . $m[1] . '>' . $constraint . ')';
            },
            $pattern
        );

        return ['#^' . ($regex ?? $pattern) . '$#', $params];
    }
}
