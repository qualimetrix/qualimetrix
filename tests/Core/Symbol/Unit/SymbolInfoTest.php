<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Symbol;

use Error;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(SymbolInfo::class)]
final class SymbolInfoTest extends TestCase
{
    #[Test]
    public function itKeepsTheExactSubjectItWasConstructedFrom(): void
    {
        $subject = MetricSubject::aggregate(SymbolPath::forNamespace('App\Domain'));

        $symbolInfo = new SymbolInfo(
            symbolPath: $subject,
            file: RelativePath::fromString('src/Domain/User.php'),
            line: 10,
        );

        self::assertSame($subject, $symbolInfo->subject);
        self::assertEquals($subject->toSymbolPath(), $symbolInfo->symbolPath);
    }

    #[Test]
    public function itCarriesNoSubjectWhenConstructedFromALogicalPath(): void
    {
        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');

        $symbolInfo = new SymbolInfo(
            symbolPath: $symbolPath,
            file: RelativePath::fromString('src/Service/UserService.php'),
            line: 42,
        );

        self::assertNull($symbolInfo->subject);
        self::assertSame($symbolPath, $symbolInfo->symbolPath);
    }

    #[Test]
    public function itRefusesAWriteToAConstructedSymbolInfo(): void
    {
        $symbolInfo = new SymbolInfo(
            symbolPath: SymbolPath::forMethod('App', 'Test', 'method'),
            file: RelativePath::fromString('test.php'),
            line: 5,
        );

        self::expectException(Error::class);
        self::expectExceptionMessage('Cannot modify readonly property');

        // @phpstan-ignore assign.propertyProtectedSet
        $symbolInfo->line = 6;
    }

    #[Test]
    public function itRejectsInvalidStoredCallableClassOwnership(): void
    {
        $file = RelativePath::fromString('src/Service.php');
        $class = DeclarationPath::of(SymbolPath::forClass('App', 'Service'), $file, DeclarationOrdinal::fromRank(0));
        $callable = DeclarationPath::of(SymbolPath::forMethod('App', 'Service', 'run'), $file, DeclarationOrdinal::fromRank(0));
        foreach ([
            [null, $class, false],
            [CallableKind::Method, null, false],
            [CallableKind::PropertyHook, null, false],
            [CallableKind::Method, $callable, false],
            [CallableKind::Method, $class, true],
            [CallableKind::PropertyHook, $class, true],
            [CallableKind::Function, $class, false],
            [CallableKind::AnonymousCallable, $class, false],
        ] as [$kind, $owner, $anonymous]) {
            try {
                new SymbolInfo(MetricSubject::declaration($callable), $file, 1, $kind, $owner, $anonymous);
                self::fail('Invalid stored callable class ownership was accepted');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('class', strtolower($exception->getMessage()));
            }
        }
    }

    #[Test]
    public function itStoresOnlyExactNamedMemberOwnership(): void
    {
        $file = RelativePath::fromString('src/Service.php');
        $class = DeclarationPath::of(SymbolPath::forClass('App', 'Service'), $file, DeclarationOrdinal::fromRank(1));
        $subject = MetricSubject::declaration(DeclarationPath::of(SymbolPath::forMethod('App', 'Service', 'run'), $file, DeclarationOrdinal::fromRank(1)));
        foreach ([
            [null, null, false],
            [CallableKind::Method, $class, false],
            [CallableKind::PropertyHook, $class, false],
            [CallableKind::Method, null, true],
            [CallableKind::PropertyHook, null, true],
            [CallableKind::Function, null, false],
            [CallableKind::Function, null, true],
            [CallableKind::AnonymousCallable, null, false],
            [CallableKind::AnonymousCallable, null, true],
        ] as [$kind, $owner, $anonymous]) {
            $record = new SymbolInfo($subject, $file, 1, $kind, $owner, $anonymous);
            self::assertSame($subject, $record->subject);
            self::assertSame($owner, $record->classAggregationOwner);
            self::assertSame($anonymous, $record->anonymousClassContext);
        }
    }
}
