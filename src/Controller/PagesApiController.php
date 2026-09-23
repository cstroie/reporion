<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Exception\PageNotFoundException;
use Reporion\Exception\RevisionConflictException;
use Reporion\Http\ApiResponse;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;

/**
 * POST /pages, PUT /pages/{path} (docs/architecture-api.md Table 2 —
 * Pages). Owner-only, 404 for anonymous — same reasoning as
 * RenderController: these are writes, not reads of a public page, and are
 * not on Table 4's public surface.
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
    public function create(Request $request, bool $isOwner): Response
    {
        if (!$isOwner) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }

        $fields = $request->json();
        $path = $fields['path'] ?? null;
        $meta = $fields['meta'] ?? null;
        $body = $fields['body'] ?? '';

        if (!\is_string($path) || $path === '' || !\is_array($meta) || !\is_string($body)) {
            return ApiResponse::error(422, 'invalid_body', '"path" (string) and "meta" (object) are required.');
        }

        try {
            $record = $this->storage->create($path, $meta, $body, 'owner');
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error(422, 'invalid_path', 'The given path is not valid.');
        }

        return $this->recordResponse($record, 201);
    }

    /**
     * PUT /pages/{path} { meta, body?, base_rev } -> 200 { pid, path, rev },
     * or 409 { error: { code: 'conflict' }, current, submitted_base_rev }
     * with both bodies for the editor's three-way merge (A2).
     */
    public function save(Request $request, string $path, bool $isOwner): Response
    {
        if (!$isOwner) {
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
            $record = $this->storage->save($path, $meta, $body, $baseRev, 'owner');
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
    public function delete(Request $request, string $path, bool $isOwner): Response
    {
        if (!$isOwner) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }

        try {
            $this->storage->delete($path, 'owner');
        } catch (PageNotFoundException) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }

        return ApiResponse::json(['deleted' => true, 'path' => $path]);
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
