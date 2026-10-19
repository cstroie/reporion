<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Index\IndexInterface;
use Reporion\Storage\FlatFile;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\DocumentFormat;
use RuntimeException;

/**
 * The page an editor is open on, for the rail's endpoints (the assistant,
 * Recall, Checks, Prior): the stored page when the caller can read it, or,
 * before its first Save, the page as it will be: a valid path the caller
 * may write with nothing there yet (the editor's own rule, 2026-09-27),
 * its frontmatter as its first Save would make it from the editor's form:
 * the raw document's block, or the Details panel's fields over the guided
 * form's carried frontmatter, else what the path says
 * (Service\FrontmatterGuess). Nothing is written; such a record has no pid
 * and rev 0.
 */
final class EditorPage
{
    public function __construct(
        private readonly IndexInterface $index,
        private readonly StorageInterface $storage,
        /** How the curated editor's fields become frontmatter (the Save's own rule) */
        private readonly ?FrontmatterFields $fields = null,
    ) {
    }

    /**
     * @param string $form     the editor's form, urlencoded as its autosave sends it (`document`, or
     *                         `body` with `fm[…]` and a guided report's `carried`): what gives a page
     *                         not written yet its frontmatter
     * @param string $document a whole document or a body, when no form is sent
     */
    public function resolve(string $path, User $principal, string $form = '', string $document = ''): ?PageRecord
    {
        if ($this->index->findByPath($path, $principal) !== null) {
            try {
                return $this->storage->read($path);
            } catch (RuntimeException) {
                return null;
            }
        }
        if (!FlatFile::isValidPath($path) || !$principal->canWrite($path)) {
            return null;
        }
        try {
            $this->storage->read($path);

            return null; // there, but not for this caller
        } catch (PageNotFoundException) {
        }
        [$frontmatter, $body] = $this->unsaved($path, $form, $document);

        return new PageRecord('', $path, 0, 'draft', 'private', $frontmatter, $body, [], []);
    }

    /**
     * A page not written yet as its editor holds it: the frontmatter and the body
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function unsaved(string $path, string $form, string $document): array
    {
        $fields = [];
        if ($form !== '') {
            parse_str($form, $fields);
        }
        $raw = \is_string($fields['document'] ?? null) ? $fields['document'] : null;
        if ($raw === null && !isset($fields['body'])) {
            $raw = $document;
        }
        if ($raw !== null) {
            try {
                [$frontmatter, $body] = DocumentFormat::parseOrBare($raw);
            } catch (RuntimeException) {
                [$frontmatter, $body] = [null, $raw];
            }

            return [$frontmatter ?? FrontmatterGuess::forNewPage($path, $body), $body];
        }
        // The curated editor: the body, the Details panel's fields over the guided form's carried frontmatter
        $body = \is_string($fields['body'] ?? null) ? $fields['body'] : $document;
        $carried = \is_string($fields['carried'] ?? null) ? json_decode($fields['carried'], true) : null;
        $carried = \is_array($carried) && !array_is_list($carried) ? $carried : null;
        $frontmatter = $carried ?? FrontmatterGuess::forNewPage($path, $body);
        if ($this->fields !== null && \is_array($fields['fm'] ?? null)) {
            $shown = \is_array($fields['fm_shown'] ?? null) ? array_map('strval', $fields['fm_shown']) : [];
            $frontmatter = Publishing::merge($frontmatter, $this->fields->changesFrom($fields['fm'], $shown, $carried ?? [], $path));
        }

        return [$frontmatter, $body];
    }

    /** A record of a page not written yet */
    public static function isUnsaved(PageRecord $page): bool
    {
        return $page->pid === '';
    }
}
