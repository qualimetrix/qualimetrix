<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Metric;

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
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(CallableWithMetrics::class)]
final class CallableWithMetricsTest extends TestCase
{
    #[Test]
    public function itKeepsTheFinalMethodIdentityAndClassOwner(): void
    {
        $metrics = (new MetricBag())->with('ccn', 5);

        $declaration = DeclarationPath::of(SymbolPath::forMethod('App\\Service', 'UserService', 'calculate'), RelativePath::fromString('src/UserService.php'), DeclarationOrdinal::fromRank(0));
        $method = new CallableWithMetrics(
            declarationPath: $declaration,
            startFilePos: 0,
            kind: CallableKind::Method,
            anonymousSyntax: null,
            lexicalClassContext: DeclarationPath::of(SymbolPath::forClass('App\\Service', 'UserService'), RelativePath::fromString('src/UserService.php'), DeclarationOrdinal::fromRank(0)),
            classAggregationOwner: new LogicalClassPath(SymbolPath::forClass('App\\Service', 'UserService')),
            metrics: $metrics,
        );

        self::assertSame($declaration, $method->declarationPath);
        self::assertSame(CallableKind::Method, $method->kind);
        self::assertSame('class:App\\Service\\UserService', $method->classAggregationOwner?->toCanonical());
    }

    #[Test]
    public function itKeepsAFunctionOutsideClassAggregation(): void
    {
        $metrics = (new MetricBag())->with('ccn', 2);

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
        $metrics = (new MetricBag())->with('ccn', 1);

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
        $metrics = (new MetricBag())->with('ccn', 7);

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
}
