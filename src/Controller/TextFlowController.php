<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Auth\User;
use Reporion\Http\ApiResponse;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Support\ReportPath;
use Reporion\Support\TextFlow;

/**
 * `POST /api/v1/textflow {path, text}`: the editor rail's *Text flow* (Tools): Support\TextFlow on
 * the body in front, for a caller who may write the page; never on a report (reports are not
 * articles: 404, as for a page the caller may not write). Writes nothing, not audited: the editor
 * applies the text it returns as one undoable edit, and the writer saves it.
 * `{data: {text, changed, fixed: {code: n}, issues: [{code, message, quote}]}}`.
 */
final class TextFlowController
{
    private const MAX_TEXT = 1_000_000;

    public function flow(Request $request, ?User $principal): Response
    {
        $fields = $request->json();
        $path = \is_string($fields['path'] ?? null) ? trim($fields['path'], ': ') : '';
        $text = \is_string($fields['text'] ?? null) ? $fields['text'] : '';
        if ($principal === null || $path === '' || ReportPath::isReport($path) || !$principal->canWrite($path)) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        if (\strlen($text) > self::MAX_TEXT) {
            return ApiResponse::error(413, 'too_long', 'The text is too long.');
        }
        $result = TextFlow::fix($text);
        $same = trim($result['text']) === trim(str_replace(["\r\n", "\r"], "\n", $text));

        return ApiResponse::json(['data' => [
            'text' => $result['text'],
            'changed' => !$same,
            'fixed' => (object) $result['fixed'],
            'issues' => array_map(static fn (array $i): array => $i + ['message' => t('editor.flow.issue.' . $i['code'])], $result['issues']),
        ]]);
    }
}
