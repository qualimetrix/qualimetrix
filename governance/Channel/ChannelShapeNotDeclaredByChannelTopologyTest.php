<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\Channel;

use FilesystemIterator;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * ADR 0031: {@see ChannelShape} moved off
 * {@see ChannelDeclaration} onto the producer. This guard is the durability
 * of that move — it does not re-check that `ChannelDeclaration` has no
 * `$shape` property (a constructor signature already makes that unstatable,
 * per {@see \Qualimetrix\Tests\Analysis\Finding\Unit\ChannelDeclarationTest}),
 * it checks that a channel-declaring call site never independently computes
 * one either, which the type system cannot rule out on its own.
 *
 * Modelled on {@see ChannelLevelAssemblyTopologyTest}'s own lesson: counting
 * a literal token is the wrong question (a shape can be built through a
 * local variable, a helper, string interpolation of the enum's `->value`,
 * none of which a literal-count would see). The question this guard asks
 * instead is about *outcome*: does the method that builds a channel's
 * {@see ChannelDeclaration} — a call to {@see ChannelDeclaration::magnitude()}
 * or {@see ChannelDeclaration::occurrence()} — also reference
 * {@see ChannelShape} anywhere in its own body? After the move, a producer's
 * shape is a single class-level fact answered by its own `shape()` method or
 * `SHAPE` constant; the method that assembles one channel's declaration has
 * no reason left to name the enum at all.
 *
 * **The two exemptions the plan names are structurally out of scope, not
 * allow-listed.** {@see \Qualimetrix\Analysis\Finding\Contract\AcceptedLevel::shape()}
 * and {@see \Qualimetrix\Analysis\Policy\Baseline\BaselineEntry::shape()}
 * derive a {@see ChannelShape} from their own stored data — neither method
 * calls `ChannelDeclaration::magnitude()` or `::occurrence()`, so this guard
 * never inspects them; excluding them by name would make the guard trust a
 * list instead of the fact that they build no channel at all.
 *
 * **A helper hop is inside the guard, not outside it.** The enclosing method's
 * own body was once all this read, which left one edit — move the
 * `ChannelShape` reference into a private method and call it — sufficient to
 * pass. The search now walks the methods the enclosing method reaches inside
 * its own class, through `$this->x()`, `self::x()` and `static::x()`,
 * transitively. {@see itSeesAChannelShapeReferenceRoutedThroughAPrivateHelper()}
 * is that edit, written down as a case.
 *
 * **What it still does not see**, honestly: a hop out of the class — a
 * collaborator, a closure passed elsewhere, a call built from a variable
 * method name. Those are named here rather than discovered later.
 */
#[CoversClass(ChannelDeclaration::class)]
final class ChannelShapeNotDeclaredByChannelTopologyTest extends TestCase
{
    #[Test]
    public function itRefusesAMethodThatBuildsAChannelDeclarationWhileAlsoReferencingChannelShape(): void
    {
        $offenders = [];
        $channelBuildingCallCount = 0;

        foreach (self::productionFiles() as $file) {
            $report = self::inspect((string) file_get_contents($file), self::relative($file));
            $channelBuildingCallCount += $report['calls'];
            $offenders = [...$offenders, ...$report['offenders']];
        }

        self::assertGreaterThan(
            0,
            $channelBuildingCallCount,
            'No ChannelDeclaration::magnitude()/occurrence() call found at all — this guard is measuring nothing.',
        );
        self::assertSame(
            [],
            $offenders,
            'A method that builds one channel\'s declaration also references ' . ChannelShape::class . '.'
            . ' Shape is a producer-level fact (its own shape() method / SHAPE constant) since ADR 0031 — a'
            . ' channel-building method has no reason to compute one.',
        );
    }

    /**
     * The detector, on inputs written here by hand: one that must be caught
     * directly, one that must be caught through a helper hop, and one that
     * must not be caught at all. Without the third, "it fires" would be the
     * only thing proved, and a detector that fires on everything is no
     * cheaper to satisfy than one that fires on nothing.
     */
    #[Test]
    public function itSeesAChannelShapeReferenceInTheChannelBuildingMethodItself(): void
    {
        $report = self::inspect(self::probe('return ChannelDeclaration::magnitude($this->name(), ChannelShape::Magnitude);'), 'probe');

        self::assertSame(1, $report['calls']);
        self::assertCount(1, $report['offenders']);
        self::assertStringContainsString('build()', $report['offenders'][0]);
    }

    #[Test]
    public function itSeesAChannelShapeReferenceRoutedThroughAPrivateHelper(): void
    {
        $report = self::inspect(
            self::probe(
                'return ChannelDeclaration::magnitude($this->name(), $this->shape());',
                'private function shape(): ChannelShape { return ChannelShape::Magnitude; }',
            ),
            'probe',
        );

        self::assertSame(1, $report['calls']);
        self::assertCount(1, $report['offenders'], 'A helper hop must not be a way out of this guard.');
    }

