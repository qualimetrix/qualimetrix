<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\Symbol\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\MixedSpelling;

#[CoversClass(MixedSpelling::class)]
final class MixedSpellingTest extends TestCase
{
    #[Test]
    public function itCarriesValidatedMixedSpellingEvidence(): void
    {
        $evidence = new MixedSpelling('class', ['App\\Order', 'app\\order'], 'App\\Order');

        self::assertSame('class', $evidence->kind);
        self::assertSame(['App\\Order', 'app\\order'], $evidence->spellings);
        self::assertSame('App\\Order', $evidence->canonical);
    }

    #[Test]
    public function itAcceptsTheInstalledDeclarationSpellingAsTheExternalCanonicalName(): void
    {
        $evidence = new MixedSpelling(
            'external',
            ['Vendor\\FOO', 'vendor\\foo'],
            'vendor\\Foo',
        );

        self::assertSame('vendor\\Foo', $evidence->canonical);
    }

    #[Test]
    public function itRefusesSpellingsFromDifferentIdentities(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mixed spellings must identify the same PHP class name');

        new MixedSpelling('external', ['Vendor\\Alpha', 'Vendor\\Beta'], 'Vendor\\Alpha');
    }

    #[Test]
    public function itRefusesANonCanonicalAnswer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mixed spelling canonical name must be the byte-minimum spelling');

        new MixedSpelling('namespace', ['App\\Domain', 'app\\domain'], 'app\\domain');
    }

    #[Test]
    public function itKeepsTheByteMinimumContractForClasses(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mixed spelling canonical name must be the byte-minimum spelling');

        new MixedSpelling('class', ['App\\Order', 'app\\order'], 'app\\order');
    }

    #[Test]
    public function itRefusesAnInstalledSpellingForAnotherExternalIdentity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mixed spelling canonical name must identify the same PHP class name');

        new MixedSpelling('external', ['Vendor\\FOO', 'vendor\\foo'], 'Vendor\\Bar');
    }
}
