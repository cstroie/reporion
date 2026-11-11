<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

use Reporion\Auth\User;
use Reporion\Exception\LibraryException;
use Reporion\Storage\PageRecord;

/**
 * Ask the notebook's kept answers (2026-11-11, owner's request; amends "shown, never written"): an
 * answer the writer chooses to keep is added to the notebook's saved analysis `answers`
 * (`{notebook}:answers`, Analyses::append()), one `## question` entry each, with its citations and
 * the day. It is the writer's revision (note `assisted: answers`), private, and moves and goes to
 * the trash with the notebook like Studio's texts; the notebook's own text is never written (D8).
 * The answer comes back from the browser, so it is held to what the server would have sent: a
 * citation of a key the notebook does not cite is taken out (NotebookAsk::onlySent()), and text
 * holding a CNP is refused (Library::holdsPatientData(), invariant 8).
 */
final class KeptAnswers
{
    /** The saved analysis the answers are kept in */
    public const PROMPT = 'answers';

    public const MAX_QUESTION = 1_000;

    public const MAX_ANSWER = 20_000;

    public function __construct(
        private readonly Analyses $analyses,
    ) {
    }

    /**
     * @param list<string> $keys the library keys the notebook cites that the caller may read
     *
     * @return ?array{path: string, rev: int, pid: string, created: bool, dropped: int} null: a page there that is not a saved analysis, or signed
     *
     * @throws LibraryException `invalid`: an empty or too long question or answer; `patient_data`: one holding a CNP
     * @throws \Reporion\Exception\RevisionConflictException another keep came first
     */
    public function keep(PageRecord $notebook, string $question, string $answer, array $keys, User $by, ?string $day = null): ?array
    {
        $question = trim((string) preg_replace('/\s+/u', ' ', $question));
        $answer = trim($answer);
        if ($question === '' || mb_strlen($question) > self::MAX_QUESTION) {
            throw new LibraryException(LibraryException::INVALID);
        }
        if ($answer === '' || mb_strlen($answer) > self::MAX_ANSWER) {
            throw new LibraryException(LibraryException::INVALID);
        }
        if (Library::holdsPatientData($question . "\n" . $answer)) {
            throw new LibraryException(LibraryException::PATIENT_DATA);
        }
        [$answer, $dropped] = NotebookAsk::onlySent($answer, $keys);
        $saved = $this->analyses->append($notebook, self::PROMPT, t('notebook.kept_label'), self::entry($question, $answer, $day ?? date('Y-m-d')), $by, '');

        return $saved === null ? null : $saved + ['dropped' => $dropped];
    }

    /** One kept answer: the question as a `##` heading (one line, no heading marks of its own), the answer, the day */
    public static function entry(string $question, string $answer, string $day): string
    {
        $heading = ltrim(trim((string) preg_replace('/\s+/u', ' ', $question)), '# ');

        return '## ' . $heading . "\n\n" . trim($answer) . "\n\n*" . $day . "*\n";
    }
}
