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

        $declaration = new DeclarationPath(
            SymbolPath::forMethod('App\\Service', 'UserService', 'calculate'),
            RelativePath::fromString('src/UserService.php'),
            420,
        );
        $method = new CallableWithMetrics(
            declarationPath: $declaration,
            kind: CallableKind::Method,
            anonymousSyntax: null,
            lexicalClassContext: new DeclarationPath(
                SymbolPath::forClass('App\\Service', 'UserService'),
                RelativePath::fromString('src/UserService.php'),
                10,
            ),
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
            declarationPath: new DeclarationPath(
                SymbolPath::forGlobalFunction('App\\Utils', 'helper'),
                RelativePath::fromString('src/Functions.php'),
                10,
            ),
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
            declarationPath: new DeclarationPath(
                SymbolPath::forGlobalFunction('', '{closure#1}'),
                RelativePath::fromString('src/Functions.php'),
                5,
            ),
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
            declarationPath: new DeclarationPath(
                SymbolPath::forGlobalFunction('', '{closure#1}'),
                RelativePath::fromString('src/Functions.php'),
                100,
            ),
            kind: CallableKind::AnonymousCallable,
            anonymousSyntax: null,
            lexicalClassContext: null,
            classAggregationOwner: null,
            metrics: $metrics,
        );
    }
}
