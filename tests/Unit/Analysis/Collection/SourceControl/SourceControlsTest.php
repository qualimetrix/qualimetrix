<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Collection\SourceControl;

use LogicException;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Collection\SourceControl\SourceControls;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Run\Contract\Collection\Declaration\DeclarationBindings;
use Qualimetrix\Baseline\Suppression\SuppressionExtractor;
use Qualimetrix\Baseline\Suppression\ThresholdOverrideExtractor;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Suppression\ControlScope;
use Qualimetrix\Core\Suppression\Suppression;
use Qualimetrix\Core\Suppression\SuppressionType;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;
use ReflectionMethod;

#[CoversClass(SourceControls::class)]
final class SourceControlsTest extends TestCase
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
        $classDeclaration = new DeclarationPath(SymbolPath::forClass('App', 'Named'), $file, $class->getStartFilePos());
        $owner = new LogicalClassPath(SymbolPath::forClass('App', 'Named'));
        $run = new DeclarationPath(SymbolPath::forMethod('App', 'Named', 'run'), $file, $methods[0]->getStartFilePos());
        $invalid = new DeclarationPath(SymbolPath::forMethod('App', 'Named', 'invalid'), $file, $methods[1]->getStartFilePos());
        $classMetrics = new ClassWithMetrics($classDeclaration, $class->getStartLine(), new MetricBag());
        $bindings = DeclarationBindings::from($ast, $file, [
            new CallableWithMetrics($run, CallableKind::Method, null, $classDeclaration, $owner, new MetricBag()),
            new CallableWithMetrics($invalid, CallableKind::Method, null, $classDeclaration, $owner, new MetricBag()),
        ], [
            $classMetrics->subject->toCanonical() => ['subject' => $classMetrics->subject, 'metrics' => $classMetrics->metrics, 'line' => $classMetrics->line],
        ]);

        $controls = SourceControls::extract(
            $ast,
            $bindings,
            new SuppressionExtractor(),
            new ThresholdOverrideExtractor(),
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
            new DeclarationPath(SymbolPath::forClass('App', 'Named'), RelativePath::fromString('src/Example.php'), 10),
            1,
            new MetricBag(),
        )->subject;
        $classControl = new Suppression('complexity.cyclomatic', 'reason', 4, SuppressionType::Symbol, 12, $subject, ControlScope::Class_);
        $callableControl = new Suppression('complexity.cyclomatic', 'reason', 4, SuppressionType::Symbol, 12, $subject, ControlScope::Callable);
        $method = new ReflectionMethod(SourceControls::class, 'deduplicate');

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

    #[Test]
    public function itAppliesOneDirectControlToEveryOrdinalCollision(): void
    {
        $ast = $this->parse("<?php\n/** @qmx-ignore complexity.cyclomatic collision */\nfunction run(int \$value): void {}\n");
        $function = (new NodeFinder())->findFirstInstanceOf($ast, Node\Stmt\Function_::class);
        self::assertInstanceOf(Node\Stmt\Function_::class, $function);

        $file = RelativePath::fromString('src/Example.php');
        $first = new DeclarationPath(SymbolPath::forGlobalFunction('App', 'run'), $file, $function->getStartFilePos(), 0);
        $second = new DeclarationPath(SymbolPath::forGlobalFunction('App', 'run'), $file, $function->getStartFilePos(), 1);
        $controls = SourceControls::extract(
            $ast,
            DeclarationBindings::from($ast, $file, [
                new CallableWithMetrics($first, CallableKind::Function, null, null, null, new MetricBag()),
                new CallableWithMetrics($second, CallableKind::Function, null, null, null, new MetricBag()),
            ], []),
            new SuppressionExtractor(),
            new ThresholdOverrideExtractor(),
        );

        $subjects = array_map(
            static fn(Suppression $suppression): string => $suppression->subject?->toCanonical() ?? '',
            $controls->suppressions,
        );
        self::assertSame([$first->toCanonical(), $second->toCanonical()], $subjects);
    }

    /** @return list<Node> */
    private function parse(string $source): array
    {
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($source);
        self::assertIsArray($ast);

        return array_values($ast);
    }
}
