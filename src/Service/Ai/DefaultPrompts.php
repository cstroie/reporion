<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use InvalidArgumentException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Storage\StorageInterface;

/**
 * The prompts the app ships (resources/prompts, moved from docs/prompts
 * 2026-10-19) made into prompt pages of an AI profile. They come in two
 * targets (2026-10-20): `radiology` (reports; written in Romanian; every
 * prompt) and `generic` (ordinary pages; in English; the ones that make
 * sense for any page: summary, tags, tags-review, commit, ask). A profile is
 * made from one of them. the reserved prompts
 * as `ai:profiles:{profile}:special:{id}`, the rail actions as
 * `ai:profiles:{profile}:{id}` (among them *Ask*, whose `{prompt}` gives the
 * rail its question box) and, when the profile has none, its own page with
 * the rail's table (actions/table.md): an existing table is never rewritten,
 * its rows are the owner's to add. The radiology target also ships the
 * profile's system prompt (`ai:profiles:{profile}:system`) and the rail's
 * *Create* and *Conclusion* actions (2026-10-25).
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
    public const SYSTEM = 'system';

    /** Prompts for reports of a radiology department, written in Romanian */
    public const RADIOLOGY = 'radiology';
    /** Prompts for ordinary pages, written in English */
    public const GENERIC = 'generic';
    public const TARGETS = [self::RADIOLOGY, self::GENERIC];

    private const BOTH = [self::RADIOLOGY, self::GENERIC];
    private const RADIOLOGY_ONLY = [self::RADIOLOGY];
    private const GENERIC_ONLY = [self::GENERIC];

    /** The target used when none is named */
    public const DEFAULT_TARGET = self::GENERIC;

    /**
     * Every shipped page: id => [kind, title, file, targets, model?]. `model`
     * (`lite` for the light tasks: one line out, a list, a rewrite) goes in the
     * page's frontmatter; the others run on `normal`. The file is
     * under resources/prompts/{target}/ (one per target the id is shipped
     * for: the radiology ones in Romanian, the generic ones in English).
     *
     * @var array<string, array{kind: string, title: string, file: string, targets: list<string>, model?: string}>
     */
    public const CATALOGUE = [
        'summary' => ['kind' => self::SPECIAL, 'title' => 'Summary', 'file' => 'summary.txt', 'model' => 'lite', 'targets' => self::BOTH],
        'tags' => ['kind' => self::SPECIAL, 'title' => 'Tags', 'file' => 'tags.txt', 'model' => 'lite', 'targets' => self::BOTH],
        'tags-review' => ['kind' => self::SPECIAL, 'title' => 'Tags review', 'file' => 'tags-review.txt', 'targets' => self::BOTH],
        'commit' => ['kind' => self::SPECIAL, 'title' => 'Revision note', 'file' => 'commit.txt', 'model' => 'lite', 'targets' => self::BOTH],
        'evolution' => ['kind' => self::SPECIAL, 'title' => 'Evolution', 'file' => 'evolution.txt', 'targets' => self::RADIOLOGY_ONLY],
        'presign' => ['kind' => self::SPECIAL, 'title' => 'Check before signing', 'file' => 'presign.txt', 'targets' => self::RADIOLOGY_ONLY],
        'recall-expand' => ['kind' => self::SPECIAL, 'title' => 'Recall: expand', 'file' => 'recall-expand.txt', 'targets' => self::RADIOLOGY_ONLY],
        'recall-missing' => ['kind' => self::SPECIAL, 'title' => 'Recall: what is missing', 'file' => 'recall-missing.txt', 'model' => 'lite', 'targets' => self::RADIOLOGY_ONLY],
        'ask-archive' => ['kind' => self::SPECIAL, 'title' => 'Ask the archive', 'file' => 'ask-archive.txt', 'targets' => self::RADIOLOGY_ONLY],
        'ask-expand' => ['kind' => self::SPECIAL, 'title' => 'Ask the archive: expand the question', 'file' => 'ask-expand.txt', 'model' => 'lite', 'targets' => self::RADIOLOGY_ONLY],
        'ask' => ['kind' => self::ACTION, 'title' => 'Ask about this page', 'file' => 'ask.txt', 'targets' => self::BOTH],
        'create' => ['kind' => self::ACTION, 'title' => 'Create', 'file' => 'create.txt', 'targets' => self::RADIOLOGY_ONLY],
        'conclusion' => ['kind' => self::ACTION, 'title' => 'Conclusion', 'file' => 'conclusion.txt', 'targets' => self::RADIOLOGY_ONLY],
        'grammar' => ['kind' => self::ACTION, 'title' => 'Grammar', 'file' => 'grammar.txt', 'model' => 'lite', 'targets' => self::GENERIC_ONLY],
        'system' => ['kind' => self::SYSTEM, 'title' => 'System prompt', 'file' => 'system.txt', 'targets' => self::RADIOLOGY_ONLY],
        'table' => ['kind' => self::TABLE, 'title' => '', 'file' => 'table.md', 'targets' => self::BOTH],
    ];

    /**
     * The baseline (what the installer's first tick makes): the rail with
     * its Ask action, and the summary and tags that Summarize, Suggest tags
     * and their tasks need. Every other prompt switches on one feature
     * outside the rail and is the owner's to add (the installer's second
     * tick, Admin → AI, `ai:prompts-install`).
     */
    public const CORE = ['table', 'ask', 'grammar', 'summary', 'tags'];

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
            self::SYSTEM => $ns . ':' . self::SYSTEM,
            default => $ns,
        };
    }

    /**
     * What each shipped page of a target is in a profile: `present` (a page
     * with text), `empty` (a page without text: left alone, an empty prompt
     * is as absent as none) or `missing`
     *
     * @return array<string, string> id => state, in catalogue order
     */
    public function status(string $profile, string $target = self::DEFAULT_TARGET): array
    {
        $states = [];
        foreach (self::CATALOGUE as $id => $entry) {
            if (!\in_array($target, $entry['targets'], true)) {
                continue;
            }
            $path = self::pathOf($profile, $id);
            $states[$id] = !$this->exists($path) ? 'missing' : ($this->hasText($path) ? 'present' : 'empty');
        }

        return $states;
    }

    /**
     * Make the shipped pages a profile lacks. Nothing is overwritten: a page
     * that exists, empty or not, is skipped.
     *
     * @param list<string>|null $only ids to make (null: all the target has)
     * @param string $target self::RADIOLOGY (Romanian) or self::GENERIC (English)
     *
     * @throws InvalidArgumentException an unknown target
     *
     * @return array{created: list<string>, skipped: list<string>} ids, in catalogue order
     */
    public function install(string $profile, string $actor, ?array $only = null, bool $dryRun = false, string $target = self::DEFAULT_TARGET): array
    {
        if (!\in_array($target, self::TARGETS, true)) {
            throw new InvalidArgumentException('Unknown target "' . $target . '". Known: ' . implode(', ', self::TARGETS));
        }
        $created = [];
        $skipped = [];
        foreach (self::CATALOGUE as $id => $entry) {
            if (($only !== null && !\in_array($id, $only, true)) || !\in_array($target, $entry['targets'], true)) {
                continue;
            }
            $path = self::pathOf($profile, $id);
            $file = $this->dir . '/' . $target . '/' . $entry['file'];
            $body = is_file($file) ? trim((string) file_get_contents($file)) : '';
            if ($body === '' || $this->exists($path)) {
                $skipped[] = $id;
                continue;
            }
            if (!$dryRun) {
                $frontmatter = $entry['kind'] === self::TABLE
                    ? ['title' => 'Prompts: ' . $profile, 'visibility' => 'private']
                    // A prompt is shown as typed, never parsed as markdown (D40)
                    : ['title' => $entry['title'], 'format' => 'text', 'visibility' => 'private'] + (isset($entry['model']) ? ['model' => $entry['model']] : []);
                $this->storage->create($path, $frontmatter, $body . "\n", $actor);
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
