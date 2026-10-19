<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use InvalidArgumentException;
use Reporion\Auth\User;
use Reporion\Http\ApiResponse;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Index\IndexInterface;
use Reporion\Service\Comments;

/**
 * A page's comments (D52): `GET /api/v1/pages/{path}/comments`,
 * `POST /api/v1/pages/{path}/comments {text, quote?}` and
 * `POST /api/v1/pages/{path}/comments/{id} {action: resolve|reopen|delete}`.
 * For a signed-in caller who may read the page (the direct-access rule,
 * Index::findByPath()); anyone else gets 404 (invariant 9). Deleting is
 * the author's or an owner's: 403 `forbidden` otherwise.
 */
final class CommentsController
{
    public function __construct(
        private readonly IndexInterface $index,
        private readonly Comments $comments,
    ) {
    }

    public function list(Request $request, string $path, ?User $principal): Response
    {
        $row = $this->readable($path, $principal);
        if ($row === null) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }

        return ApiResponse::json(['data' => array_map(self::shown(...), $this->comments->of($path))]);
    }

    public function add(Request $request, string $path, ?User $principal): Response
    {
        $row = $this->readable($path, $principal);
        if ($row === null || $principal === null) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        $fields = $request->json();
        try {
            $comment = $this->comments->add($path, (string) $row['pid'], (int) $row['rev'], $principal, \is_string($fields['text'] ?? null) ? $fields['text'] : '', \is_string($fields['quote'] ?? null) ? $fields['quote'] : '', $request);
        } catch (InvalidArgumentException) {
            return ApiResponse::error(422, 'invalid_comment', t('comments.err_text', [Comments::MAX_TEXT]), ['text' => 'invalid']);
        }

        return ApiResponse::json(['data' => self::shown($comment)], 201);
    }

    public function change(Request $request, string $path, string $id, ?User $principal): Response
    {
        $row = $this->readable($path, $principal);
        if ($row === null || $principal === null) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        $action = $request->json()['action'] ?? null;
        $pid = (string) $row['pid'];
        if ($action === 'resolve' || $action === 'reopen') {
            return $this->comments->resolve($path, $pid, $id, $principal, $action === 'resolve', $request)
                ? ApiResponse::json(['data' => array_map(self::shown(...), $this->comments->of($path))])
                : ApiResponse::error(404, 'not_found', 'No such comment.');
        }
        if ($action === 'delete') {
            return match ($this->comments->delete($path, $pid, $id, $principal, $request)) {
                'deleted' => ApiResponse::json(['data' => array_map(self::shown(...), $this->comments->of($path))]),
                'forbidden' => ApiResponse::error(403, 'forbidden', t('comments.err_delete')),
                default => ApiResponse::error(404, 'not_found', 'No such comment.'),
            };
        }

        return ApiResponse::error(422, 'invalid_action', 'The action is resolve, reopen or delete.');
    }

    /** @return ?array<string, mixed> the page's index row, when the caller may read it */
    private function readable(string $path, ?User $principal): ?array
    {
        return $principal !== null && $path !== '' ? $this->index->findByPath($path, $principal) : null;
    }

    /**
     * A comment as the screen shows it: its author's display name beside the username
     *
     * @param array<string, mixed> $comment
     *
     * @return array<string, mixed>
     */
    private static function shown(array $comment): array
    {
        return $comment + [
            'by_name' => display_name((string) $comment['by']),
            'resolved_by_name' => \is_array($comment['resolved'] ?? null) ? display_name((string) $comment['resolved']['by']) : null,
        ];
    }
}
