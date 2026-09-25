<?php

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Audit\AuditLog;
use Reporion\Storage\FlatFile;

/**
 * bin/reporion import:rollback --batch <id>
 *
 * Deletes imported pages (via commit-log.json) only if still status: archived.
 * Refuses to delete pages that have been signed or edited since import.
 */
final class ImportRollbackCommand implements CommandInterface
{
    private readonly AuditLog $audit;

    public function __construct(
        private readonly string $dataRoot,
        private readonly FlatFile $storage,
        ?AuditLog $audit = null,
    ) {
        $this->audit = $audit ?? new AuditLog($dataRoot . '/audit');
    }

    public function run(array $args, Output $output): int
    {
        $options = self::parseOptions($args);
        $batchId = $options['batch'] ?? null;

        if ($batchId === null) {
            $output->error('Usage: bin/reporion import:rollback --batch <id>');
            return 1;
        }

        $batchDir = $this->dataRoot . '/import/' . $batchId;
        $commitLogFile = $batchDir . '/commit-log.json';

        if (!is_file($commitLogFile)) {
            $output->error("No commit log found. Run import:commit first.");
            return 1;
        }

        $commitLog = json_decode((string) file_get_contents($commitLogFile), true);
        $entries = $commitLog['entries'] ?? [];
        $deleted = 0;
        $protected = 0;

        foreach ($entries as $entry) {
            // Legacy logs (before the commit commands logged $record->pid)
            // hold the whole PageRecord under 'pid'; its path is the one
            // create() actually allocated, which target_path may not be.
            $targetPath = \is_array($entry['pid'] ?? null) && \is_string($entry['pid']['path'] ?? null)
                ? $entry['pid']['path']
                : ($entry['target_path'] ?? null);
            if ($targetPath === null) {
                continue;
            }

            try {
                // Check if page exists and is still archived
                $page = $this->storage->read($targetPath);

                // Only delete the page this batch created, untouched since.
                // Status alone can't tell: an edit keeps an archived page
                // archived (Storage\FlatFile::save()), so rev is the signal.
                $entryPid = self::entryPid($entry);
                if ($entryPid !== null && $page->pid !== $entryPid) {
                    $output->line("Protected: {$targetPath} (a different page now lives here, skipped)");
                    $protected++;
                    continue;
                }
                if ($page->status !== 'archived' || $page->rev > 1) {
                    $output->line("Protected: {$targetPath} (edited or signed since import, skipped)");
                    $protected++;
                    continue;
                }

                // Delete the page
                $this->storage->delete($targetPath, 'import');
                $this->audit->record('page.delete', 'import', null, $page->pid, $page->path, $page->rev, extra: ['batch' => $batchId, 'reason' => 'rollback']);
                $deleted++;
            } catch (\Exception $e) {
                // Page doesn't exist, skip
                continue;
            }
        }

        $output->line("Rolled back {$deleted} page(s)");
        if ($protected > 0) {
            $output->line("Protected {$protected} page(s) that were edited/signed");
        }

        return 0;
    }

    /**
     * The pid a commit-log entry recorded: a string, or — in legacy logs —
     * nested inside the serialised PageRecord. Null when neither is present.
     *
     * @param array<string, mixed> $entry
     */
    private static function entryPid(array $entry): ?string
    {
        $pid = $entry['pid'] ?? null;
        if (\is_array($pid)) {
            $pid = $pid['pid'] ?? null;
        }

        return \is_string($pid) && $pid !== '' ? $pid : null;
    }

    /**
     * @param list<string> $args
     *
     * @return array<string, string>
     */
    private static function parseOptions(array $args): array
    {
        $options = [];
        foreach ($args as $arg) {
            if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
                continue;
            }
            [$key, $value] = explode('=', substr($arg, 2), 2);
            $options[$key] = $value;
        }

        return $options;
    }
}
