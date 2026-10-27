<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Index\IndexInterface;
use Reporion\Service\Mcp\ReportView;
use Reporion\Storage\StorageInterface;
use Reporion\Support\ReportPath;
use RuntimeException;

/**
 * POST /mcp (D56, phase 1): a Model Context Protocol server over Streamable HTTP, answering
 * JSON-RPC with one JSON response (no event stream), so Claude Code, claude.ai and Desktop can
 * read the wiki as a bearer token's account sees it. Read only. Every lookup goes through the
 * index under the caller (invariant 6); a token limited to namespaces is already narrowed
 * (User::narrowedTo()). A patient page (a report, or any page naming a patient) is left out of
 * every answer unless `mcp.reports` is on; then it is shown by pid only, de-identified
 * (Service\Mcp\ReportView), and withheld when an identifier survives (invariant 8). Its path never
 * leaves: a report is asked for by pid, never by path. Audited `mcp.call` with the tool, a count
 * and a pid, never a query, path or text.
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
        // Admin → Settings `mcp.reports` (phase 2): patient pages served de-identified, by pid
        private readonly bool $reports = false,
        // site.base_url: a 401 then names the OAuth metadata (RFC 9728), so a client can connect on its own
        private readonly string $baseUrl = '',
    ) {
    }

    public function handle(Request $request, ?User $principal): Response
    {
        if ($principal === null) {
            return new Response(401, '', [
                'WWW-Authenticate' => 'Bearer realm="reporion"' . ($this->baseUrl !== '' ? ', resource_metadata="' . $this->baseUrl . '/.well-known/oauth-protected-resource"' : ''),
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
                'instructions' => $this->reports
                    ? 'Reporion wiki pages as this account sees them. Address a page by its colon path (ns:sub:slug); a radiology report by its pid only. Reports are de-identified: [pacient], [CNP] and [nr] stand for what was taken out; never try to recover them.'
                    : 'Reporion wiki pages as this account sees them. Address a page by its colon path (ns:sub:slug). Reports and pages about a patient are not served.',
            ]),
            'ping' => self::result($id, []),
            'tools/list' => self::result($id, ['tools' => self::tools($this->reports)]),
            'tools/call' => $this->call($id, $params, $request, $principal),
            default => self::failure($id, -32601, 'Method not found'),
        };
    }

    /** @return list<array<string, mixed>> */
    private static function tools(bool $reports): array
    {
        $string = static fn (string $d): array => ['type' => 'string', 'description' => $d];

        return [
            ['name' => 'search', 'description' => 'Full-text search of the wiki pages this account may read. Returns paths, titles and snippets.',
                'inputSchema' => ['type' => 'object', 'properties' => ['query' => $string('Words to find'), 'namespace' => $string('Only under this namespace, e.g. docs:radiology')], 'required' => ['query']],
                'annotations' => ['readOnlyHint' => true]],
            ['name' => 'get_page', 'description' => $reports
                    ? 'One page: its markdown text, frontmatter, revision and status. A report (a search result with a pid and no path) is asked for by its pid and comes de-identified.'
                    : 'One page: its markdown text, frontmatter, revision and status.',
                'inputSchema' => ['type' => 'object', 'properties' => $reports
                    ? ['path' => $string('The page path, e.g. docs:howto:backup'), 'pid' => $string('A report\'s pid, from a search result')]
                    : ['path' => $string('The page path, e.g. docs:howto:backup')]],
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
            'get_page' => $this->page(trim($arg('path'), ':'), $arg('pid'), $principal, $request->basePath),
            'list_namespace' => $this->listing(trim($arg('namespace'), ':'), $principal),
            default => [null, 0],
        };
        if ($payload === null && $count === 0 && !\in_array($name, ['search', 'get_page', 'list_namespace'], true)) {
            return self::failure($id, -32602, 'Unknown tool');
        }
        $this->audit->record('mcp.call', $principal->username, $request, \is_string($payload['pid'] ?? null) ? $payload['pid'] : null, extra: ['tool' => $name, 'results' => $count]);
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
            if (\count($hits) >= self::LIMIT) {
                break;
            }
            if (!self::isPatientPage((string) $row['path'], $row)) {
                $hits[] = ['path' => (string) $row['path'], 'title' => (string) ($row['title'] ?? ''), 'snippet' => (string) ($row['snippet'] ?? ''), 'updated' => (string) ($row['updated'] ?? '')];
                continue;
            }
            if (!$this->reports) {
                continue;
            }
            try {
                $record = $this->storage->read((string) $row['path']);
            } catch (RuntimeException) {
                continue;
            }
            // Withheld when its title cannot be shown without an identifier
            $title = ReportView::title($record);
            if ($title !== '') {
                $hits[] = ['pid' => $record->pid, 'title' => $title, 'snippet' => ReportView::snippet($record, (string) ($row['snippet'] ?? '')), 'study_date' => $row['study_date'] ?? null, 'status' => $record->status];
            }
        }

        return [['results' => $hits], \count($hits)];
    }

    /** @return array{0: ?array<string, mixed>, 1: int} */
    private function page(string $path, string $pid, User $principal, string $basePath): array
    {
        // A patient page by pid only (its path names the patient): asked by path, it is not found
        $row = $pid !== '' ? ($this->reports ? $this->index->findByPid($pid, $principal) : null)
            : ($path !== '' ? $this->index->findByPath($path, $principal) : null);
        if ($row === null) {
            return [null, 0];
        }
        try {
            $record = $this->storage->read((string) $row['path']);
        } catch (RuntimeException) {
            return [null, 0];
        }
        $patient = self::isPatientPage($record->path, $row) || isset($record->frontmatter['patient']);
        if ($patient) {
            $view = $pid !== '' ? ReportView::of($record, $basePath) : null;

            return $view === null ? [null, 0] : [$view, 1];
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
            if (!self::isPatientPage((string) $row['path'], $row)) {
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
     * A page about a patient: a report, or one whose index row names a patient. Never shown as it is
     *
     * @param array<string, mixed> $row
     */
    private static function isPatientPage(string $path, array $row): bool
    {
        return ReportPath::isReport($path) || ReportPath::isReportNamespace($path)
            || ($row['patient_key'] ?? null) !== null || ($row['patient_key_weak'] ?? null) !== null;
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
