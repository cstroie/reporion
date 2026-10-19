<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Auth;

use InvalidArgumentException;
use Reporion\Support\ReportPath;

/**
 * An account record, straight from `data/users/{username}.json` (D36)
 * disk-authoritative, same as a page. Immutable: every change (role,
 * grants, active) goes through UserStoreInterface::save() with a whole new
 * instance, never a setter.
 */
final class User
{
    /**
     * @param list<Grant> $grants ignored when $isOwner is true: an owner's
     *                            access is instance-wide, not grant-based
     */
    public function __construct(
        public readonly string $username,
        public readonly string $passwordHash,
        public readonly bool $isOwner,
        public readonly array $grants,
        public readonly bool $active,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        // How the account signs a printed report (name, professional
        // title); optional, empty when never set.
        public readonly string $displayName = '',
        public readonly string $title = '',
        // API tokens (roadmap phase 13): per token its id, name, scope
        // (read|write), created, last_used and the sha256 of its secret
        // never the secret itself.
        /** @var list<array{id: string, name: string, scope: string, created: string, last_used: ?string, hash: string}> */
        public readonly array $tokens = [],
        // Namespaces pinned to the quick-navigation menu (2026-10-01), in
        // the order pinned; Auth\Pins says what may be one.
        /** @var list<string> */
        public readonly array $pins = [],
        // A radiologist signs reports (2026-10-13, amends D37): the write grant is still
        // needed, but no longer enough. Set by an owner in Admin → Users, never by the account itself.
        public readonly bool $radiologist = false,
        // The groups this account belongs to (D47), by name: the account's own field, stored.
        /** @var list<string> */
        public readonly array $groups = [],
        // What those groups resolve to (withGroups()): never stored, re-read with the record on
        // every request, so a group edit takes effect on its members' very next request.
        /** @var list<Grant> */
        public readonly array $groupGrants = [],
        public readonly bool $groupRadiologist = false,
        // The doctor's stamp (parafă) code, optional; the account's own field, stored, set on /profile.
        public readonly string $stamp = '',
        // Saved views (2026-10-19): named searches in the quick-navigation menu; Auth\SavedViews says what may be one.
        /** @var list<array{name: string, query: string}> */
        public readonly array $views = [],
    ) {
        // roleOn() calls Grant::covers() on every element without a further
        // type check: a bare string or anything non-Grant slipping in here
        // would fatal inside the one predicate that decides who can read a
        // private report, not at construction time where it's easy to spot.
        foreach ([...$grants, ...$groupGrants] as $grant) {
            if (!$grant instanceof Grant) {
                throw new InvalidArgumentException('grants must be a list of Grant');
            }
        }
    }

    /**
     * This account with $changes applied, everything else kept (the resolved group fields too
     * a store's save() writes only the account's own fields).
     *
     * @param ?list<array{id: string, name: string, scope: string, created: string, last_used: ?string, hash: string}> $tokens
     * @param ?list<string> $pins
     * @param ?list<Grant> $grants
     * @param ?list<string> $groups
     * @param ?list<array{name: string, query: string}> $views
     */
    public function with(?bool $active = null, ?string $displayName = null, ?string $title = null, ?string $passwordHash = null, ?array $tokens = null, ?array $pins = null, ?bool $radiologist = null, ?array $grants = null, ?array $groups = null, ?bool $isOwner = null, ?string $stamp = null, ?array $views = null): self
    {
        return new self(
            $this->username,
            $passwordHash ?? $this->passwordHash,
            $isOwner ?? $this->isOwner,
            $grants ?? $this->grants,
            $active ?? $this->active,
            $this->createdAt,
            $this->updatedAt,
            $displayName ?? $this->displayName,
            $title ?? $this->title,
            $tokens ?? $this->tokens,
            $pins ?? $this->pins,
            $radiologist ?? $this->radiologist,
            $groups ?? $this->groups,
            $this->groupGrants,
            $this->groupRadiologist,
            $stamp ?? $this->stamp,
            $views ?? $this->views,
        );
    }

    /**
     * This account with its groups resolved (D47): $groups are the records it names, as found
     * a name with no record is simply left out, so a deleted group never locks anyone out.
     *
     * @param list<Group> $groups
     */
    public function withGroups(array $groups): self
    {
        $grants = [];
        $radiologist = false;
        foreach ($groups as $group) {
            if (!\in_array($group->name, $this->groups, true)) {
                continue;
            }
            array_push($grants, ...$group->grants);
            $radiologist = $radiologist || $group->radiologist;
        }

        return new self(
            $this->username, $this->passwordHash, $this->isOwner, $this->grants, $this->active,
            $this->createdAt, $this->updatedAt, $this->displayName, $this->title, $this->tokens,
            $this->pins, $this->radiologist, $this->groups, $grants, $radiologist, $this->stamp, $this->views,
        );
    }

    /**
     * A radiologist (D37 amended, D47): set on the account by an owner, or given by one of its
     * groups. Only a radiologist sees patient pages, signs, and uses the report features.
     */
    public function isRadiologist(): bool
    {
        return $this->radiologist || $this->groupRadiologist;
    }

