<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit\Extraction;

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
use Qualimetrix\Analysis\Policy\Inline\Extraction\DeclarationControlBindings;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * A class-scoped directive reaches the methods of *its* class declaration.
 *
 * The binding is a string comparison of a callable's lexical class context
 * against a class subject's canonical key, which makes it a consumer of the
 * two producers agreeing on the declaration number. On a file that declares
 * one class once, the comparison is between two zeroes and proves nothing.
 */
#[CoversClass(DeclarationControlBindings::class)]
final class DuplicateClassControlBindingTest extends TestCase
{
    #[Test]
    public function itBindsEachClassDeclarationToItsOwnMethods(): void
    {
        $source = <<<'PHP'
            <?php

            namespace App;

            if (\PHP_VERSION_ID > 80000) {
                class Greeter
                {
                    public function greet(): void {}
                }
            } else {
                class Greeter
                {
                    public function greet(): void {}
                }
            }
            PHP;
        $ast = (new ParserFactory())->createForHostVersion()->parse($source) ?? [];
        $finder = new NodeFinder();
        $classes = $finder->find($ast, static fn(Node $node): bool => $node instanceof Node\Stmt\Class_);
        $methods = $finder->find($ast, static fn(Node $node): bool => $node instanceof Node\Stmt\ClassMethod);
        self::assertCount(2, $classes);
        self::assertCount(2, $methods);

        $file = RelativePath::fromString('src/Dup.php');
        $classPaths = [
            DeclarationPath::of(SymbolPath::forClass('App', 'Greeter'), $file, DeclarationOrdinal::fromRank(0)),
            DeclarationPath::of(SymbolPath::forClass('App', 'Greeter'), $file, DeclarationOrdinal::fromRank(1)),
        ];
        $methodPaths = [
            DeclarationPath::of(SymbolPath::forMethod('App', 'Greeter', 'greet'), $file, DeclarationOrdinal::fromRank(0)),
            DeclarationPath::of(SymbolPath::forMethod('App', 'Greeter', 'greet'), $file, DeclarationOrdinal::fromRank(1)),
        ];
        $owner = new LogicalClassPath(SymbolPath::forClass('App', 'Greeter'));

        $bindings = DeclarationControlBindings::from(
            $ast,
            $file,
            [
                new CallableWithMetrics($methodPaths[0], $methods[0]->getStartFilePos(), CallableKind::Method, null, $classPaths[0], $owner, new MetricBag()),
                new CallableWithMetrics($methodPaths[1], $methods[1]->getStartFilePos(), CallableKind::Method, null, $classPaths[1], $owner, new MetricBag()),
            ],
            self::classMetrics(
                [$classPaths[0], $classes[0]->getStartFilePos()],
                [$classPaths[1], $classes[1]->getStartFilePos()],
            ),
        );

        self::assertSame(
            [
                'declaration:class:App\Greeter@src/Dup.php#1',
                'declaration:callable:App\Greeter::greet@src/Dup.php#1',
            ],
            array_map(
                static fn(array $binding): string => $binding['subject']->toCanonical(),
                $bindings->bindingsFor($classes[1]),
            ),
        );
        self::assertSame(
            [ControlScope::Class_, ControlScope::Class_],
            array_map(static fn(array $binding): ControlScope => $binding['scope'], $bindings->bindingsFor($classes[1])),
        );
    }

    /**
     * @param array{DeclarationPath, int} ...$declarations
     *
     * @return array<string, array{subject: MetricSubject, metrics: MetricBag, line: int, start: int}>
     */
    private static function classMetrics(array ...$declarations): array
    {
        $metrics = [];
        foreach ($declarations as [$declaration, $start]) {
            $class = new ClassWithMetrics($declaration, $start, 1, new MetricBag());
            $metrics[$class->subject->toCanonical()] = [
                'subject' => $class->subject,
                'metrics' => $class->metrics,
                'line' => $class->line,
                'start' => $class->startFilePos,
            ];
        }

        return $metrics;
    }
}
