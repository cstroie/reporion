<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Index;

use PDO;
use Throwable;

/**
 * The imaging guide's tables in the disposable index (D62, migration 009): `guide_row`, one row
 * per exam of a situation, and `guide_fts`, one row per situation. Filled whole from the CSV
 * (Service\Guide), never page by page; the sha of the file they hold is `schema_meta.guide_sha`.
 * The guide is public: no visibility predicate applies (invariant 6 covers pages).
 */
final class GuideTable
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Replace the guide with $rows (GuideCsv::parse()) in one transaction; $sha null empties it
     *
     * @param list<array<string, mixed>> $rows
     */
    public function replace(array $rows, ?string $sha): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM guide_row');
            $this->pdo->exec('DELETE FROM guide_fts');
            $this->pdo->prepare('DELETE FROM schema_meta WHERE key = ?')->execute(['guide_sha']);
            $insert = $this->pdo->prepare('INSERT INTO guide_row (key, situation, ord, chapter, subchapter, title, tip, exam, indication, grade, comment, other, dose_min, dose_max, therapeutic)
                VALUES (:key, :situation, :ord, :chapter, :subchapter, :title, :tip, :exam, :indication, :grade, :comment, :other, :dose_min, :dose_max, :therapeutic)');
            $bySituation = [];
            foreach ($rows as $row) {
                $insert->execute(array_intersect_key($row, array_flip(['key', 'situation', 'ord', 'chapter', 'subchapter', 'title', 'tip', 'exam', 'indication', 'grade', 'comment', 'other', 'dose_min', 'dose_max', 'therapeutic'])));
                $bySituation[$row['situation']][] = $row;
            }
            $fts = $this->pdo->prepare('INSERT INTO guide_fts (title, exams, comment, chapter, subchapter, situation) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($bySituation as $key => $its) {
                $comments = array_values(array_unique(array_filter(array_column($its, 'comment'))));
                $fts->execute([$its[0]['title'], implode("\n", array_column($its, 'exam')), implode("\n", $comments), $its[0]['chapter'], $its[0]['subchapter'], $key]);
            }
            if ($sha !== null) {
                $this->pdo->prepare('INSERT INTO schema_meta (key, value) VALUES (?, ?)')->execute(['guide_sha', $sha]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** The sha256 of the CSV the tables hold, or null (none loaded) */
    public function sha(): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM schema_meta WHERE key = ?');
        $stmt->execute(['guide_sha']);
        $sha = $stmt->fetchColumn();

        return \is_string($sha) ? $sha : null;
    }

    /**
     * The keys of the situations matching an FTS5 expression, best first, narrowed by exam types
     * (any of them), a chapter and indications (any of them, the guide's words); $match '' lists
     * the narrowed situations in the guide's order
     *
     * @param list<string> $tips
     * @param list<string> $indications
     *
     * @return list<string>
     */
    public function find(string $match, array $tips = [], string $chapter = '', array $indications = [], int $limit = 10, int $offset = 0): array
    {
        $where = [];
        $params = [];
        $rowCond = [];
        if ($tips !== []) {
            $rowCond[] = 'r.tip IN (' . implode(', ', array_fill(0, \count($tips), '?')) . ')';
            array_push($params, ...$tips);
        }
        if ($indications !== []) {
            $rowCond[] = 'r.indication IN (' . implode(', ', array_fill(0, \count($indications), '?')) . ')';
            array_push($params, ...$indications);
        }
        if ($rowCond !== []) {
            $where[] = 'EXISTS (SELECT 1 FROM guide_row r WHERE r.situation = f.situation AND ' . implode(' AND ', $rowCond) . ')';
        }
        if ($chapter !== '') {
            $where[] = 'f.chapter = ?';
            $params[] = $chapter;
        }
        if ($match !== '') {
            array_unshift($where, 'guide_fts MATCH ?');
            array_unshift($params, $match);
            // The situation's own words weigh most, then its exams, then the comments
            $order = 'bm25(guide_fts, 10.0, 4.0, 1.0, 2.0, 3.0)';
        } else {
            $order = '(SELECT MIN(ord) FROM guide_row r WHERE r.situation = f.situation)';
        }
        $sql = 'SELECT f.situation FROM guide_fts f' . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The rows of these situations, grouped by situation in the order of $keys
     *
     * @param list<string> $keys
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function rowsOf(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT * FROM guide_row WHERE situation IN (' . implode(', ', array_fill(0, \count($keys), '?')) . ') ORDER BY ord');
        $stmt->execute(array_values($keys));
        $grouped = array_fill_keys($keys, []);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['ord'] = (int) $row['ord'];
            $row['therapeutic'] = $row['therapeutic'] === null ? null : (int) $row['therapeutic'];
            $grouped[$row['situation']][] = $row;
        }

        return array_filter($grouped, static fn (array $rows): bool => $rows !== []);
    }

    /**
     * The chapters and their subchapters with how many situations each, in the guide's order
     *
     * @return list<array{chapter: string, subchapters: list<array{name: string, situations: int}>, situations: int}>
     */
    public function outline(): array
    {
        $stmt = $this->pdo->query('SELECT chapter, subchapter, COUNT(DISTINCT situation) AS n, MIN(ord) AS first FROM guide_row GROUP BY chapter, subchapter ORDER BY first');
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $at = array_search($row['chapter'], array_column($out, 'chapter'), true);
            if ($at === false) {
                $out[] = ['chapter' => (string) $row['chapter'], 'subchapters' => [], 'situations' => 0];
                $at = \count($out) - 1;
            }
            $out[$at]['subchapters'][] = ['name' => (string) $row['subchapter'], 'situations' => (int) $row['n']];
            $out[$at]['situations'] += (int) $row['n'];
        }

        return $out;
    }
}
