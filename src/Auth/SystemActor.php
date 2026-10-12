<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Auth;

/**
 * The pseudousers: who a revision or an audit line names when the instance
 * itself wrote — the automatic summary, the tags, an import from the HIS —
 * rather than the person who started it. Not accounts: no file in
 * `data/users/`, no password, no grant, no token, no session (D35 is
 * untouched). The colon cannot appear in a username
 * (FlatFileUserStore::assertUsername()), so a name here never clashes with
 * an account.
 *
 * A revision names the pseudouser; the audit line carries the person as
 * `on_behalf_of`, so nothing is anonymous. Signing is never one of them:
 * a signature is always a person (D37).
 */
final class SystemActor
{
    public const SUMMARIZER = 'system:summarizer';
    public const TAGGER = 'system:tagger';
    /** The HIS (FHIR) and the booking table: data brought in from outside */
    public const IMPORTER = 'system:importer';
    public const PACS = 'system:pacs';
    /** Reserved, no caller yet: the assistant never saves by itself in the editor (D8) */
    public const AI = 'system:ai';
    /** Reserved, no caller yet: instance housekeeping that rewrites pages */
    public const ADMIN = 'system:admin';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::SUMMARIZER, self::TAGGER, self::IMPORTER, self::PACS, self::AI, self::ADMIN];
    }

    public static function isSystem(string $actor): bool
    {
        return \in_array($actor, self::all(), true);
    }
}
