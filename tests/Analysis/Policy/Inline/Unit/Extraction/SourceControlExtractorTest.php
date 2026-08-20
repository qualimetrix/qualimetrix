<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit\Extraction;

use LogicException;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Policy\Inline\Contract\SourceControls;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Extraction\SourceControlExtractor;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;
use ReflectionMethod;

#[CoversClass(SourceControls::class)]
#[CoversClass(SourceControlExtractor::class)]
final class SourceControlExtractorTest extends TestCase
{
    #[Test]
    public function itKeepsPhysicalControlsAndBindsSymbolControlsAndMalformedThresholds(): void
    {
        $ast = $this->parse(<<<'PHP'
            <?php
            /** @qmx-ignore-file size.loc file reason */
            class Named
            {
                /** @qmx-ignore complexity.cyclomatic class reason */
                public function run(
                    /** @qmx-ignore-next-line size.method-count next reason */
                    int $value,
                ): void {}

                /** @qmx-threshold complexity.cyclomatic broken */
                public function invalid(): void {}
            }
            PHP);
        $nodes = new NodeFinder();
        $class = $nodes->findFirstInstanceOf($ast, Node\Stmt\Class_::class);
        $methods = $nodes->findInstanceOf($ast, Node\Stmt\ClassMethod::class);
        self::assertInstanceOf(Node\Stmt\Class_::class, $class);
        self::assertCount(2, $methods);

        $file = RelativePath::fromString('src/Example.php');
        $classDeclaration = DeclarationPath::of(SymbolPath::forClass('App', 'Named'), $file, DeclarationOrdinal::fromRank(0));
        $owner = new LogicalClassPath(SymbolPath::forClass('App', 'Named'));
        $run = DeclarationPath::of(SymbolPath::forMethod('App', 'Named', 'run'), $file, DeclarationOrdinal::fromRank(0));
        $invalid = DeclarationPath::of(SymbolPath::forMethod('App', 'Named', 'invalid'), $file, DeclarationOrdinal::fromRank(0));
        $classMetrics = new ClassWithMetrics($classDeclaration, $class->getStartFilePos(), $class->getStartLine(), new MetricBag());
        $callables = [
            new CallableWithMetrics($run, $methods[0]->getStartFilePos(), CallableKind::Method, null, $classDeclaration, $owner, new MetricBag()),
            new CallableWithMetrics($invalid, $methods[1]->getStartFilePos(), CallableKind::Method, null, $classDeclaration, $owner, new MetricBag()),
        ];
        $classes = [
            $classMetrics->subject->toCanonical() => [
                'subject' => $classMetrics->subject,
                'metrics' => $classMetrics->metrics,
                'line' => $classMetrics->line,
                'start' => $classMetrics->startFilePos,
            ],
        ];

        $controls = (new SourceControlExtractor())->extract(
            $ast,
            $file,
            $callables,
            $classes,
        );

        self::assertContains(SuppressionType::File, array_map(static fn($suppression): SuppressionType => $suppression->type, $controls->suppressions));
        self::assertContains(SuppressionType::NextLine, array_map(static fn($suppression): SuppressionType => $suppression->type, $controls->suppressions));
        $symbol = array_values(array_filter($controls->suppressions, static fn($suppression): bool => $suppression->type === SuppressionType::Symbol));
        self::assertCount(1, $symbol);
        self::assertSame($run->toCanonical(), $symbol[0]->subject?->toCanonical());
        self::assertCount(0, $controls->thresholdOverrides);
        self::assertCount(1, $controls->thresholdDiagnostics);
        self::assertSame($invalid->toCanonical(), $controls->thresholdDiagnostics[0]->subject->toCanonical());
    }

    #[Test]
    public function itKeepsDistinctControlScopesWhileCollapsingTrueDuplicates(): void
    {
        $subject = new ClassWithMetrics(
            DeclarationPath::of(SymbolPath::forClass('App', 'Named'), RelativePath::fromString('src/Example.php'), DeclarationOrdinal::fromRank(0)),
            10,
            1,
            new MetricBag(),
        )->subject;
        $classControl = new Suppression('complexity.cyclomatic', 'reason', 4, SuppressionType::Symbol, 12, $subject, ControlScope::Class_);
        $callableControl = new Suppression('complexity.cyclomatic', 'reason', 4, SuppressionType::Symbol, 12, $subject, ControlScope::Callable);
        $method = new ReflectionMethod(SourceControlExtractor::class, 'deduplicate');

        /** @var list<Suppression> $deduplicated */
        $deduplicated = $method->invoke(null, [$classControl, $callableControl, $classControl]);

        self::assertCount(2, $deduplicated);
        self::assertSame([
            ControlScope::Class_,
            ControlScope::Callable,
        ], array_map(
            static fn(Suppression $suppression): ControlScope => $suppression->controlScope
                ?? throw new LogicException('Expected a symbol control scope'),
            $deduplicated,
        ));
    }

    /**
     * P1's identity comparison excluded the ordinal, so two producers giving
     * one physical declaration two different ordinals were merged into one
     * binding. P2 makes the ordinal part of identity ({@see
     * DeclarationControlBindings::assertCompatibleSourceMetadata()}), so the
     * same disagreement is now rejected outright rather than silently
     * applying one control to both.
     */
    #[Test]
    public function itRejectsSourceControlBindingWhenTwoOrdinalsClaimOnePhysicalPosition(): void
    {
        $ast = $this->parse("<?php\n/** @qmx-ignore complexity.cyclomatic collision */\nfunction run(int \$value): void {}\n");
        $function = (new NodeFinder())->findFirstInstanceOf($ast, Node\Stmt\Function_::class);
        self::assertInstanceOf(Node\Stmt\Function_::class, $function);

        $file = RelativePath::fromString('src/Example.php');
        $first = DeclarationPath::of(SymbolPath::forGlobalFunction('App', 'run'), $file, DeclarationOrdinal::fromRank(0));
        $second = DeclarationPath::of(SymbolPath::forGlobalFunction('App', 'run'), $file, DeclarationOrdinal::fromRank(1));

        $this->expectException(LogicException::class);
        (new SourceControlExtractor())->extract(
            $ast,
            $file,
            [
                new CallableWithMetrics($first, $function->getStartFilePos(), CallableKind::Function, null, null, null, new MetricBag()),
                new CallableWithMetrics($second, $function->getStartFilePos(), CallableKind::Function, null, null, null, new MetricBag()),
            ],
            [],
        );
    }

    #[Test]
    public function itExtractsSourceControlsWithoutRunDeclarationBindings(): void
    {
        $extractor = new SourceControlExtractor();
        $controls = $extractor->extract(
            $this->parse("<?php\n// @qmx-ignore-file complexity\nfinal class Example {}\n"),
            RelativePath::fromString('src/Example.php'),
            [],
            [],
        );

        self::assertCount(1, $controls->suppressions);
        self::assertSame(SuppressionType::File, $controls->suppressions[0]->type);
    }

    /** @return list<Node> */
    private function parse(string $source): array
    {
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($source);
        self::assertIsArray($ast);

        return array_values($ast);
    }
}
