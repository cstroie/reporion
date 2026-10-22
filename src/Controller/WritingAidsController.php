<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Auth\User;
use Reporion\Http\ApiResponse;
use Reporion\Http\CheckText;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Service\PriorStudy;
use Reporion\Storage\PageRecord;
use Reporion\Support\DocumentFormat;
use Reporion\Support\Exams;
use Reporion\Support\Measurements;
use Reporion\Support\MetaText;
use Reporion\Support\RecallTerms;
use Reporion\Support\ReportName;
use Reporion\Support\WritingChecks;
use RuntimeException;

/**
 * The editor's writing aids (D51), for a radiologist who may write the
 * report (User::canActOnReport()); one who may read it but is no
 * radiologist gets 403 `not_radiologist`, anyone else, and a page that is
 * not a report, 404 (Controller\EditorPages: before the first Save, the
 * page it will be). Reads, like search: not audited.
 *
 * - `POST /api/v1/checks {path, text, form?}`: Support\WritingChecks on the
 *   text in front (a whole document, or a body with the page's own
 *   frontmatter, or, before the first Save, the form's), each said in words: `{data: [{code, exam, message, quote}]}`.
 * - `GET /api/v1/pages/{path}/prior?exam=N` (or `POST {exam, form}`,
 *   for a page not written yet: its form's frontmatter names the patient): the report's prior
 *   (Service\PriorStudy, for the exam in front): its exams' `###` sections
 *   as plain text with the sizes they state, `{data: {pid, rev, study_date,
 *   exam_title, auto, href, exams: [{title, sections: [{heading, text,
 *   passages: [{text, sizes}]}]}]}}`, `{data: null}` when there is none.
 *   Never the name heading, never a page path (invariant 8: the link is
 *   `/r/{pid}/{rev}`).
 */
final class WritingAidsController
{
    private const MAX_TEXT = 200_000;

    public function __construct(
        private readonly EditorPages $editorPages,
        private readonly PriorStudy $priors,
    ) {
    }

    public function checks(Request $request, ?User $principal): Response
    {
        $fields = $request->json();
        $path = \is_string($fields['path'] ?? null) ? trim($fields['path'], ': ') : '';
        $text = \is_string($fields['text'] ?? null) ? $fields['text'] : '';
        if (mb_strlen($text) > self::MAX_TEXT) {
            return ApiResponse::error(413, 'too_long', 'The text is too long to check.');
        }
        $form = \is_string($fields['form'] ?? null) ? mb_substr($fields['form'], 0, 2 * self::MAX_TEXT) : '';
        $page = $this->editorPages->forReport($path, $principal, $form, $text);
        if ($page instanceof Response) {
            return $page;
        }
        // A whole document carries its frontmatter; a body, or a block still being typed, is checked against the page's
        try {
            [$frontmatter, $body] = DocumentFormat::parseOrBare($text);
        } catch (RuntimeException) {
            [$frontmatter, $body] = [null, $text];
        }
        $data = array_map(static fn (array $check): array => [
            'code' => $check['code'],
            'exam' => $check['exam'],
            'message' => CheckText::message($check),
            'quote' => $check['quote'],
        ], WritingChecks::check($body, $frontmatter ?? $page->frontmatter));

        return ApiResponse::json(['data' => $data]);
    }

    public function prior(Request $request, string $path, ?User $principal): Response
    {
        // POST carries the editor's form: the frontmatter of a page not written yet
        $fields = $request->method === 'POST' ? $request->json() : [];
        $form = \is_string($fields['form'] ?? null) ? $fields['form'] : '';
        if (mb_strlen($form) > 2 * self::MAX_TEXT) {
            return ApiResponse::error(413, 'too_long', 'The text is too long.');
        }
        $page = $this->editorPages->forReport($path, $principal, $form);
        if ($page instanceof Response) {
            return $page;
        }
        $exam = $fields['exam'] ?? $request->query['exam'] ?? '';
        $n = ctype_digit((string) $exam) ? (int) $exam : 0;
        $exams = Exams::of($page->frontmatter);
        $found = $this->priors->for($page, $principal, $n > 0 ? ($exams[$n - 1] ?? null) : null);
        if ($found === null) {
            return ApiResponse::json(['data' => null]);
        }
        $prior = $found['record'];

        return ApiResponse::json(['data' => [
            'pid' => $prior->pid,
            'rev' => $prior->rev,
            'study_date' => MetaText::date($prior->frontmatter['study_date'] ?? null, 'Y-m-d'),
            'exam_title' => Exams::title($prior->frontmatter),
            'auto' => $found['auto'],
            'href' => '/r/' . rawurlencode($prior->pid) . '/' . $prior->rev,
            'exams' => self::exams($prior),
        ]]);
    }

    /**
     * A report's exams, each its `###` sections as plain text, each
     * paragraph with the sizes it states
     *
     * @return list<array{title: string, sections: list<array{heading: string, text: string, passages: list<array{text: string, sizes: list<array{text: string, mm: float, unit: string}>}>}>}>
     */
    private static function exams(PageRecord $record): array
    {
        $split = Exams::split(ReportName::withoutNameHeading($record->body, $record->frontmatter));
        $parts = $split['parts'] !== [] ? $split['parts'] : [['title' => Exams::title($record->frontmatter), 'text' => $split['head']]];
        if ($split['shared'] !== null) {
            $parts[] = $split['shared'];
        }
        $out = [];
        foreach ($parts as $part) {
            $sections = [];
            foreach (RecallTerms::passages($part['text']) as $passage) {
                $last = array_key_last($sections);
                if ($last === null || $sections[$last]['heading'] !== $passage['heading']) {
                    $sections[] = ['heading' => $passage['heading'], 'text' => '', 'passages' => []];
                    $last = array_key_last($sections);
                }
                $sections[$last]['text'] .= ($sections[$last]['text'] !== '' ? "\n\n" : '') . $passage['text'];
                $sections[$last]['passages'][] = ['text' => $passage['text'], 'sizes' => Measurements::of($passage['text'])];
            }
            $out[] = ['title' => trim($part['title']), 'sections' => $sections];
        }

        return $out;
    }
}
