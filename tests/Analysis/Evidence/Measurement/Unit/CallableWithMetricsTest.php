<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(CallableWithMetrics::class)]
final class CallableWithMetricsTest extends TestCase
{
    #[Test]
    public function itKeepsTheFinalMethodIdentityAndClassOwner(): void
    {
        $metrics = (new MetricBag())->with('complexity.ccn', 5);

        $declaration = DeclarationPath::of(SymbolPath::forMethod('App\\Service', 'UserService', 'calculate'), RelativePath::fromString('src/UserService.php'), DeclarationOrdinal::fromRank(0));
        $method = new CallableWithMetrics(
            declarationPath: $declaration,
            startFilePos: 0,
            kind: CallableKind::Method,
            anonymousSyntax: null,
            lexicalClassContext: DeclarationPath::of(SymbolPath::forClass('App\\Service', 'UserService'), RelativePath::fromString('src/UserService.php'), DeclarationOrdinal::fromRank(0)),
            classAggregationOwner: DeclarationPath::of(SymbolPath::forClass('App\Service', 'UserService'), RelativePath::fromString('src/UserService.php'), DeclarationOrdinal::fromRank(0)),
            metrics: $metrics,
        );

        self::assertSame($declaration, $method->declarationPath);
        self::assertSame(CallableKind::Method, $method->kind);
        self::assertSame('class:App\\Service\\UserService', $method->classAggregationOwner?->logical->toCanonical());
    }

    #[Test]
    public function itKeepsAFunctionOutsideClassAggregation(): void
    {
        $metrics = (new MetricBag())->with('complexity.ccn', 2);

        $method = new CallableWithMetrics(
            declarationPath: DeclarationPath::of(SymbolPath::forGlobalFunction('App\\Utils', 'helper'), RelativePath::fromString('src/Functions.php'), DeclarationOrdinal::fromRank(0)),
            startFilePos: 10,
            kind: CallableKind::Function,
            anonymousSyntax: null,
            lexicalClassContext: null,
            classAggregationOwner: null,
            metrics: $metrics,
        );

        self::assertNull($method->classAggregationOwner);
    }

    #[Test]
    public function itRequiresSyntaxForAnAnonymousCallable(): void
    {
        $metrics = (new MetricBag())->with('complexity.ccn', 1);

        $method = new CallableWithMetrics(
            declarationPath: DeclarationPath::of(SymbolPath::forGlobalFunction('', '{closure#1}'), RelativePath::fromString('src/Functions.php'), DeclarationOrdinal::fromRank(0)),
            startFilePos: 5,
            kind: CallableKind::AnonymousCallable,
            anonymousSyntax: 'arrow',
            lexicalClassContext: null,
            classAggregationOwner: null,
            metrics: $metrics,
        );

        self::assertSame('arrow', $method->anonymousSyntax);
    }

    #[Test]
    public function itRejectsInvalidAnonymousSyntax(): void
    {
        $metrics = (new MetricBag())->with('complexity.ccn', 7);

        $this->expectException(InvalidArgumentException::class);

        new CallableWithMetrics(
            declarationPath: DeclarationPath::of(SymbolPath::forGlobalFunction('', '{closure#1}'), RelativePath::fromString('src/Functions.php'), DeclarationOrdinal::fromRank(1)),
            startFilePos: 100,
            kind: CallableKind::AnonymousCallable,
            anonymousSyntax: null,
            lexicalClassContext: null,
            classAggregationOwner: null,
            metrics: $metrics,
        );
    }

    #[Test]
    public function itRejectsInvalidClassOwnershipAtConstruction(): void
    {
        $file = RelativePath::fromString('src/Service.php');
        $class = DeclarationPath::of(SymbolPath::forClass('App', 'Service'), $file, DeclarationOrdinal::fromRank(0));
        $callable = DeclarationPath::of(SymbolPath::forMethod('App', 'Service', 'run'), $file, DeclarationOrdinal::fromRank(0));
        foreach ([
            [CallableKind::Method, null, false],
            [CallableKind::PropertyHook, null, false],
            [CallableKind::Method, $callable, false],
            [CallableKind::Method, $class, true],
            [CallableKind::PropertyHook, $class, true],
            [CallableKind::Function, $class, false],
            [CallableKind::AnonymousCallable, $class, false],
        ] as [$kind, $owner, $anonymous]) {
            try {
                new CallableWithMetrics($callable, 1, $kind, $kind === CallableKind::AnonymousCallable ? 'closure' : null, $class, $owner, new MetricBag(), anonymousClassContext: $anonymous);
                self::fail('Invalid callable class ownership was accepted: ' . $kind->value);
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('class', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function itKeepsLexicalContextIndependentFromClassOwnership(): void
    {
        $file = RelativePath::fromString('src/Service.php');
        $class = DeclarationPath::of(SymbolPath::forClass('App', 'Service'), $file, DeclarationOrdinal::fromRank(0));
        $callable = DeclarationPath::of(SymbolPath::forMethod('App', 'Service', 'run'), $file, DeclarationOrdinal::fromRank(0));
        foreach ([
            [CallableKind::Method, $class, false],
            [CallableKind::PropertyHook, $class, false],
            [CallableKind::Method, null, true],
            [CallableKind::PropertyHook, null, true],
            [CallableKind::Function, null, false],
            [CallableKind::Function, null, true],
            [CallableKind::AnonymousCallable, null, false],
            [CallableKind::AnonymousCallable, null, true],
        ] as [$kind, $owner, $anonymous]) {
            $record = new CallableWithMetrics($callable, 1, $kind, $kind === CallableKind::AnonymousCallable ? 'closure' : null, $class, $owner, new MetricBag(), anonymousClassContext: $anonymous);
            self::assertSame($class, $record->lexicalClassContext);
            self::assertSame($owner, $record->classAggregationOwner);
            self::assertSame($anonymous, $record->anonymousClassContext);
        }
    }
}