    #[Test]
    public function itLeavesAChannelShapeReferenceInAMethodTheBuilderNeverCallsAlone(): void
    {
        $report = self::inspect(
            self::probe(
                'return ChannelDeclaration::magnitude($this->name(), $this->name());',
                'private function elsewhere(): ChannelShape { return ChannelShape::Magnitude; }',
            ),
            'probe',
        );

        self::assertSame(1, $report['calls']);
        self::assertSame([], $report['offenders'], 'A reference the builder never reaches is not this guard\'s business.');
    }

    private static function probe(string $buildBody, string $extraMethod = ''): string
    {
        return <<<PHP
            <?php
            class Probe {
                public function build(): ChannelDeclaration { {$buildBody} }
                private function name(): string { return 'probe'; }
                {$extraMethod}
            }
            PHP;
    }

    /**
     * @return array{offenders: list<string>, calls: int}
     */
    private static function inspect(string $source, string $label): array
    {
        $ast = (new ParserFactory())->createForHostVersion()->parse($source) ?? [];
        $finder = new NodeFinder();

        /** @var list<StaticCall> $calls */
        $calls = $finder->find($ast, static fn(Node $node): bool => $node instanceof StaticCall
            && $node->class instanceof Name
            && $node->class->toString() === 'ChannelDeclaration'
            && $node->name instanceof Node\Identifier
            && \in_array($node->name->toString(), ['magnitude', 'occurrence'], true));

        $offenders = [];

        foreach ($calls as $call) {
            $method = self::enclosingMethod($ast, $call);

            if ($method === null) {
                continue;
            }

            foreach (self::methodsReachedFrom($ast, $method) as $reached) {
                $shapeReferences = $finder->find(
                    $reached,
                    static fn(Node $node): bool => $node instanceof ClassConstFetch
                        && $node->class instanceof Name
                        && $node->class->toString() === 'ChannelShape',
                );

                if ($shapeReferences === []) {
                    continue;
                }

                $offenders[] = \sprintf(
                    '%s:%d in %s()%s',
                    $label,
                    $call->getStartLine(),
                    $method->name->toString(),
                    $reached === $method ? '' : ' via ' . $reached->name->toString() . '()',
                );

                break;
            }
        }

        return ['offenders' => $offenders, 'calls' => \count($calls)];
    }

    /**
     * The method itself plus every method of its own class it reaches through
     * `$this->x()`, `self::x()` or `static::x()`, transitively.
     *
     * @param array<Node> $ast
     *
     * @return list<ClassMethod>
     */
    private static function methodsReachedFrom(array $ast, ClassMethod $entry): array
    {
        $finder = new NodeFinder();
        $siblings = [];

        /** @var list<Class_> $classes */
        $classes = $finder->findInstanceOf($ast, Class_::class);

        foreach ($classes as $class) {
            $methods = $finder->findInstanceOf($class, ClassMethod::class);

            if (!\in_array($entry, $methods, true)) {
                continue;
            }

            foreach ($methods as $method) {
                $siblings[$method->name->toString()] = $method;
            }
        }

        $reached = [$entry];
        $queue = [$entry];

        while ($queue !== []) {
            $current = array_shift($queue);

            /** @var list<MethodCall|StaticCall> $invocations */
            $invocations = $finder->find($current, static fn(Node $node): bool => ($node instanceof MethodCall
                    && $node->var instanceof Variable
                    && $node->var->name === 'this')
                || ($node instanceof StaticCall
                    && $node->class instanceof Name
                    && \in_array($node->class->toString(), ['self', 'static'], true)));

            foreach ($invocations as $invocation) {
                if (!$invocation->name instanceof Node\Identifier) {
                    continue;
                }

                $target = $siblings[$invocation->name->toString()] ?? null;

                if ($target === null || \in_array($target, $reached, true)) {
                    continue;
                }

                $reached[] = $target;
                $queue[] = $target;
            }
        }

        return $reached;
    }

    /**
     * @param array<Node> $ast
     */
    private static function enclosingMethod(array $ast, Node $target): ?ClassMethod
    {
        $finder = new NodeFinder();
        /** @var list<ClassMethod> $methods */
        $methods = $finder->findInstanceOf($ast, ClassMethod::class);

        foreach ($methods as $method) {
            $span = $finder->find($method, static fn(Node $node): bool => $node === $target);

            if ($span !== []) {
                return $method;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function productionFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::sourceRoot(), FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private static function sourceRoot(): string
    {
        return \dirname(__DIR__, 2) . '/src';
    }

    private static function relative(string $file): string
    {
        return 'src' . substr($file, \strlen(self::sourceRoot()));
    }
}
