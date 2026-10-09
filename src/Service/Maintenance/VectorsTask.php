<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

use Reporion\Exception\AiException;
use Reporion\Index\Sqlite;
use Reporion\Service\Ai\Context;
use Reporion\Service\Ai\Embedder;
use Reporion\Storage\FlatFile;
use Reporion\Support\ReportPath;
use Throwable;

/**
 * index:vectors — the embeddings of Similar reports (roadmap phase 34e,
 * 2026-10-08), from disk into `page_vectors`. For every report, signed or
 * not, what Context::forEmbedding() gives (the conclusion, de-identified);
 * a report whose vector was made by the same model from the same text is
 * `current` and asks nothing, any other is embedded — BATCH texts per
 * request to the one embedding model Admin → AI names. Vectors of pages
 * that are gone are dropped. Check counts and sends nothing; --limit bounds
 * a run (the next one carries on); --force embeds every report again, the
 * current ones too (a rebuild of the vectors, e.g. after the redaction rules
 * changed). Writes no page: the index is a cache
 * (invariant 1). A server that fails stops the run; a batch the server
 * rejects (HTTP 4xx — a text too long for the model, say) is retried one
 * text at a time, and only the texts it still rejects are `failed`. A report
 * whose text still held an identifier after redaction is `withheld`, never
 * sent. Pages by pid only.
 */
final class VectorsTask implements MaintenanceTask, ProgressAware
{
    /** Texts per request */
    public const BATCH = 16;

    /** @var ?callable(string, array<string, mixed>): void */
    private $progress = null;

    public function __construct(
        private readonly FlatFile $storage,
        private readonly Sqlite $index,
        private readonly ?Embedder $embedder,
        private readonly int $timeout = 120,
    ) {
    }

    public function name(): string
    {
        return 'index:vectors';
    }

    public function modes(): array
    {
        return [self::CHECK, self::APPLY];
    }

    public function setProgress(?callable $progress): void
    {
        $this->progress = $progress;
    }

    public function options(array $raw): array
    {
        $limit = $raw['limit'] ?? 0;

        return [
            'limit' => is_numeric($limit) && (int) $limit > 0 ? (int) $limit : 0,
            'force' => \in_array($raw['force'] ?? false, [true, '1', 'on', 'yes', 'true'], true),
        ];
    }

    public function run(string $mode, string $actor, array $options): MaintenanceReport
    {
        $apply = $mode === self::APPLY;
        $limit = (int) $options['limit'];
        $force = $options['force'] === true;
        $report = new MaintenanceReport($this->name(), $mode, $actor, $options, date('Y-m-d\TH:i:sP'));
        foreach ([$apply ? 'embedded' : 'would_embed', 'current', 'no_text', 'withheld', 'dropped', 'failed', ...($apply ? ['remaining'] : [])] as $key) {
            $report->count($key, 0);
        }
        if ($this->embedder === null) {
            $report->note('No embedding model: choose a server and a model under Similar reports in Admin → AI.');
            $report->fail();

            return $report;
        }
        $model = $this->embedder->model;
        $states = $this->index->vectorStates();

        $todo = [];
        $seen = [];
        foreach ($this->storage->allPaths() as $path) {
            if (!ReportPath::isReport($path)) {
                continue;
            }
            try {
                $page = $this->storage->read($path);
            } catch (Throwable) {
                continue;
            }
            $seen[$page->pid] = true;
            ['text' => $text, 'withheld' => $withheld] = Context::embeddingText($page);
            if ($text === null) {
                $report->count($withheld ? 'withheld' : 'no_text');
                continue;
            }
            $sha = Embedder::sha($model, $text);
            if (!$force && ($states[$page->pid]['sha'] ?? null) === $sha) {
                $report->count('current');
                continue;
            }
            $todo[] = ['pid' => $page->pid, 'sha' => $sha, 'text' => $text];
        }
        $gone = array_values(array_diff(array_keys($states), array_keys($seen)));
        if ($apply) {
            $this->index->deleteVectors(array_map('strval', $gone));
        }
        $report->count('dropped', \count($gone));

        $pending = \count($todo);
        $todo = $limit > 0 ? \array_slice($todo, 0, $limit) : $todo;
        $total = \count($todo);
        if (!$apply) {
            $report->count('would_embed', $total);

            return $report;
        }
        // Past --limit, for the next run
        $remaining = $pending - $total;
        foreach (array_chunk($todo, self::BATCH) as $n => $batch) {
            @set_time_limit($this->timeout + 30);
            $this->tell('start', ['n' => $n * self::BATCH + \count($batch), 'total' => $total, 'label' => $batch[0]['pid']]);
            try {
                $vectors = $this->embedder->embed(array_column($batch, 'text'));
            } catch (AiException $e) {
                // Rejected: a text at a time; only a server that stops answering stops the run
                $e = self::rejected($e) ? $this->oneByOne($batch, $model, $report, $e) : $e;
                if ($e === null) {
                    $this->tell('done', ['status' => 'ok']);
                    continue;
                }
                $report->count('failed', \count($batch));
                $report->note('Stopped: ' . $e->reason . ' — the embedding server did not answer as it should; the next run carries on from here.');
                $report->count('remaining', $total - $n * self::BATCH - \count($batch) + $remaining);
                $report->fail();
                $this->tell('done', ['status' => 'failed: ' . $e->reason]);

                return $report;
            }
            foreach ($batch as $i => $item) {
                $this->index->putVector($item['pid'], $model, $item['sha'], $vectors[$i]);
            }
            $report->count('embedded', \count($batch));
            $this->tell('done', ['status' => 'ok']);
        }
        $report->count('remaining', $remaining);

        return $report;
    }

    /** The server answered, and refused these texts: not a reason to stop */
    private static function rejected(AiException $e): bool
    {
        return $e->reason === 'provider_error' && $e->status !== null && $e->status >= 400 && $e->status < 500;
    }

    /**
     * A rejected batch again, a text at a time: what the server takes is
     * embedded, what it still rejects is `failed` (asked again next run)
     *
     * @param list<array{pid: string, sha: string, text: string}> $batch
     *
     * @return ?AiException the error that stops the run (the server stopped
     *                      answering), with nothing of this batch written; null: carry on
     */
    private function oneByOne(array $batch, string $model, MaintenanceReport $report, AiException $first): ?AiException
    {
        $failed = 0;
        $vectors = [];
        foreach ($batch as $item) {
            try {
                $vectors[$item['pid']] = $this->embedder?->embed([$item['text']])[0] ?? null;
            } catch (AiException $e) {
                if (!self::rejected($e)) {
                    return $e;
                }
                ++$failed;
            }
        }
        foreach ($batch as $item) {
            $vector = $vectors[$item['pid']] ?? null;
            if ($vector !== null) {
                $this->index->putVector($item['pid'], $model, $item['sha'], $vector);
                $report->count('embedded');
            }
        }
        if ($failed > 0) {
            $report->count('failed', $failed);
            $report->note('Rejected by the embedding server: ' . $failed . ' report(s) — ' . ($first->detail !== '' ? $first->detail : $first->reason) . '. Asked again on the next run.');
        }

        return null;
    }

    /** @param array<string, mixed> $info */
    private function tell(string $event, array $info): void
    {
        if ($this->progress !== null) {
            ($this->progress)($event, $info);
        }
    }
}
