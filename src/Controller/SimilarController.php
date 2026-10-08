<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Auth\User;
use Reporion\Http\ApiResponse;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Index\Sqlite;
use Reporion\Support\ReportPath;

/**
 * GET /api/v1/pages/{path}/similar (roadmap phase 34e, 2026-10-08): the
 * reports nearest to this one by their embeddings (Index\Sqlite::similar()),
 * among those the caller can see — invariant 6, the visibility predicate
 * picks the candidates — and other patients' only. Signed-in callers who
 * can read the report; 404 otherwise (invariant 9), and when no embedding
 * model is set up. `{data: [{path, exam_title, study_date, modality,
 * summary, status, score}], page: {limit}}` — no patient name; a report
 * with no vector yet has an empty list.
 */
final class SimilarController
{
    public const LIMIT = 10;

    public function __construct(
        private readonly Sqlite $index,
        /** The embedding model in use (Admin → AI); null: Similar reports is off */
        private readonly ?string $model,
    ) {
    }

    public function show(Request $request, string $path, ?User $principal): Response
    {
        $row = $principal !== null && $this->model !== null ? $this->index->findByPath($path, $principal) : null;
        if ($row === null || !ReportPath::isReport($path)) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        $data = array_map(static fn (array $r): array => [
            'path' => (string) $r['path'],
            'exam_title' => $r['exam_title'] !== null ? (string) $r['exam_title'] : null,
            'study_date' => $r['study_date'] !== null ? (string) $r['study_date'] : null,
            'modality' => $r['modality'] !== null ? (string) $r['modality'] : null,
            'summary' => $r['summary'] !== null ? (string) $r['summary'] : null,
            'status' => (string) $r['status'],
            'score' => (float) $r['score'],
        ], $this->index->similar((string) $row['pid'], $this->model, $principal, self::LIMIT));

        return ApiResponse::json(['data' => $data, 'page' => ['limit' => self::LIMIT]]);
    }
}