    /** The name a printed report shows for this account: display name, else username. */
    public function signatureName(): string
    {
        return $this->displayName !== '' ? $this->displayName : $this->username;
    }

    /** The root of every account's own pages (D46) */
    public const PERSONAL_ROOT = 'users';

    /**
     * Page-directory entries and reserved segments a path cannot use (Storage\FlatFile::assertValidPath):
     * an account named like one has no personal namespace, because no page could live there
     */
    private const RESERVED_SEGMENTS = ['rev', 'media.json', 'current.md', 'meta.json', 'redirect', '.', '..'];

    /**
     * This account's own namespace, `users:{username}` (D46), or null when it has none:
     * a pseudouser (a colon in the name:`system:summarizer`, not an account) or a username that
     * is a reserved path segment.
     */
    public function personalNamespace(): ?string
    {
        if ($this->username === '' || str_contains($this->username, ':') || str_contains($this->username, '/')
            || \in_array($this->username, self::RESERVED_SEGMENTS, true) || str_starts_with($this->username, 'autosave.')) {
            return null;
        }

        return self::PERSONAL_ROOT . ':' . $this->username;
    }

    /**
     * What this account may reach (D46, D47): its own grants, its groups' grants, and the implicit
     * editor grant on its own namespace `users:{username}`: derived here and never stored (D36:
     * disk holds only what an owner granted). For anyone not a radiologist, every grant on a report
     * namespace (`reports`, `reports:*`) is dropped: patient pages are a radiologist's, whatever an
     * owner granted. An owner is instance-wide already and gets nothing added. This, not `$grants`,
     * is what the access checks and the visibility predicate (Search\Query) read.
     *
     * @return list<Grant>
     */
    public function effectiveGrants(): array
    {
        if ($this->isOwner) {
            return $this->grants;
        }

        $grants = [...$this->grants, ...$this->groupGrants];
        if (!$this->isRadiologist()) {
            $grants = array_values(array_filter(
                $grants,
                static fn (Grant $g): bool => !ReportPath::isReportNamespace($g->namespace)
            ));
        }
        $own = $this->personalNamespace();

        return $own === null ? $grants : [...$grants, new Grant($own, GrantRole::Editor)];
    }

    /**
     * Highest role this account holds on $ns: the most permissive covering grant wins (editor
     * beats viewer). An owner is an editor everywhere: except on a report namespace when not a
     * radiologist (D47): there an owner reads and administers, never writes.
     */
    public function roleOn(string $ns): ?GrantRole
    {
        if ($this->isOwner) {
            return ReportPath::isReportNamespace($ns) && !$this->isRadiologist() ? GrantRole::Viewer : GrantRole::Editor;
        }

        $best = null;
        foreach ($this->effectiveGrants() as $grant) {
            if (!$grant->covers($ns)) {
                continue;
            }
            if ($grant->role === GrantRole::Editor) {
                return GrantRole::Editor;
            }
            $best = GrantRole::Viewer;
        }

        return $best;
    }

    /** May this account sign the report at $path: a radiologist, with the write grant there (D37, amended) */
    public function canSign(string $path): bool
    {
        return $this->canActOnReport($path);
    }

    /**
     * May this account use the report features on $path (D47): write or create a report, sign,
     * join and merge, the assistant on a report, the PACS/HIS tabs: a radiologist with the write
     * grant there. An owner who is not a radiologist reads and administers reports, never writes them.
     */
    public function canActOnReport(string $path): bool
    {
        return $this->isRadiologist() && $this->canWrite($path);
    }

    public function canWrite(string $ns): bool
    {
        return $this->roleOn($ns) === GrantRole::Editor;
    }

    public function canRead(string $ns): bool
    {
        return $this->roleOn($ns) !== null;
    }

    /**
     * True if this account could write *somewhere*, regardless of which
     * namespace. Exists for the one case canWrite($ns) can't answer: a
     * write endpoint that doesn't know the target namespace yet because
     * the request that would carry it hasn't been validated as well-formed
     * (PagesApiController::create(): the namespace is inside the JSON
     * body, not a route parameter, so there is no $ns to check before the
     * body is parsed). This is a coarse gate only: a caller passing it
     * still needs canWrite($ns) checked against the real namespace once
     * it's known.
     */
    /**
     * Whether the only place this account may write is its own namespace (D46): not an owner, and no
     * explicit editor grant. The new-page form then starts there.
     */
    public function writesOnlyInOwnNamespace(): bool
    {
        if ($this->isOwner || $this->personalNamespace() === null) {
            return false;
        }
        foreach ($this->effectiveGrants() as $grant) {
            if ($grant->role === GrantRole::Editor && $grant->namespace !== $this->personalNamespace()) {
                return false;
            }
        }

        return true;
    }

    public function hasAnyWriteAccess(): bool
    {
        if ($this->isOwner) {
            return true;
        }

        foreach ($this->effectiveGrants() as $grant) {
            if ($grant->role === GrantRole::Editor) {
                return true;
            }
        }

        return false;
    }
}
