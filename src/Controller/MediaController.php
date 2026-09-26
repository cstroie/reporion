<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ApiResponse;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Index\IndexInterface;
use Reporion\Storage\FlatFile;
use Reporion\Support\MediaRef;

/**
 * Images pasted or dropped into the editor (D27, decided 2026-09-26).
 *
 * POST /api/v1/media?page={path}&name={file name} — the image itself as the
 * request body (no multipart: the editor sends the File as it is). Stored
 * content addressed and attached to the page (Storage::attachMedia()); the
 * answer carries the markdown to insert. Write access to the page.
 *
 * GET /media/{sha256}.{ext} — only to a caller who can open some page the
 * file is attached to; 404 otherwise, never 403 (invariant 9).
 */
final class MediaController
{
    public function __construct(
        private readonly FlatFile $storage,
        private readonly IndexInterface $index,
        private readonly AuditLog $audit,
        private readonly int $maxBytes,
    ) {
    }

    public function upload(Request $request, ?User $principal): Response
    {
        $path = $request->query['page'] ?? '';
        if ($principal === null || $path === '' || !$principal->canWrite($path)) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        if ($request->body === '') {
            return ApiResponse::error(422, 'empty', t('media.err_empty'));
        }
        if (\strlen($request->body) > $this->maxBytes) {
            return ApiResponse::error(413, 'too_large', t('media.err_too_large', [(string) intdiv($this->maxBytes, 1024 * 1024)]));
        }

        try {
            $entry = $this->storage->attachMedia($path, $request->body, $request->query['name'] ?? '', $principal->username);
            $page = $this->storage->read($path);
        } catch (PageNotFoundException) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        } catch (InvalidArgumentException) {
            return ApiResponse::error(422, 'unsupported_type', t('media.err_type'));
        }
        $this->audit->record('media.attach', $principal->username, $request, $page->pid, $page->path, $page->rev, extra: [
            'sha256' => $entry['sha256'],
            'bytes' => $entry['bytes'],
        ]);

        return ApiResponse::json([
            'sha256' => $entry['sha256'],
            'ext' => $entry['ext'],
            'name' => $entry['name'],
            'w' => $entry['w'],
            'h' => $entry['h'],
            'url' => $request->basePath . '/media/' . $entry['sha256'] . '.' . $entry['ext'],
            'markdown' => MediaRef::markdown(pathinfo($entry['name'], PATHINFO_FILENAME), $entry['sha256'], $entry['ext']),
        ], 201);
    }

    public function show(Request $request, string $sha256, string $ext, ?User $principal): Response
    {
        $file = $this->storage->mediaFile($sha256, $ext);
        if ($file === null || !$this->index->canSeeMedia($sha256 . '.' . $ext, $principal)) {
            return new Response(404, 'Not found', ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $bytes = file_get_contents($file);
        if ($bytes === false) {
            return new Response(404, 'Not found', ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return new Response(200, $bytes, [
            'Content-Type' => ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'][$ext],
            // Content addressed: the bytes behind this URL never change. Private,
            // because whether you may see them depends on who you are.
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
