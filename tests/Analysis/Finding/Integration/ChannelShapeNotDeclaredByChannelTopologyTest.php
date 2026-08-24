<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use FilesystemIterator;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
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
use RuntimeException;

/**
 * ADR 0031 / Р3 (rule-vocabulary plan): {@see ChannelShape} moved off
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
 * **What this guard does not see**, honestly: it inspects the *enclosing
 * method's* body, so a `ChannelShape` reference smuggled through a call to a
 * private helper the enclosing method invokes would not be caught — the same
 * class of gap this file's own model, {@see ChannelLevelAssemblyTopologyTest},
 * names for its level-suffix check. Verified by deliberately reintroducing the
 * violation in a scratch copy of a production rule during this guard's own
 * development: a reference in the *same* method fails loudly; one routed
 * through a second method does not.
 */
#[CoversClass(ChannelDeclaration::class)]
final class ChannelShapeNotDeclaredByChannelTopologyTest extends TestCase
{
    #[Test]
    public function noMethodThatBuildsAChannelDeclarationAlsoReferencesChannelShape(): void
    {
        $offenders = [];
        $channelBuildingCallCount = 0;

        foreach (self::productionFiles() as $file) {
            $ast = self::parse($file);
            $finder = new NodeFinder();

            /** @var list<StaticCall> $calls */
            $calls = $finder->find($ast, static fn(Node $node): bool => $node instanceof StaticCall
                && $node->class instanceof Name
                && $node->class->toString() === 'ChannelDeclaration'
                && $node->name instanceof Node\Identifier
                && \in_array($node->name->toString(), ['magnitude', 'occurrence'], true));

            foreach ($calls as $call) {
                $channelBuildingCallCount++;

                $method = self::enclosingMethod($ast, $call);

                if ($method === null) {
                    continue;
                }

                $shapeReferences = $finder->find(
                    $method,
                    static fn(Node $node): bool => $node instanceof ClassConstFetch
                        && $node->class instanceof Name
                        && $node->class->toString() === 'ChannelShape',
                );

                if ($shapeReferences !== []) {
                    $offenders[] = \sprintf(
                        '%s:%d in %s()',
                        self::relative($file),
                        $call->getStartLine(),
                        $method->name->toString(),
                    );
                }
            }
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

    /**
     * @return array<Node>
     */
    private static function parse(string $file): array
    {
        $contents = file_get_contents($file);

        if ($contents === false) {
            throw new RuntimeException(\sprintf('Could not read %s.', $file));
        }

        return (new ParserFactory())->createForHostVersion()->parse($contents) ?? [];
    }

    private static function sourceRoot(): string
    {
        return \dirname(__DIR__, 4) . '/src';
    }

    private static function relative(string $file): string
    {
        return 'src' . substr($file, \strlen(self::sourceRoot()));
    }
}
