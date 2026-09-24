<?php

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Storage\FlatFile;

/**
 * bin/reporion import:rollback --batch <id>
 *
 * Deletes imported pages (via commit-log.json) only if still status: archived.
 * Refuses to delete pages that have been signed or edited since import.
 */
final class ImportRollbackCommand implements CommandInterface
{
    public function __construct(
        private readonly string $dataRoot,
        private readonly FlatFile $storage,
    ) {}

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
            $targetPath = $entry['target_path'] ?? null;
            if ($targetPath === null) {
                continue;
            }

            try {
                // Check if page exists and is still archived
                $page = $this->storage->read($targetPath);

                // Only delete if status is still 'archived' (never edited/signed)
                if ($page->status !== 'archived') {
                    $output->line("Protected: {$targetPath} (status changed — not archived, skipped)");
                    $protected++;
                    continue;
                }

                // Delete the page
                $this->storage->delete($targetPath, 'import');
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
