<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Auth;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Reporion\Auth\GrantParser;
use Reporion\Auth\GrantRole;

final class GrantParserTest extends TestCase
{
    public function testParsesANamespaceAndRole(): void
    {
        $grant = GrantParser::parse('reports:mri:editor');

        self::assertSame('reports:mri', $grant->namespace);
        self::assertSame(GrantRole::Editor, $grant->role);
    }

    public function testParsesAViewerGrant(): void
    {
        $grant = GrantParser::parse('reports:ct:viewer');

        self::assertSame(GrantRole::Viewer, $grant->role);
    }

    public function testSplitsOnTheLastColonNotTheFirst(): void
    {
        // A three-segment namespace must not be mistaken for
        // namespace "reports" + role "mri:mioveni:editor".
        $grant = GrantParser::parse('reports:mri:mioveni:editor');

        self::assertSame('reports:mri:mioveni', $grant->namespace);
        self::assertSame(GrantRole::Editor, $grant->role);
    }

    public function testBareNamespaceWithNoRoleIsRejectedNotSilentlyMisparsed(): void
    {
        // "reports:mri" alone must not be read as namespace "reports" +
        // role "mri" — GrantRole::tryFrom('mri') is null, so this must
        // throw, not fabricate a Grant with the wrong namespace.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/role/i');
        GrantParser::parse('reports:mri');
    }

    public function testNoColonAtAllIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GrantParser::parse('reportsmri');
    }

    public function testInvalidRoleNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GrantParser::parse('reports:mri:admin');
    }
}
