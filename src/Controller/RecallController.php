<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Auth\User;
use Reporion\Http\ApiResponse;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Index\Sqlite;
use Reporion\Service\Recall;
use Reporion\Storage\StorageInterface;
use Reporion\Support\ReportPath;
use RuntimeException;

/**
 * POST /api/v1/recall {path, text, written?} (D48): the editor's Recall
 * panel. `text` is the line just written, `written` the text in front
 * (what is already said). `{data: {passages: [{pid, rev, heading, text,
 * html, study_date, modality, mine, href}], missing: [..]}}`: passages of
 * finished reports the caller can read, never this patient's, never a
 * page path (invariant 8: the link is `/r/{pid}/{rev}`). For a
 * radiologist who may write the report (User::canActOnReport()); one who
 * may read it but is no radiologist gets 403 `not_radiologist`, anyone
 * else 404. A read, like search: not audited.
 */
final class RecallController
{
    /** The longest line recalled; a longer one is no line */
    private const MAX_LINE = 1_000;

    /** The most of the text in front read for what is already said */
    private const MAX_WRITTEN = 50_000;

    public function __construct(
        private readonly Sqlite $index,
        private readonly StorageInterface $storage,
        private readonly Recall $recall,
    ) {
    }

    public function find(Request $request, ?User $principal): Response
    {
        $fields = $request->json();
        $path = \is_string($fields['path'] ?? null) ? trim($fields['path'], ': ') : '';
        if ($principal === null || $path === '' || !ReportPath::isReport($path) || $this->index->findByPath($path, $principal) === null) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        if (!$principal->canActOnReport($path)) {
            return $principal->isRadiologist()
                ? ApiResponse::error(404, 'not_found', 'Not found.')
                : ApiResponse::error(403, 'not_radiologist', 'Only a radiologist may write reports.');
        }
        $line = \is_string($fields['text'] ?? null) ? $fields['text'] : '';
        $written = \is_string($fields['written'] ?? null) ? $fields['written'] : '';
        if (mb_strlen($line) > self::MAX_LINE || mb_strlen($written) > self::MAX_WRITTEN) {
            return ApiResponse::error(413, 'too_long', 'The text is too long to recall.');
        }
        try {
            $page = $this->storage->read($path);
        } catch (RuntimeException) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        $found = $this->recall->find($page, $line, $written, $principal);

        return ApiResponse::json(['data' => ['passages' => $found['passages'], 'missing' => $found['missing']]]);
    }
}
