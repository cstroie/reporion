<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use Reporion\Exception\PageNotFoundException;
use Reporion\Storage\StorageInterface;

/**
 * The prompts the app ships (resources/prompts, moved from docs/prompts
 * 2026-10-19) made into prompt pages of an AI profile: the reserved prompts
 * as `ai:profiles:{profile}:special:{id}`, the rail actions as
 * `ai:profiles:{profile}:{id}` (among them *Ask*, whose `{prompt}` gives the
 * rail its question box) and, when the profile has none, its own page with
 * the rail's table (actions/table.md): an existing table is never rewritten,
 * its rows are the owner's to add.
 *
 * Run by the installer's Configure step and, since 2026-10-19, at any time
 * for any profile (Admin → AI, `ai:prompts-install`): a page that is there
 * already, however edited, is left as it is, so asking twice is harmless and
 * a profile can be completed after the fact. The pages are ordinary private
 * pages, edited like any other. Turning them on sends nothing: the assistant
 * stays off until a server is set up (D15).
 */
final class DefaultPrompts
{
    public const SPECIAL = 'special';
    public const ACTION = 'action';
    public const TABLE = 'table';

    /**
     * Every shipped page: id => [kind, title, file under resources/prompts]
     *
     * @var array<string, array{kind: string, title: string, file: string}>
     */
    public const CATALOGUE = [
        'summary' => ['kind' => self::SPECIAL, 'title' => 'Summary', 'file' => 'summary.txt'],
        'tags' => ['kind' => self::SPECIAL, 'title' => 'Tags', 'file' => 'tags.txt'],
        'tags-review' => ['kind' => self::SPECIAL, 'title' => 'Tags review', 'file' => 'tags-review.txt'],
        'evolution' => ['kind' => self::SPECIAL, 'title' => 'Evolution', 'file' => 'evolution.txt'],
        'presign' => ['kind' => self::SPECIAL, 'title' => 'Check before signing', 'file' => 'presign.txt'],
        'commit' => ['kind' => self::SPECIAL, 'title' => 'Revision note', 'file' => 'commit.txt'],
        'recall-expand' => ['kind' => self::SPECIAL, 'title' => 'Recall: expand', 'file' => 'recall-expand.txt'],
        'recall-missing' => ['kind' => self::SPECIAL, 'title' => 'Recall: what is missing', 'file' => 'recall-missing.txt'],
        'ask-archive' => ['kind' => self::SPECIAL, 'title' => 'Ask the archive', 'file' => 'ask-archive.txt'],
        'ask-expand' => ['kind' => self::SPECIAL, 'title' => 'Ask the archive: expand the question', 'file' => 'ask-expand.txt'],
        'ask' => ['kind' => self::ACTION, 'title' => 'Ask about this report', 'file' => 'actions/ask.txt'],
        'grammar' => ['kind' => self::ACTION, 'title' => 'Grammar', 'file' => 'actions/grammar.txt'],
        'shorten' => ['kind' => self::ACTION, 'title' => 'Shorten', 'file' => 'actions/shorten.txt'],
        'patient' => ['kind' => self::ACTION, 'title' => 'For the patient', 'file' => 'actions/patient.txt'],
        'english' => ['kind' => self::ACTION, 'title' => 'English', 'file' => 'actions/english.txt'],
        'table' => ['kind' => self::TABLE, 'title' => '', 'file' => 'actions/table.md'],
    ];

    /**
     * The baseline (what the installer's first tick makes): the rail with
     * its actions, and the summary and tags that Summarize, Suggest tags
     * and their tasks need. Every other prompt switches on one feature
     * outside the rail and is the owner's to add (the installer's second
     * tick, Admin → AI, `ai:prompts-install`).
     */
    public const CORE = ['table', 'ask', 'grammar', 'shorten', 'patient', 'english', 'summary', 'tags'];

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly string $dir,
    ) {
    }

    /** The page an id is made into for a profile */
    public static function pathOf(string $profile, string $id): string
    {
        $ns = Actions::NS . ':' . $profile;

        return match (self::CATALOGUE[$id]['kind']) {
            self::SPECIAL => $ns . ':' . Actions::SPECIAL_NS . ':' . $id,
            self::ACTION => $ns . ':' . $id,
            default => $ns,
        };
    }

    /**
     * What each shipped page is in a profile: `present` (a page with text),
     * `empty` (a page without text: left alone, an empty prompt is as absent
     * as none) or `missing`
     *
     * @return array<string, string> id => state, in catalogue order
     */
    public function status(string $profile): array
    {
        $states = [];
        foreach (array_keys(self::CATALOGUE) as $id) {
            $path = self::pathOf($profile, $id);
            $states[$id] = !$this->exists($path) ? 'missing' : ($this->hasText($path) ? 'present' : 'empty');
        }

        return $states;
    }

    /**
     * Make the shipped pages a profile lacks. Nothing is overwritten: a page
     * that exists, empty or not, is skipped.
     *
     * @param list<string>|null $only ids to make (null: all)
     *
     * @return array{created: list<string>, skipped: list<string>} ids, in catalogue order
     */
    public function install(string $profile, string $actor, ?array $only = null, bool $dryRun = false): array
    {
        $created = [];
        $skipped = [];
        foreach (self::CATALOGUE as $id => $entry) {
            if ($only !== null && !\in_array($id, $only, true)) {
                continue;
            }
            $path = self::pathOf($profile, $id);
            $file = $this->dir . '/' . $entry['file'];
            $body = is_file($file) ? trim((string) file_get_contents($file)) : '';
            if ($body === '' || $this->exists($path)) {
                $skipped[] = $id;
                continue;
            }
            if (!$dryRun) {
                $title = $entry['kind'] === self::TABLE ? 'Prompts: ' . $profile : $entry['title'];
                $this->storage->create($path, ['title' => $title, 'visibility' => 'private'], $body . "\n", $actor);
            }
            $created[] = $id;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    private function exists(string $path): bool
    {
        try {
            $this->storage->read($path);

            return true;
        } catch (PageNotFoundException) {
            return false;
        }
    }

    private function hasText(string $path): bool
    {
        try {
            return trim($this->storage->read($path)->body) !== '';
        } catch (PageNotFoundException) {
            return false;
        }
    }
}
