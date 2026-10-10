<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use Reporion\Auth\User;
use Reporion\Http\Request;
use Reporion\Storage\PageRecord;
use Reporion\Support\CommitDiff;
use Reporion\Support\ReportName;
use Reporion\Support\SummaryLine;
use Throwable;

/**
 * The revision note a Save writes when *What changed?* is left empty
 * (2026-10-10): the reserved `commit` prompt, on a small model (lite by
 * default), given what the save changes in the body as {diff}
 * (Support\CommitDiff). The editor marks the note `assisted: commit` (D8).
 *
 * A Save never waits on more than this one call, and never fails because
 * of it: one attempt (the Assistant it is given retries nothing), the
 * prompt page's `timeout:` (10 s when it sets none), and any failure —
 * no answer, a refusal, an identifier left in the prompt — is no note.
 *
 * Both bodies are de-identified before they are compared, against the
 * stored frontmatter *and* the one being saved: a name corrected in this
 * save would otherwise go out on a `-` line, since Context only knows the
 * page as it is on disk. Context then redacts and checks the diff again.
 */
final class CommitNote
{
    public function __construct(
        private readonly Actions $actions,
        private readonly Assistant $assistant,
    ) {
    }

    /**
     * The note for saving $body (with $frontmatter) over $record, or null
     * when there is none to give
     *
     * @param array<string, mixed> $frontmatter
     */
    public function suggest(PageRecord $record, array $frontmatter, string $body, User $user, ?Request $request = null): ?string
    {
        try {
            $action = $this->actions->special($record->path, 'commit');
            if ($action === null) {
                return null;
            }
            $redactor = new Redactor();
            $redactor->learn($record->frontmatter, $record->path, $record->body);
            $redactor->learn($frontmatter, $record->path, $body);
            $diff = CommitDiff::build(
                $redactor->redact(ReportName::withoutNameHeading($record->body, $record->frontmatter)),
                $redactor->redact(ReportName::withoutNameHeading($body, $frontmatter)),
            );
            if ($diff === null) {
                return null;
            }
            $answer = '';
            $this->assistant->run($action, $record, $diff, 'diff', null, '', $user, $request, static function (string $piece) use (&$answer): void {
                $answer .= $piece;
            });
            $note = SummaryLine::tidy($answer);

            return $note !== '' && strcasecmp($note, 'NONE') !== 0 ? $note : null;
        } catch (Throwable) {
            return null;
        }
    }
}
