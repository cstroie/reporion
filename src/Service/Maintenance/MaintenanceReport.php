<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

/**
 * The result of one maintenance run, in one shape for every task: what the
 * admin screen renders, what `bin/reporion … --json` prints and what is
 * kept in data/maintenance/runs/. Pages are named by pid only — never by
 * path (invariant 8); the owner-only screen looks titles up itself.
 */
final class MaintenanceReport
{
    /** @var list<array{pid: ?string, rev: ?int, outcome: string, detail: string, data: array<string, mixed>}> */
    private array $items = [];

    /** @var array<string, int> */
    private array $summary = [];

    /** @var list<string> */
    private array $notes = [];

    private int $exit = 0;

    private string $finished = '';

    /**
     * @param array<string, int|bool> $options
     */
    public function __construct(
        public readonly string $task,
        public readonly string $mode,
        public readonly string $actor,
        public readonly array $options,
        public readonly string $started,
    ) {
    }

    /**
     * @param string               $detail human-readable, for the screen
     * @param array<string, mixed> $data   machine-readable specifics, for --json and the CLI text
     */
    public function item(?string $pid, ?int $rev, string $outcome, string $detail = '', array $data = []): void
    {
        $this->items[] = ['pid' => $pid, 'rev' => $rev, 'outcome' => $outcome, 'detail' => $detail, 'data' => $data];
    }

    public function count(string $key, int $by = 1): void
    {
        $this->summary[$key] = ($this->summary[$key] ?? 0) + $by;
    }

    public function note(string $text): void
    {
        $this->notes[] = $text;
    }

    public function fail(): void
    {
        $this->exit = 1;
    }

    public function finish(): self
    {
        $this->finished = date('Y-m-d\TH:i:sP');

        return $this;
    }

    public function exit(): int
    {
        return $this->exit;
    }

    /** @return list<array{pid: ?string, rev: ?int, outcome: string, detail: string, data: array<string, mixed>}> */
    public function items(): array
    {
        return $this->items;
    }

    /** @return array<string, int> */
    public function summary(): array
    {
        return $this->summary;
    }

    /** @return list<string> */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * @return array{task: string, mode: string, actor: string, options: array<string, int|bool>, started: string, finished: string, exit: int, summary: array<string, int>, items: list<array{pid: ?string, rev: ?int, outcome: string, detail: string, data: array<string, mixed>}>, notes: list<string>}
     */
    public function toArray(): array
    {
        return [
            'task' => $this->task,
            'mode' => $this->mode,
            'actor' => $this->actor,
            'options' => $this->options,
            'started' => $this->started,
            'finished' => $this->finished,
            'exit' => $this->exit,
            'summary' => $this->summary,
            'items' => $this->items,
            'notes' => $this->notes,
        ];
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed> $data a stored run (toArray()) */
    public static function fromArray(array $data): self
    {
        $report = new self(
            (string) ($data['task'] ?? ''),
            (string) ($data['mode'] ?? ''),
            (string) ($data['actor'] ?? ''),
            \is_array($data['options'] ?? null) ? $data['options'] : [],
            (string) ($data['started'] ?? ''),
        );
        foreach ((array) ($data['items'] ?? []) as $item) {
            if (\is_array($item)) {
                $report->item(
                    isset($item['pid']) ? (string) $item['pid'] : null,
                    isset($item['rev']) ? (int) $item['rev'] : null,
                    (string) ($item['outcome'] ?? ''),
                    (string) ($item['detail'] ?? ''),
                    \is_array($item['data'] ?? null) ? $item['data'] : [],
                );
            }
        }
        foreach ((array) ($data['summary'] ?? []) as $key => $n) {
            $report->count((string) $key, (int) $n);
        }
        foreach ((array) ($data['notes'] ?? []) as $note) {
            $report->note((string) $note);
        }
        $report->exit = (int) ($data['exit'] ?? 0);
        $report->finished = (string) ($data['finished'] ?? '');

        return $report;
    }
}
