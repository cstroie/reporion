<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Http\Request;
use Reporion\Index\IndexInterface;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Storage\FlatFile;
use Reporion\Support\Exams;
use Reporion\Support\HeadingNormalizer;
use Reporion\Support\MetaText;
use Reporion\Support\PatientKey;
use Reporion\Support\ReportName;
use Reporion\Support\ReportPath;
use Throwable;

/**
 * Join two or more reports of one patient into one multi-exam report
 * (roadmap phase 29, docs/FORMATS.md §12b). Decided with the owner: only
 * the parents' latest revisions are joined, the user checks the result
 * first, and the parents go to the trash.
 *
 * plan() works out the check screen and the report it would write; apply()
 * writes it — a new draft through Storage (invariant 5), its exams the
 * parents' in the chosen order, each keeping its accession (D20), template
 * and PACS study, with `joined_from` naming the parents and revisions.
 * Links to a parent in unsigned pages are pointed at the joined report.
 */
final class Joins
{
    /** Report-level fields picked among the parents when they differ */
    public const CHOSEN = ['referrer', 'indication', 'summary'];

    /** Most reports joined at once — the most exams a worklist start makes (§12) */
    public const MAX = 8;

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
        private readonly PageMoves $moves,
        private readonly AuditLog $audit,
        /** @var array<string, string> modality code => namespace segment */
        private readonly array $modalityNamespaces,
    ) {
    }

    /**
     * The check screen and the report it would write.
     *
     * - $order: exam keys (`{parent}.{exam}`, from exams()) in the order
     *   wanted; missing or unknown ones keep the time order.
     * - $choices: field => the index of the parent value picked (CHOSEN).
     * - $ns: the modality namespace for the path, among the exams'.
     * - $leaf: the path's last segment, `{yymmdd}-{name}` on the exam day,
     *   as the user wrote it; '' is the parents' common name ("-rk"/"-lk"
     *   dropped), else the first parent's.
     *
     * The joined body must be in the report shape (docs/FORMATS.md §11):
     * HeadingNormalizer sets its heading levels when it can (`relevelled`),
     * and a body it cannot place is a problem — never a `###` exam.
     *
     * @param list<string>          $paths
     * @param list<string>          $order
     * @param array<string, string> $choices
     *
     * @return array{problems: list<string>, parents: list<PageRecord>, signed: list<string>, exams: list<array{key: string, parent: int, exam: array<string, mixed>, text: string}>, fields: array<string, list<array{value: string, from: list<int>}>>, chosen: array<string, int>, namespaces: list<string>, ns: string, prefix: string, leaf: string, path: ?string, relevelled: bool, frontmatter: ?array<string, mixed>, body: string}
     */
    public function plan(array $paths, User $principal, array $order = [], array $choices = [], string $ns = '', string $leaf = ''): array
    {
        $problems = [];
        $parents = [];
        foreach (array_values(array_unique($paths)) as $path) {
            if (!ReportPath::isReport($path) || !$principal->canWrite($path) || $this->index->findByPath($path, $principal) === null) {
                $problems[] = t('join.err_access');
                continue;
            }
            try {
                $parents[] = $this->storage->read($path);
            } catch (Throwable) {
                $problems[] = t('join.err_access');
            }
        }
        $empty = ['problems' => [], 'parents' => $parents, 'signed' => [], 'exams' => [], 'fields' => [], 'chosen' => [], 'namespaces' => [], 'ns' => '', 'prefix' => '', 'leaf' => '', 'path' => null, 'relevelled' => false, 'frontmatter' => null, 'body' => ''];
        if (\count($parents) < 2) {
            return ['problems' => [...$problems, t('join.err_two')]] + $empty;
        }
        if (\count($parents) > self::MAX) {
            return ['problems' => [...$problems, t('join.err_max', [self::MAX])]] + $empty;
        }

        $sites = array_unique(array_map(static fn (PageRecord $p): string => MetaText::text($p->frontmatter['site'] ?? null), $parents));
        if (\count($sites) !== 1 || reset($sites) === '') {
            $problems[] = t('join.err_site');
        }
        if (!self::samePatient($parents)) {
            $problems[] = t('join.err_patient');
        }

        // Each parent's exams with their text: its `##` sections, paired one to one
        $heads = [];
        $exams = [];
        foreach ($parents as $p => $parent) {
            $body = ReportName::withoutNameHeading($parent->body, $parent->frontmatter);
            $split = Exams::split($body);
            $own = Exams::of($parent->frontmatter);
            if (\count($split['parts']) === \count($own)) {
                $texts = array_column($split['parts'], 'text');
            } elseif (\count($own) === 1 && $split['parts'] === []) {
                // A one-exam report without its `##`: its whole text under its title
                $texts = ['## ' . (MetaText::text($own[0]['title'] ?? null) ?: ReportName::examTitle($parent->frontmatter, $parent->path)) . "\n\n" . ltrim($split['head'])];
                $split['head'] = '';
            } else {
                $problems[] = t('join.err_shape', [ReportName::examTitle($parent->frontmatter, ReportPath::leaf($parent->path))]);
                continue;
            }
            if (trim($split['head']) !== '') {
                $heads[] = rtrim($split['head']) . "\n";
            }
            foreach ($own as $e => $exam) {
                $exams[$p . '.' . $e] = ['key' => $p . '.' . $e, 'parent' => $p, 'exam' => $exam, 'text' => rtrim($texts[$e], "\n") . "\n"];
            }
        }

        // D20: an accession is never held twice — a parent with a number another exam has is fixed first
        $numbers = array_filter(array_map(static fn (array $row): string => MetaText::text($row['exam']['accession'] ?? null), $exams), static fn (string $a): bool => $a !== '');
        foreach (array_unique(array_diff_assoc($numbers, array_unique($numbers))) as $twice) {
            $problems[] = t('join.err_accession', [$twice]);
        }

        // Time order, then as asked
        uasort($exams, static fn (array $a, array $b): int => [self::when($a['exam']), $a['key']] <=> [self::when($b['exam']), $b['key']]);
        $ordered = [];
        foreach ($order as $key) {
            if (isset($exams[$key]) && !isset($ordered[$key])) {
                $ordered[$key] = $exams[$key];
            }
        }
        $exams = array_values($ordered + $exams);

        // Report-level fields: the parents' distinct values, the first picked unless chosen
        $fields = [];
        $chosen = [];
        foreach (self::CHOSEN as $key) {
            $values = [];
            foreach ($parents as $p => $parent) {
                $value = MetaText::text($parent->frontmatter[$key] ?? null);
                if ($value === '') {
                    continue;
                }
                $at = array_search($value, array_column($values, 'value'), true);
                if ($at === false) {
                    $values[] = ['value' => $value, 'from' => [$p]];
                } else {
                    $values[$at]['from'][] = $p;
                }
            }
            $fields[$key] = $values;
            $pick = isset($choices[$key]) && ctype_digit($choices[$key]) ? (int) $choices[$key] : 0;
            $chosen[$key] = isset($values[$pick]) ? $pick : 0;
        }

        // The path: the first exam's modality namespace (or the one chosen), the earliest day, the patient
        $namespaces = [];
        foreach ($exams as $row) {
            foreach (Exams::listOf($row['exam']['modality'] ?? null) as $modality) {
                $segment = $this->modalityNamespaces[$modality] ?? null;
                if ($segment !== null && !\in_array($segment, $namespaces, true)) {
                    $namespaces[] = $segment;
                }
            }
        }
        $ns = \in_array($ns, $namespaces, true) ? $ns : ($namespaces[0] ?? '');
        $first = $parents[0];
        $day = MetaText::date(Exams::derive(array_column($exams, 'exam'))['study_date'], 'ymd');
        $leaf = strtolower(trim($leaf));
        if ($leaf === '' && preg_match('/^\d{6}$/', $day) === 1) {
            $leaf = $day . '-' . self::commonName($parents);
        }
        $prefix = $ns !== '' && \count($sites) === 1 ? 'reports:' . $ns . ':' . reset($sites) . ':' : '';
        $path = $prefix !== '' && preg_match('/^\d{6}$/', $day) === 1 ? $prefix . $leaf : null;
        $parentPaths = array_map(static fn (PageRecord $p): string => $p->path, $parents);
        if ($path === null) {
            $problems[] = t('join.err_path');
        } elseif (!str_starts_with($leaf, $day . '-') || !ReportPath::isReport($path) || !FlatFile::isValidPath($path)) {
            $problems[] = t('join.err_leaf', [$day]);
        } elseif (!$principal->canWrite($path)) {
            $problems[] = t('join.err_access');
        } elseif (!\in_array($path, $parentPaths, true) && $this->index->findByPath($path, $principal) !== null) {
            $problems[] = t('join.err_taken');
        }

        $signed = array_values(array_map(static fn (PageRecord $p): string => ReportName::examTitle($p->frontmatter, ReportPath::leaf($p->path)), array_filter($parents, static fn (PageRecord $p): bool => $p->status === 'signed')));
        $frontmatter = null;
        $body = '';
        $relevelled = false;
        if ($problems === [] && $path !== null) {
            $frontmatter = $this->frontmatter($parents, array_column($exams, 'exam'), $fields, $chosen, $path);
            $body = '# ' . MetaText::text($first->frontmatter['title'] ?? null) . "\n\n" . implode("\n", $heads) . ($heads !== [] ? "\n" : '') . implode("\n", array_column($exams, 'text'));
            // The report shape, # name / ## exam / ### sections: levels set when they can be, else not joined
            $shape = HeadingNormalizer::normalize($body, $frontmatter);
            if ($shape['outcome'] === HeadingNormalizer::NORMALIZED) {
                $body = $shape['body'];
                $relevelled = true;
            } elseif ($shape['outcome'] === HeadingNormalizer::REVIEW) {
                $problems[] = t('join.err_structure', [implode('; ', $shape['reasons'])]);
                $frontmatter = null;
                $body = '';
            }
        }

        return [
            'problems' => array_values(array_unique($problems)),
            'parents' => $parents,
            'signed' => $signed,
            'exams' => $exams,
            'fields' => $fields,
            'chosen' => $chosen,
            'namespaces' => $namespaces,
            'ns' => $ns,
            'prefix' => $prefix,
            'leaf' => $leaf,
            'path' => $path,
            'relevelled' => $relevelled,
            'frontmatter' => $frontmatter,
            'body' => $body,
        ];
    }

    /**
     * Writes the plan: the parents to the trash, the joined report created
     * (should that fail, the parents come back), links pointed at it.
     * $revs are the parents' revisions the user checked: a parent saved
     * since is refused, nothing written.
     *
     * @param array<string, mixed> $plan  plan()'s result
     * @param array<string, int>   $revs  pid => the revision shown on the check screen
     *
     * @throws InvalidArgumentException with the reason, nothing written
     */
    public function apply(array $plan, array $revs, User $principal, ?Request $request = null): PageRecord
    {
        if ($plan['problems'] !== [] || $plan['frontmatter'] === null || $plan['path'] === null) {
            throw new InvalidArgumentException(implode(' ', $plan['problems']) ?: t('join.err_path'));
        }
        foreach ($plan['parents'] as $parent) {
            if (($revs[$parent->pid] ?? null) !== $parent->rev) {
                throw new InvalidArgumentException(t('join.err_changed'));
            }
        }
        $actor = $principal->username;

        // The parents first: the joined report takes the path a parent may hold
        $trashed = [];
        foreach ($plan['parents'] as $parent) {
            try {
                $this->storage->delete($parent->path, $actor);
            } catch (Throwable $e) {
                $this->undo($trashed, $actor);
                throw new InvalidArgumentException(t('join.err_delete'), 0, $e);
            }
            $trashed[] = $parent;
        }
        try {
            $joined = $this->storage->create($plan['path'], $plan['frontmatter'], $plan['body'], $actor, t('join.note', [\count($plan['parents'])]));
        } catch (Throwable $e) {
            $this->undo($trashed, $actor);
            throw new InvalidArgumentException(t('join.err_create'), 0, $e);
        }

        $relinked = $this->moves->relink(array_fill_keys(array_map(static fn (PageRecord $p): string => $p->path, $plan['parents']), $joined->path), $actor, 'link to joined report');

        foreach ($plan['parents'] as $parent) {
            $this->audit->record('page.delete', $actor, $request, $parent->pid, $parent->path, $parent->rev, extra: ['reason' => 'join']);
        }
        $this->audit->record('page.create', $actor, $request, $joined->pid, $joined->path, $joined->rev);
        $this->audit->record('page.join', $actor, $request, $joined->pid, $joined->path, $joined->rev, extra: [
            'parents' => implode(',', array_map(static fn (PageRecord $p): string => $p->pid . '@' . $p->rev, $plan['parents'])),
            'links_fixed' => \count($relinked['fixed']),
            'links_left_signed' => $relinked['skippedSigned'],
        ]);
        foreach ($relinked['fixed'] as $page) {
            $this->audit->record('page.save', $actor, $request, $page->pid, $page->path, $page->rev, extra: ['reason' => 'link-fixup']);
        }

        return $joined;
    }

    /**
     * @param list<PageRecord>                                                $parents
     * @param list<array<string, mixed>>                                      $exams
     * @param array<string, list<array{value: string, from: list<int>}>>    $fields
     * @param array<string, int>                                              $chosen
     *
     * @return array<string, mixed>
     */
    private function frontmatter(array $parents, array $exams, array $fields, array $chosen, string $path): array
    {
        $first = $parents[0]->frontmatter;
        $patient = [];
        foreach ($parents as $parent) {
            foreach (\is_array($parent->frontmatter['patient'] ?? null) ? $parent->frontmatter['patient'] : [] as $key => $value) {
                if (!isset($patient[$key]) && $value !== null && $value !== '') {
                    $patient[$key] = $value;
                }
            }
        }
        $parentPaths = array_map(static fn (PageRecord $p): string => $p->path, $parents);
        $union = static function (string $key) use ($parents, $parentPaths, $path): array {
            $all = [];
            foreach ($parents as $parent) {
                foreach ((array) ($parent->frontmatter[$key] ?? []) as $value) {
                    if (\is_string($value) && $value !== '' && !\in_array($value, $parentPaths, true) && $value !== $path && !\in_array($value, $all, true)) {
                        $all[] = $value;
                    }
                }
            }

            return $all;
        };
        $derived = Exams::derive($exams);
        $chosenValue = static fn (string $key): ?string => $fields[$key][$chosen[$key]]['value'] ?? null;

        return array_filter([
            'title' => $first['title'] ?? null,
            'exam_title' => $derived['exam_title'],
            'visibility' => 'private',
            'modality' => $derived['modality'],
            'region' => $derived['region'],
            'site' => $first['site'] ?? null,
            'device' => $derived['device'],
            'study_date' => $derived['study_date'],
            'accession' => $derived['accession'],
            'patient' => $patient,
            'referrer' => $chosenValue('referrer'),
            'indication' => $chosenValue('indication'),
            'summary' => $chosenValue('summary'),
            'tags' => $union('tags'),
            'priors' => $union('priors'),
            'template' => $derived['template'],
            'exams' => $exams,
            'joined_from' => array_map(static fn (PageRecord $p): array => ['pid' => $p->pid, 'rev' => $p->rev], $parents),
        ], static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * The same patient: the CNP when every parent has one, else name, birth
     * year and sex (D11's strong and weak keys).
     *
     * @param list<PageRecord> $parents
     */
    private static function samePatient(array $parents): bool
    {
        $strong = [];
        $weak = [];
        foreach ($parents as $parent) {
            $patient = \is_array($parent->frontmatter['patient'] ?? null) ? $parent->frontmatter['patient'] : [];
            $strong[] = PatientKey::strong(MetaText::text($patient['cnp'] ?? null));
            $born = MetaText::text($patient['born'] ?? null);
            $name = MetaText::text($patient['name'] ?? null) ?: MetaText::text($parent->frontmatter['title'] ?? null);
            $weak[] = $name === '' ? null : PatientKey::weak($name, ctype_digit($born) ? (int) $born : null, MetaText::text($patient['sex'] ?? null) ?: null);
        }
        $keys = \in_array(null, $strong, true) ? $weak : $strong;

        return !\in_array(null, $keys, true) && \count(array_unique($keys)) === 1;
    }

    /**
     * What the parents' names (their day left out) start with, at a whole
     * word: "…-eduard-rk" and "…-eduard-lk" give "…-eduard". The first
     * parent's name when they share no word.
     *
     * @param list<PageRecord> $parents
     */
    private static function commonName(array $parents): string
    {
        $names = array_map(static fn (PageRecord $p): array => explode('-', (string) preg_replace('/^\d{6}-/', '', ReportPath::leaf($p->path))), $parents);
        $common = $names[0];
        foreach ($names as $words) {
            $n = 0;
            while ($n < \count($common) && $n < \count($words) && $common[$n] === $words[$n]) {
                $n++;
            }
            $common = \array_slice($common, 0, $n);
        }

        return implode('-', $common !== [] ? $common : $names[0]);
    }

    /** @param array<string, mixed> $exam */
    private static function when(array $exam): string
    {
        $date = $exam['study_date'] ?? null;

        return \is_int($date) ? gmdate('Y-m-d\TH:i:s', $date) : MetaText::text($date);
    }

    /** @param list<PageRecord> $trashed */
    private function undo(array $trashed, string $actor): void
    {
        foreach ($trashed as $parent) {
            try {
                $this->storage->restore($parent->pid, $actor);
            } catch (Throwable) {
                // Left in the trash: Admin → Trash restores it
            }
        }
    }
}
