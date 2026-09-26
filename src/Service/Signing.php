<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Http\Request;
use Reporion\Schema\Loader;
use Reporion\Schema\Validator;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;

/**
 * Signing a page's current revision — shared by POST /api/v1/pages/{path}/sign
 * and the page's Sign button (GET/POST /{path}/sign). Only checks and
 * audits around Storage::sign(); the signature record itself is the
 * storage layer's (D3, D37).
 */
final class Signing
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly Loader $schemas,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * Dotted names of the `required` / `required_for: ["sign"]` fields
     * still empty (D7: they block signing, never saving).
     *
     * @return list<string>
     */
    public function missing(PageRecord $record): array
    {
        $checkFields = $record->frontmatter;
        $checkFields['status'] = $record->status;
        $checkFields['visibility'] = $record->visibility;

        return Validator::missingForSign($checkFields, $this->schemaFields($record));
    }

    /** Whether the current revision already carries a signature. */
    public static function isSigned(PageRecord $record): bool
    {
        foreach ((array) ($record->meta['signatures'] ?? []) as $signature) {
            if (\is_array($signature) && (int) ($signature['rev'] ?? 0) === $record->rev) {
                return true;
            }
        }

        return false;
    }

    /**
     * Signs the current revision as $signer and audits it (page.sign). The
     * caller has checked write access and missing(). Idempotent per
     * revision (Storage::sign()).
     */
    public function sign(PageRecord $record, User $signer, ?string $parafa, ?Request $request): PageRecord
    {
        $parafa = $parafa !== null ? trim($parafa) : null;
        $signed = $this->storage->sign($record->path, $signer->username, $this->schemaFields($record), $parafa === '' ? null : $parafa);
        $this->audit->record('page.sign', $signer->username, $request, $signed->pid, $signed->path, $signed->rev);

        return $signed;
    }

    /**
     * `frontmatter['modality']` is caller-supplied YAML that no save-time
     * validation ever checks (D7), so it can be absent, a bare string or
     * contain non-strings. Normalised defensively, so a malformed modality
     * shows up in missing() (modality is `required`) instead of as a
     * TypeError out of `Schema\Loader`.
     *
     * @return array<string, array<string, mixed>>
     */
    private function schemaFields(PageRecord $record): array
    {
        $raw = $record->frontmatter['modality'] ?? [];
        $list = \is_array($raw) ? $raw : [$raw];

        return $this->schemas->fieldsFor(array_values(array_filter($list, \is_string(...))));
    }
}
