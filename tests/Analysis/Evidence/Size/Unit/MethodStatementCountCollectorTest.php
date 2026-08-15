<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Size\Unit;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Evidence\Size\MethodStatementCountCollector;
use Qualimetrix\Analysis\Evidence\Size\MethodStatementCountVisitor;
use Qualimetrix\Core\Path\RelativePath;
use SplFileInfo;

#[CoversClass(MethodStatementCountCollector::class)]
#[CoversClass(MethodStatementCountVisitor::class)]
final class MethodStatementCountCollectorTest extends TestCase
{
    #[Test]
    public function itCountsStatementsIndependentlyOfFormatting(): void
    {
        $oneLine = '<?php class Example { public function run(bool $ready): int { if ($ready) { return 1; } return 0; } }';
        $multiLine = <<<'PHP'
<?php

class Example
{
    public function run(
        bool $ready,
    ): int {
        if (
            $ready
        ) {
            return
                1;
        }

        return
            0;
    }
}
PHP;

        self::assertSame(3, $this->methodCount($oneLine, 'Example::run'));
        self::assertSame(
            $this->methodCount($oneLine, 'Example::run'),
            $this->methodCount($multiLine, 'Example::run'),
        );
    }

    #[Test]
    public function itCountsExecutableAndControlStatementsButNotBlankLinesOrComments(): void
    {
        $code = <<<'PHP'
<?php

class Example
{
    public function run(array $items): void
    {
        // A comment is not a statement.

        foreach ($items as $item) {
            if ($item) {
                echo $item;
            } else {
                continue;
            }
        }
    }
}
PHP;

        // foreach + if + echo + else + continue
        self::assertSame(5, $this->methodCount($code, 'Example::run'));
    }

    #[Test]
    public function itReportsZeroForEmptyAndAbstractMethods(): void
    {
        $code = <<<'PHP'
<?php

abstract class Example
{
    abstract public function abstractMethod(): void;

    public function emptyMethod(): void
    {
        // Still empty.
    }
}
PHP;

        self::assertSame(0, $this->methodCount($code, 'Example::abstractMethod'));
        self::assertSame(0, $this->methodCount($code, 'Example::emptyMethod'));
    }

    #[Test]
    public function itIsolatesNestedCallablesAndAnonymousClasses(): void
    {
        $code = <<<'PHP'
<?php

class Example
{
    public function outer(): void
    {
        $closure = function (): void {
            echo 'closure';
        };
        $arrow = fn (): int => 1 + 1;
        $object = new class {
            public function nested(): void
            {
                echo 'anonymous';
            }
        };
    }
}
PHP;

        // The outer method owns only its three assignment expression statements.
        self::assertSame(3, $this->methodCount($code, 'Example::outer'));

        $methods = $this->collectMethods($code);
        $closureCounts = [];
        foreach ($methods as $method) {
            if ($method->kind === \Qualimetrix\Core\Symbol\CallableKind::AnonymousCallable) {
                $closureCounts[] = $method->metrics->get('methodStatementCount');
            }
        }

        // A block closure owns echo; an arrow function owns its expression body.
        self::assertSame([1, 1], $closureCounts);
        self::assertCount(4, $methods);
        $anonymousMethod = null;
        foreach ($methods as $method) {
            if ($method->declarationPath->logical->member === 'nested') {
                $anonymousMethod = $method;
                break;
            }
        }

        self::assertNotNull($anonymousMethod);
        self::assertNull($anonymousMethod->classAggregationOwner);
        self::assertNotNull($anonymousMethod->lexicalClassContext);
        self::assertStringStartsWith('{anonymous@', $anonymousMethod->lexicalClassContext->logical->type ?? '');
    }

    #[Test]
    public function itDeclaresMethodLevelAggregation(): void
    {
        $collector = new MethodStatementCountCollector();

        self::assertSame('method-statement-count', $collector->getName());
        self::assertSame(['methodStatementCount'], $collector->provides());

        $definitions = $collector->getMetricDefinitions();
        self::assertCount(1, $definitions);
        self::assertSame(SymbolLevel::Callable, $definitions[0]->collectedAt);
        self::assertSame(
            [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max],
            $definitions[0]->getStrategiesForLevel(SymbolLevel::Class_),
        );
    }

    private function methodCount(string $code, string $fqn): int
    {
        foreach ($this->collectMethods($code) as $method) {
            $methodFqn = $method->declarationPath->logical->getType() === \Qualimetrix\Core\Symbol\SymbolType::Method
                ? $method->declarationPath->logical->type . '::' . $method->declarationPath->logical->member
                : $method->declarationPath->logical->member;
            if ($methodFqn === $fqn) {
                return (int) ($method->metrics->get('methodStatementCount') ?? -1);
            }
        }

        self::fail("Method {$fqn} was not collected");
    }

    /**
     * @return list<\Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics>
     */
    private function collectMethods(string $code): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code);
        self::assertNotNull($ast);

        $collector = new MethodStatementCountCollector();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector->getVisitor());
        $traverser->traverse($ast);
        $collector->collect(new SplFileInfo('/tmp/example.php'), $ast);

        return $collector->getCallablesWithMetrics(RelativePath::fromString('src/example.php'));
    }
}
