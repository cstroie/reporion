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
 * read the wiki as a bearer token's account sees it; a write token may also create and update
 * ordinary pages (phase 4: write()). Every lookup goes through the
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
    public const VERSION = '0.2.0';
    /**
     * Admin → Settings `mcp.access`: off, local network only (Claude Code), everywhere with a token
     * only (a client that sends its own Authorization header, as claude.ai's custom connector can:
     * no OAuth endpoint answers), everywhere with OAuth sign-in too
     */
    public const ACCESS = ['off', 'local', 'token', 'on'];
    private const LIMIT = 20;

    public function __construct(
        private readonly IndexInterface $index,
        private readonly StorageInterface $storage,
        private readonly AuditLog $audit,
        // Admin → Settings `mcp.reports` (phase 2): patient pages served de-identified, by pid
        private readonly bool $reports = false,
        // site.base_url: a 401 then names the OAuth metadata (RFC 9728), so a client can connect on its own
        private readonly string $baseUrl = '',
        // Phase 4: the writes go through the pages API (journal, audit, conflicts, grants as there)
        private readonly ?PagesApiController $pages = null,
        /** @var ?\Closure(Request): bool whether the caller's token is a write token */
        private readonly ?\Closure $writes = null,
        private readonly string $access = 'on',
    ) {
    }

    /**
     * Whether /mcp answers this request at all (Admin → Settings `mcp.access`). Asked before the
     * token is looked at: a disabled server must not tell a bad token from a good one (404 for
     * both) nor touch the account behind it.
     */
    public function available(Request $request): bool
    {
        return !($this->access === 'off' || ($this->access === 'local' && !self::isLocal($request)));
    }

    public function handle(Request $request, ?User $principal): Response
    {
        // Off, or local only and asked from outside: as if there were no MCP server (no hint, no OAuth pointer)
        if (!$this->available($request)) {
            return new Response(404, '', ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        if ($principal === null) {
            return new Response(401, '', [
                'WWW-Authenticate' => 'Bearer realm="reporion"' . ($this->baseUrl !== '' && $this->access === 'on' ? ', resource_metadata="' . $this->baseUrl . '/.well-known/oauth-protected-resource"' : ''),
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
                'serverInfo' => $this->serverInfo(),
                'instructions' => $this->reports
                    ? 'Reporion wiki pages as this account sees them. Address a page by its colon path (ns:sub:slug); a radiology report by its pid only. Reports are de-identified: [pacient], [CNP] and [nr] stand for what was taken out; never try to recover them.'
                    : 'Reporion wiki pages as this account sees them. Address a page by its colon path (ns:sub:slug). Reports and pages about a patient are not served.',
            ]),
            'ping' => self::result($id, []),
            'tools/list' => self::result($id, ['tools' => [...self::tools($this->reports), ...($this->canWrite($request) ? self::writeTools() : [])]]),
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
            'create_page', 'update_page' => [null, 0],
            default => [null, -1],
        };
        if ($count === -1) {
            return self::failure($id, -32602, 'Unknown tool');
        }
        if ($name === 'create_page' || $name === 'update_page') {
            return $this->write($id, $name, $args, $request, $principal);
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

    /**
     * The server as the client lists it (MCP `Implementation`): name, title, version, and, with
     * site.base_url, the app's own icons and address, so the client shows Reporion's mark
     *
     * @return array<string, mixed>
     */
    private function serverInfo(): array
    {
        $info = ['name' => 'reporion', 'title' => 'Reporion', 'version' => self::VERSION];
        if ($this->baseUrl !== '') {
            $info['websiteUrl'] = $this->baseUrl . '/';
            $info['icons'] = [
                ['src' => $this->baseUrl . '/assets/icon.svg', 'mimeType' => 'image/svg+xml', 'sizes' => ['any']],
                ['src' => $this->baseUrl . '/assets/apple-touch-icon.png', 'mimeType' => 'image/png', 'sizes' => ['180x180']],
                ['src' => $this->baseUrl . '/assets/favicon.ico', 'mimeType' => 'image/x-icon', 'sizes' => ['48x48']],
            ];
        }

        return $info;
    }

    /**
     * From this machine or a private network (VPN, LAN): the address the request came from, and
     * every address a proxy says it forwarded for. A proxy on the LAN in front of an internet
     * client passes the client's public address on, which makes the request not local.
     */
    public static function isLocal(Request $request): bool
    {
        $addresses = [$request->remoteAddr, ...array_map('trim', $request->forwardedFor === '' ? [] : explode(',', $request->forwardedFor))];
        foreach ($addresses as $ip) {
            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return false;
            }
            // Private or loopback/reserved: both filters refuse it as public
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                return false;
            }
        }

        return true;
    }

    private function canWrite(Request $request): bool
    {
        return $this->pages !== null && $this->writes !== null && ($this->writes)($request);
    }

    /** @return list<array<string, mixed>> */
    private static function writeTools(): array
    {
        $string = static fn (string $d): array => ['type' => 'string', 'description' => $d];
        $write = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false];

        return [
            ['name' => 'create_page', 'description' => 'Create a new page at a path where none exists. The body is markdown; its first # heading is the title unless a title is given. Never a report or a page about a patient.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'path' => $string('The new page path, e.g. docs:howto:restore'),
                    'body' => $string('The page text, markdown'),
                    'title' => $string('Optional title, when the text has no # heading'),
                    'note' => $string('A short note for the revision history'),
                ], 'required' => ['path', 'body']],
                'annotations' => $write],
            ['name' => 'update_page', 'description' => 'Replace the text of an existing page, as a new revision (history keeps the old one). Read it with get_page first and pass its rev as base_rev; if someone saved since, nothing is saved and the current text comes back. Frontmatter is kept. Not for signed pages, reports or pages about a patient.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'path' => $string('The page path'),
                    'body' => $string('The whole new text, markdown'),
                    'base_rev' => ['type' => 'integer', 'description' => 'The rev get_page returned'],
                    'note' => $string('A short note for the revision history: what changed'),
                ], 'required' => ['path', 'body', 'base_rev']],
                'annotations' => $write],
        ];
    }

    /**
     * create_page / update_page through the pages API, as the token's account (D56, phase 4).
     * Never a patient page (a report path, or a page naming a patient), never a signed page:
     * signing and correcting reports stay in the editor (D3, D8, D37). The revision note says
     * it came through MCP.
     *
     * @param array<mixed> $args
     *
     * @return array<string, mixed>
     */
    private function write(mixed $id, string $tool, array $args, Request $request, User $principal): array
    {
        $refuse = function (string $why) use ($id, $tool, $request, $principal): array {
            $this->audit->record('mcp.call', $principal->username, $request, outcome: 'refused', extra: ['tool' => $tool]);

            return self::result($id, ['content' => [['type' => 'text', 'text' => $why]], 'isError' => true]);
        };
        if (!$this->canWrite($request)) {
            return $refuse('This connection is read only.');
        }
        $path = \is_string($args['path'] ?? null) ? trim($args['path'], " :") : '';
        $body = \is_string($args['body'] ?? null) ? $args['body'] : null;
        if ($path === '' || $body === null) {
            return $refuse('path and body are required.');
        }
        if (ReportPath::isReport($path) || ReportPath::isReportNamespace($path)) {
            return $refuse('Reports are written in Reporion\'s editor, not here.');
        }
        $userNote = \is_string($args['note'] ?? null) ? trim($args['note']) : '';
        $note = ($userNote !== '' ? $userNote . ' ' : '') . '(via MCP)';

        if ($tool === 'create_page') {
            $title = \is_string($args['title'] ?? null) ? trim($args['title']) : '';
            $fields = ['path' => $path, 'note' => $note];
            $fields += $title !== ''
                ? ['document' => "---\ntitle: " . json_encode($title, JSON_UNESCAPED_UNICODE) . "\n---\n\n" . $body]
                : ['body' => $body];
            $response = $this->pages->create(self::inner($request, 'POST', '/api/v1/pages', $fields), $principal);
        } else {
            $baseRev = $args['base_rev'] ?? null;
            if (!\is_int($baseRev)) {
                return $refuse('base_rev (an integer, from get_page) is required.');
            }
            $row = $this->index->findByPath($path, $principal);
            try {
                $record = $row === null ? null : $this->storage->read($path);
            } catch (RuntimeException) {
                $record = null;
            }
            if ($record === null || !$principal->canWrite($path)) {
                return $refuse('Not found, or not yours to change.');
            }
            if (self::isPatientPage($path, $row) || isset($record->frontmatter['patient'])) {
                return $refuse('Pages about a patient are written in Reporion\'s editor, not here.');
            }
            if ($record->status === 'signed') {
                return $refuse('This page is signed: a change is a correction, made and signed again in Reporion.');
            }
            $response = $this->pages->save(self::inner($request, 'PUT', '/api/v1/pages/' . $path, ['meta' => $record->frontmatter, 'body' => $body, 'base_rev' => $baseRev, 'note' => $note]), $path, $principal);
        }

        $answer = json_decode($response->body, true);
        $answer = \is_array($answer) ? $answer : [];
        $this->audit->record('mcp.call', $principal->username, $request, \is_string($answer['pid'] ?? null) ? $answer['pid'] : null, outcome: $response->status < 300 ? 'ok' : 'refused', extra: ['tool' => $tool, 'status' => $response->status]);
        if ($response->status === 409 && \is_array($answer['current'] ?? null)) {
            return self::result($id, ['content' => [['type' => 'text', 'text' => 'Not saved: the page changed since base_rev. Its current revision and text follow; merge your change into it and call update_page again with this rev.' . "\n\n" . json_encode(['rev' => $answer['current']['rev'] ?? null, 'body' => $answer['current']['body'] ?? null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]], 'isError' => true]);
        }
        if ($response->status >= 300) {
            $message = \is_string($answer['error']['message'] ?? null) ? $answer['error']['message'] : 'Not saved.';

            return self::result($id, ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true]);
        }
        $saved = ['path' => $answer['path'] ?? $path, 'rev' => $answer['rev'] ?? null, 'href' => $request->basePath . '/' . ($answer['path'] ?? $path)];

        return self::result($id, ['content' => [['type' => 'text', 'text' => (string) json_encode($saved, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]], 'structuredContent' => $saved]);
    }

    /** @param array<string, mixed> $json a request to the pages API, carrying this one's caller details for the audit */
    private static function inner(Request $request, string $method, string $path, array $json): Request
    {
        return new Request($method, $path, body: (string) json_encode($json, JSON_UNESCAPED_UNICODE), basePath: $request->basePath, remoteAddr: $request->remoteAddr, userAgent: $request->userAgent, secure: $request->secure);
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
