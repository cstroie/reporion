<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

use Reporion\Exception\AiException;
use Reporion\Index\Sqlite;
use Reporion\Service\Ai\Context;
use Reporion\Service\Ai\Embedder;
use Reporion\Storage\FileTree;
use Reporion\Storage\PageRecord;
use Reporion\Support\LibrarySource;
use Reporion\Support\ReportPath;
use Reporion\Support\SourcePassages;
use RuntimeException;

/**
 * index:vectors: the embeddings of Similar reports (roadmap phase 34e,
 * 2026-10-08), from disk into `page_vectors`. For every report, signed or
 * not, what Context::forEmbedding() gives (the conclusion, de-identified);
 * a report whose vector was made by the same model from the same text is
 * `current` and asks nothing, any other is embedded. BATCH texts per
 * request to the one embedding model Admin → AI names. Vectors of pages
 * that are gone are dropped. Check counts and sends nothing; --limit bounds
 * a run (the next one carries on); --force embeds every report again, the
 * current ones too (a rebuild of the vectors, e.g. after the redaction rules
 * changed). Writes no page: the index is a cache
 * (invariant 1). A server that fails stops the run; a batch the server
 * rejects (HTTP 4xx: a text too long for the model, say) is retried one
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
        private readonly FileTree $storage,
        private readonly Sqlite $index,
        private readonly ?Embedder $embedder,
        private readonly int $timeout = 120,
        private readonly string $libraryNamespace = 'library',
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
        foreach ([$apply ? 'embedded' : 'would_embed', 'current', 'no_text', 'withheld', 'dropped', 'failed', 'unreadable', ...($apply ? ['remaining'] : []),
            $apply ? 'sources_embedded' : 'sources_would_embed', 'sources_current', 'sources_no_text', 'sources_dropped', 'passages_withheld', 'passages_failed', ...($apply ? ['passages_embedded'] : [])] as $key) {
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
            } catch (RuntimeException) {
                $report->unreadable($path);
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

        // Library sources (docs/library.md, phase 7): their passages, a source at a time
        $passageStates = $this->index->passageStates();
        $sources = [];
        $seenSources = [];
        foreach ($this->storage->allPaths() as $path) {
            if (!str_starts_with($path, $this->libraryNamespace . ':')) {
                continue;
            }
            try {
                $page = $this->storage->read($path);
            } catch (RuntimeException) {
                $report->unreadable($path);
                continue;
            }
            if (!LibrarySource::isSource($page->frontmatter)) {
                continue;
            }
            $seenSources[$page->pid] = true;
            $passages = SourcePassages::ofSource($page->body);
            if ($passages === []) {
                $report->count('sources_no_text');
                continue;
            }
            $shas = array_map(static fn (array $p): string => Embedder::sha($model, $p['text']), $passages);
            $state = $passageStates[$page->pid] ?? null;
            if (!$force && $state !== null && $state['model'] === $model && $state['shas'] === $shas) {
                $report->count('sources_current');
                continue;
            }
            $sources[] = ['page' => $page, 'passages' => $passages, 'shas' => $shas];
        }
        $goneSources = array_values(array_diff(array_keys($passageStates), array_keys($seenSources)));
        if ($apply) {
            $this->index->deletePassages(array_map('strval', $goneSources));
        }
        $report->count('sources_dropped', \count($goneSources));

        // --limit counts reports and whole sources alike, reports first
        $pending = \count($todo) + \count($sources);
        $todo = $limit > 0 ? \array_slice($todo, 0, $limit) : $todo;
        $sources = $limit > 0 ? \array_slice($sources, 0, max(0, $limit - \count($todo))) : $sources;
        $total = \count($todo);
        if (!$apply) {
            $report->count('would_embed', $total);
            $report->count('sources_would_embed', \count($sources));

            return $report;
        }
        // Past --limit, for the next run
        $remaining = $pending - $total - \count($sources);
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
                $report->note('Stopped: ' . $e->reason . ': the embedding server did not answer as it should; the next run carries on from here.');
                $report->count('remaining', $total - $n * self::BATCH - \count($batch) + $remaining + \count($sources));
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
        foreach ($sources as $k => $source) {
            $this->tell('start', ['n' => $k + 1, 'total' => \count($sources), 'label' => $source['page']->pid]);
            $e = $this->embedSource($source['page'], $source['passages'], $source['shas'], $model, $report);
            if ($e !== null) {
                $report->note('Stopped: ' . $e->reason . ': the embedding server did not answer as it should; the next run carries on from here.');
                $report->count('remaining', \count($sources) - $k + $remaining);
                $report->fail();
                $this->tell('done', ['status' => 'failed: ' . $e->reason]);

                return $report;
            }
            $report->count('sources_embedded');
            $this->tell('done', ['status' => 'ok']);
        }
        $report->count('remaining', $remaining);

        return $report;
    }

    /**
     * One library source's passages embedded, BATCH a request, then its rows replaced together. A
     * passage that still holds an identifier after redaction is withheld (a row with no vector,
     * never sent, never matched); one the server rejects is a row with no vector and no sha, so the
     * next run asks for the source again
     *
     * @param list<array{n: int, page: ?int, text: string}> $passages
     * @param list<string>                                  $shas
     *
     * @return ?AiException the error that stops the run, nothing of this source written; null: done
     */
    private function embedSource(PageRecord $page, array $passages, array $shas, string $model, MaintenanceReport $report): ?AiException
    {
        $rows = [];
        $send = [];
        foreach ($passages as $i => $p) {
            ['text' => $text, 'withheld' => $withheld] = Context::passageEmbeddingText($page, $p['text']);
            $rows[$i] = ['n' => $p['n'], 'sha' => $shas[$i], 'page' => $p['page'], 'vec' => []];
            if ($text === null) {
                if ($withheld) {
                    $report->count('passages_withheld');
                }
                continue;
            }
            $send[$i] = $text;
        }
        foreach (array_chunk($send, self::BATCH, true) as $batch) {
            @set_time_limit($this->timeout + 30);
            try {
                $vectors = $this->embedder?->embed(array_values($batch)) ?? [];
                foreach (array_keys($batch) as $j => $i) {
                    $rows[$i]['vec'] = $vectors[$j] ?? [];
                }
            } catch (AiException $e) {
                if (!self::rejected($e)) {
                    return $e;
                }
                // A text at a time: what the server still rejects is asked again next run
                foreach ($batch as $i => $text) {
                    try {
                        $rows[$i]['vec'] = $this->embedder?->embed([$text])[0] ?? [];
                    } catch (AiException $one) {
                        if (!self::rejected($one)) {
                            return $one;
                        }
                        $rows[$i]['sha'] = '';
                        $report->count('passages_failed');
                    }
                }
            }
        }
        $this->index->putPassages($page->pid, $model, array_values($rows));
        $report->count('passages_embedded', \count(array_filter($rows, static fn (array $r): bool => $r['vec'] !== [])));

        return null;
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
            $report->note('Rejected by the embedding server: ' . $failed . ' report(s):' . ($first->detail !== '' ? $first->detail : $first->reason) . '. Asked again on the next run.');
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
