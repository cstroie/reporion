<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use InvalidArgumentException;
use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Exception\RevisionConflictException;
use Reporion\Http\ApiResponse;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;

/**
 * POST /pages, PUT /pages/{path}, DELETE /pages/{path} (docs/architecture-api.md
 * Table 2 — Pages). D35–D37: a write requires an `editor` (or `owner`)
 * grant covering the target path's namespace — not just being signed in.
 * Refused as 404, not 401/403, same as an anonymous caller: these are
 * writes, not reads of a public page, and their existence is not
 * information worth confirming to a caller who cannot use them either way
 * (invariant 9's "404, never 403" reasoning extended past anonymous).
 *
 * Every response is written through Storage with the real signed-in
 * username as the actor, not a fixed 'owner' string — meta.json's
 * append-only revlog is what "who wrote this" (D35/D37: whoever holds the
 * write grant signs their own work) actually depends on.
 *
 * Idempotency-Key (docs/FORMATS.md §7) is NOT implemented here — that is
 * its own small subsystem (data/idempotency.sqlite) and a deliberate
 * scope cut, not an oversight (see docs/BUILD_LOG.md).
 */
final class PagesApiController
{
    public function __construct(
        private readonly StorageInterface $storage,
    ) {
    }

    /**
     * POST /pages { path, meta, body? } -> 201 { pid, path, rev }.
     */
    public function create(Request $request, ?User $principal): Response
    {
        // A coarse gate, not the real check: the target namespace lives in
        // the JSON body, not a route parameter, so there is nothing to run
        // canWrite() against yet. This only rules out a caller who could
        // never create a page anywhere (anonymous, or a pure viewer) —
        // still 404 for them regardless of what they submit, matching
        // invariant 9's "never confirm a restricted route's existence."
        if ($principal === null || !$principal->hasAnyWriteAccess()) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }

        $fields = $request->json();
        $path = $fields['path'] ?? null;
        $meta = $fields['meta'] ?? null;
        $body = $fields['body'] ?? '';

        if (!\is_string($path) || $path === '' || !\is_array($meta) || !\is_string($body)) {
            return ApiResponse::error(422, 'invalid_body', '"path" (string) and "meta" (object) are required.');
        }

        // Now that $path is well-formed, the real per-namespace check: a
        // real editor grant elsewhere still gets 404 here, not 422 — the
        // path was fine, they just aren't entitled to write under it.
        if (!$principal->canWrite($path)) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }

        try {
            $record = $this->storage->create($path, $meta, $body, $principal->username);
        } catch (InvalidArgumentException) {
            return ApiResponse::error(422, 'invalid_path', 'The given path is not valid.');
        }

        return $this->recordResponse($record, 201);
    }

    /**
     * PUT /pages/{path} { meta, body?, base_rev } -> 200 { pid, path, rev },
     * or 409 { error: { code: 'conflict' }, current, submitted_base_rev }
     * with both bodies for the editor's three-way merge (A2).
     */
    public function save(Request $request, string $path, ?User $principal): Response
    {
        if ($principal === null || !$principal->canWrite($path)) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }

        $fields = $request->json();
        $meta = $fields['meta'] ?? null;
        $body = $fields['body'] ?? '';
        $baseRev = $fields['base_rev'] ?? null;

        if (!\is_array($meta) || !\is_string($body) || !\is_int($baseRev)) {
            return ApiResponse::error(422, 'invalid_body', '"meta" (object), "body" (string) and "base_rev" (integer) are required.');
        }

        try {
            $record = $this->storage->save($path, $meta, $body, $baseRev, $principal->username);
        } catch (PageNotFoundException) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        } catch (RevisionConflictException $e) {
            return ApiResponse::json([
                'error' => ['code' => 'conflict', 'message' => 'The page has a newer revision than base_rev.'],
                'submitted_base_rev' => $e->submittedBaseRev,
                'current' => $this->recordPayload($e->current),
            ], 409);
        }

        return $this->recordResponse($record, 200);
    }

    /**
     * DELETE /pages/{path} -> 200 { deleted: true, path }. Soft delete only
     * (moves to trash/) — ?purge=1 (permanent, owner-only, audited per D3b)
     * is not implemented; there is no audit log infrastructure yet to
     * satisfy "writes an audit entry naming the operator" (see
     * docs/BUILD_LOG.md).
     */
    public function delete(Request $request, string $path, ?User $principal): Response
    {
        if ($principal === null || !$principal->canWrite($path)) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }

        try {
            $this->storage->delete($path, $principal->username);
        } catch (PageNotFoundException) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }

        return ApiResponse::json(['deleted' => true, 'path' => $path]);
    }

    /**
     * POST /pages/{path}/revert { to: 6 } -> 200 { pid, path, rev }. A2:
     * writes a brand new revision, never rewrites history — the response's
     * `rev` is always the *new* revision number, one past whatever was
     * current before this call, never `to` itself.
     */
    public function revert(Request $request, string $path, ?User $principal): Response
    {
        if ($principal === null || !$principal->canWrite($path)) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }

        $fields = $request->json();
        $to = $fields['to'] ?? null;

        if (!\is_int($to)) {
            return ApiResponse::error(422, 'invalid_body', '"to" (integer revision number) is required.');
        }

        try {
            $record = $this->storage->revert($path, $to, $principal->username);
        } catch (PageNotFoundException) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }

        return $this->recordResponse($record, 200);
    }

    private function recordResponse(PageRecord $record, int $status): Response
    {
        return ApiResponse::json($this->recordPayload($record), $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function recordPayload(PageRecord $record): array
    {
        return [
            'pid' => $record->pid,
            'path' => $record->path,
            'rev' => $record->rev,
            'status' => $record->status,
            'visibility' => $record->visibility,
            'meta' => $record->frontmatter,
            'body' => $record->body,
        ];
    }
}
