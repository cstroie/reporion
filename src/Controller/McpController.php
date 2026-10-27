<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Index\IndexInterface;
use Reporion\Storage\StorageInterface;
use Reporion\Support\ReportPath;
use RuntimeException;

/**
 * POST /mcp (D56, phase 1): a Model Context Protocol server over Streamable HTTP, answering
 * JSON-RPC with one JSON response (no event stream), so Claude Code, claude.ai and Desktop can
 * read the wiki as a bearer token's account sees it. Read only. Every lookup goes through the
 * index under the caller (invariant 6); a token limited to namespaces is already narrowed
 * (User::narrowedTo()). Phase 1 serves no patient page: a report or a page with a `patient` block
 * is left out of every answer and is "not found" when asked for (invariant 8): phase 2 adds a
 * de-identified view. Audited `mcp.call` with the tool and counts, never a query or text.
 */
final class McpController
{
    public const PROTOCOL = '2025-06-18';
    public const VERSION = '0.1.0';
    private const LIMIT = 20;

    public function __construct(
        private readonly IndexInterface $index,
        private readonly StorageInterface $storage,
        private readonly AuditLog $audit,
    ) {
    }

    public function handle(Request $request, ?User $principal): Response
    {
        if ($principal === null) {
            return new Response(401, '', [
                'WWW-Authenticate' => 'Bearer realm="reporion"',
                'Content-Type' => 'application/json; charset=utf-8',
            ]);
        }
        if ($request->method !== 'POST') {
            return new Response(405, '', ['Allow' => 'POST']);
        }

        $message = json_decode($request->body, true);
        if (!\is_array($message)) {
            return self::json(self::failure(null, -32700, 'Parse error'));
        }
        // A batch (allowed by older protocol versions): each answered, notifications dropped
        if (array_is_list($message)) {
            $answers = array_values(array_filter(array_map(fn (mixed $m): ?array => \is_array($m) ? $this->dispatch($m, $request, $principal) : self::failure(null, -32600, 'Invalid request'), $message)));

            return $answers === [] ? new Response(202) : self::json($answers);
        }
        $answer = $this->dispatch($message, $request, $principal);

        return $answer === null ? new Response(202) : self::json($answer);
    }

