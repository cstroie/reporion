<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\LibraryException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ApiResponse;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Index\IndexInterface;
use Reporion\Service\Library\Library;
use Reporion\Storage\FileTree;

/**
 * The library's endpoints (docs/library.md, phase 1):
 *
 * POST /api/v1/library/uploads?name={file name}: the file itself as the body (as media), read and
 * answered with the proposed record and a token; nothing kept but the temporary upload.
 * POST /api/v1/library {token, csl, key?, tags?}: the confirmed record, made a library page.
 * GET /library/file/{pid}: a source's original, to a signed-in caller who may see its page.
 *
 * An editor of the library's namespace adds; anyone else gets 404 (invariant 9).
 */
final class LibraryController
{
    private const TYPES = [
        'pdf' => 'application/pdf', 'txt' => 'text/plain; charset=utf-8', 'md' => 'text/markdown; charset=utf-8', 'markdown' => 'text/markdown; charset=utf-8',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'odt' => 'application/vnd.oasis.opendocument.text',
        'rtf' => 'application/rtf', 'epub' => 'application/epub+zip', 'doc' => 'application/msword', 'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'odp' => 'application/vnd.oasis.opendocument.presentation',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
    ];

    public function __construct(
        private readonly Library $library,
        private readonly FileTree $storage,
        private readonly IndexInterface $index,
        private readonly AuditLog $audit,
    ) {
    }

    public function upload(Request $request, ?User $principal): Response
    {
        if ($principal === null || !$this->library->canAdd($principal)) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        try {
            $proposal = $this->library->upload($request->body, (string) ($request->query['name'] ?? ''), $principal);
        } catch (LibraryException $e) {
            return $this->failure($e);
        }

        return ApiResponse::json($proposal, 201);
    }

    public function confirm(Request $request, ?User $principal): Response
    {
        if ($principal === null || !$this->library->canAdd($principal)) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        $fields = $request->json();
        if (!\is_string($fields['token'] ?? null) || !\is_array($fields['csl'] ?? null)) {
            return ApiResponse::error(422, 'invalid_body', '"token" (string) and "csl" (object) are required.');
        }
        try {
            $page = $this->library->confirm($fields['token'], $fields, $principal, $principal->username);
        } catch (LibraryException $e) {
            return $this->failure($e, $principal);
        }
        $original = (array) $page->frontmatter['original'];
        $this->audit->record('library.add', $principal->username, $request, $page->pid, $page->path, $page->rev, extra: [
            'sha256' => (string) ($original['sha256'] ?? ''),
            'bytes' => (int) ($original['bytes'] ?? 0),
            'ext' => (string) ($original['ext'] ?? ''),
        ]);

        return ApiResponse::json([
            'pid' => $page->pid,
            'path' => $page->path,
            'rev' => $page->rev,
            'key' => substr($page->path, \strlen($this->library->namespace()) + 1),
            'href' => $request->basePath . '/' . $page->path,
        ], 201);
    }

    public function original(Request $request, string $pid, ?User $principal): Response
    {
        $notFound = new Response(404, 'Not found', ['Content-Type' => 'text/plain; charset=utf-8']);
        $row = $principal !== null ? $this->index->findByPid($pid, $principal) : null;
        if ($row === null) {
            return $notFound;
        }
        try {
            $page = $this->storage->read((string) $row['path']);
        } catch (PageNotFoundException) {
            return $notFound;
        }
        $original = $page->frontmatter['original'] ?? null;
        $file = \is_array($original) ? $this->storage->originalFile((string) ($original['sha256'] ?? ''), (string) ($original['ext'] ?? '')) : null;
        $bytes = $file !== null ? file_get_contents($file) : false;
        if ($bytes === false) {
            return $notFound;
        }
        $ext = (string) $original['ext'];
        $segments = explode(':', $page->path);
        $name = end($segments) . '.' . $ext;

        return new Response(200, $bytes, [
            // A PDF opens in the browser; anything else is downloaded, never rendered from this origin
            'Content-Type' => $ext === 'pdf' ? self::TYPES['pdf'] : (self::TYPES[$ext] ?? 'application/octet-stream'),
            'Content-Disposition' => ($ext === 'pdf' ? 'inline' : 'attachment') . '; filename="' . $name . '"',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function failure(LibraryException $e, ?User $principal = null): Response
    {
        $status = match ($e->reason) {
            LibraryException::TOO_LARGE => 413,
            LibraryException::UNSUPPORTED => 415,
            LibraryException::DUPLICATE => 409,
            LibraryException::EXPIRED => 410,
            LibraryException::TOOL_MISSING => 503,
            LibraryException::TIMEOUT => 504,
            default => 422,
        };
        $args = $e->reason === LibraryException::TOO_LARGE ? [(string) intdiv($this->library->maxBytes(), 1024 * 1024)] : [];
        $fields = null;
        // The article already there, when the caller may see it
        if ($e->pid !== null && ($row = $this->index->findByPid($e->pid, $principal)) !== null) {
            $fields = ['pid' => $e->pid, 'path' => (string) $row['path']];
        }

        return ApiResponse::error($status, $e->reason, t('library.err_' . $e->reason, $args), $fields);
    }
}