    /**
     * @param array<mixed> $m
     *
     * @return ?array<string, mixed> null for a notification
     */
    private function dispatch(array $m, Request $request, User $principal): ?array
    {
        $id = $m['id'] ?? null;
        $method = \is_string($m['method'] ?? null) ? $m['method'] : '';
        if (!\array_key_exists('id', $m)) {
            return null;
        }
        $params = \is_array($m['params'] ?? null) ? $m['params'] : [];

        return match ($method) {
            'initialize' => self::result($id, [
                'protocolVersion' => \is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : self::PROTOCOL,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'reporion', 'version' => self::VERSION],
                'instructions' => 'Reporion wiki pages as this account sees them. Address a page by its colon path (ns:sub:slug). Reports and pages about a patient are not served.',
            ]),
            'ping' => self::result($id, []),
            'tools/list' => self::result($id, ['tools' => self::tools()]),
            'tools/call' => $this->call($id, $params, $request, $principal),
            default => self::failure($id, -32601, 'Method not found'),
        };
    }

    /** @return list<array<string, mixed>> */
    private static function tools(): array
    {
        $string = static fn (string $d): array => ['type' => 'string', 'description' => $d];

        return [
            ['name' => 'search', 'description' => 'Full-text search of the wiki pages this account may read. Returns paths, titles and snippets.',
                'inputSchema' => ['type' => 'object', 'properties' => ['query' => $string('Words to find'), 'namespace' => $string('Only under this namespace, e.g. docs:radiology')], 'required' => ['query']],
                'annotations' => ['readOnlyHint' => true]],
            ['name' => 'get_page', 'description' => 'One page: its markdown text, frontmatter, revision and status.',
                'inputSchema' => ['type' => 'object', 'properties' => ['path' => $string('The page path, e.g. docs:howto:backup')], 'required' => ['path']],
                'annotations' => ['readOnlyHint' => true]],
            ['name' => 'list_namespace', 'description' => 'The pages and sub-namespaces directly in a namespace.',
                'inputSchema' => ['type' => 'object', 'properties' => ['namespace' => $string('The namespace, e.g. docs')], 'required' => ['namespace']],
                'annotations' => ['readOnlyHint' => true]],
        ];
    }

    /**
     * @param array<mixed> $params
     *
     * @return array<string, mixed>
     */
    private function call(mixed $id, array $params, Request $request, User $principal): array
    {
        $name = \is_string($params['name'] ?? null) ? $params['name'] : '';
        $args = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $arg = static fn (string $k): string => \is_string($args[$k] ?? null) ? trim($args[$k]) : '';

        [$payload, $count] = match ($name) {
            'search' => $this->search($arg('query'), trim($arg('namespace'), ':'), $principal),
            'get_page' => $this->page(trim($arg('path'), ':'), $principal),
            'list_namespace' => $this->listing(trim($arg('namespace'), ':'), $principal),
            default => [null, 0],
        };
        if ($payload === null && $count === 0 && !\in_array($name, ['search', 'get_page', 'list_namespace'], true)) {
            return self::failure($id, -32602, 'Unknown tool');
        }
        $this->audit->record('mcp.call', $principal->username, $request, extra: ['tool' => $name, 'results' => $count]);
        if ($payload === null) {
            return self::result($id, ['content' => [['type' => 'text', 'text' => 'Not found.']], 'isError' => true]);
        }

        return self::result($id, [
            'content' => [['type' => 'text', 'text' => (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]],
            'structuredContent' => $payload,
        ]);
    }

    /** @return array{0: ?array<string, mixed>, 1: int} */
    private function search(string $query, string $ns, User $principal): array
    {
        if ($query === '') {
            return [null, 0];
        }
        $hits = [];
        foreach ($this->index->search($query, $principal, 'relevance', $ns, [], self::LIMIT * 2) as $row) {
            if (!self::servable((string) $row['path'], $row) || \count($hits) >= self::LIMIT) {
                continue;
            }
            $hits[] = ['path' => (string) $row['path'], 'title' => (string) ($row['title'] ?? ''), 'snippet' => (string) ($row['snippet'] ?? ''), 'updated' => (string) ($row['updated'] ?? '')];
        }

        return [['results' => $hits], \count($hits)];
    }

    /** @return array{0: ?array<string, mixed>, 1: int} */
    private function page(string $path, User $principal): array
    {
        $row = $path !== '' ? $this->index->findByPath($path, $principal) : null;
        if ($row === null || !self::servable($path, $row)) {
            return [null, 0];
        }
        try {
            $record = $this->storage->read($path);
        } catch (RuntimeException) {
            return [null, 0];
        }
        if (isset($record->frontmatter['patient'])) {
            return [null, 0];
        }

        return [[
            'path' => $record->path,
            'rev' => $record->rev,
            'status' => $record->status,
            'visibility' => $record->visibility,
            'meta' => $record->frontmatter,
            'body' => $record->body,
        ], 1];
    }

    /** @return array{0: ?array<string, mixed>, 1: int} */
    private function listing(string $ns, User $principal): array
    {
        if ($ns === '' || ReportPath::isReportNamespace($ns)) {
            return [null, 0];
        }
        $pages = [];
        foreach ($this->index->listNamespace($ns, $principal) as $row) {
            if (self::servable((string) $row['path'], $row)) {
                $pages[] = ['path' => (string) $row['path'], 'title' => (string) ($row['title'] ?? '')];
            }
        }
        $subs = [];
        foreach ($this->index->listSubnamespaces($ns, $principal) as $sub) {
            $full = $ns . ':' . $sub['name'];
            if (!ReportPath::isReportNamespace($full)) {
                $subs[] = ['namespace' => $full, 'pages' => $sub['count']];
            }
        }

        return [['namespace' => $ns, 'pages' => $pages, 'namespaces' => $subs], \count($pages)];
    }

    /**
     * Phase 1: no patient page leaves the box. A report, or a page whose index row names a patient
     *
     * @param array<string, mixed> $row
     */
    private static function servable(string $path, array $row): bool
    {
        return !ReportPath::isReport($path) && !ReportPath::isReportNamespace($path)
            && ($row['patient_key'] ?? null) === null && ($row['patient_key_weak'] ?? null) === null;
    }

    /** @return array<string, mixed> */
    private static function result(mixed $id, array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result === [] ? new \stdClass() : $result];
    }

    /** @return array<string, mixed> */
    private static function failure(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /** @param array<mixed> $data */
    private static function json(array $data): Response
    {
        return new Response(200, (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ['Content-Type' => 'application/json; charset=utf-8']);
    }
}
