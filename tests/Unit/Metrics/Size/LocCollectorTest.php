<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\Size;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Metrics\Size\LocCollector;
use Qualimetrix\Metrics\Size\LocVisitor;
use SplFileInfo;

#[CoversClass(LocCollector::class)]
#[CoversClass(LocVisitor::class)]
final class LocCollectorTest extends TestCase
{
    private LocCollector $collector;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->collector = new LocCollector();
        $this->tempDir = sys_get_temp_dir() . '/loc_collector_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Cleanup temp files
        $files = glob($this->tempDir . '/*');

        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    #[Test]
    public function itGetsName(): void
    {
        self::assertSame('loc', $this->collector->getName());
    }

    #[Test]
    public function itProvides(): void
    {
        self::assertSame(['loc', 'lloc', 'cloc', 'classLoc'], $this->collector->provides());
    }

    #[Test]
    public function itHandlesEmptyFile(): void
    {
        $metrics = $this->collectMetrics('');

        self::assertSame(0, $metrics->get('loc'));
        self::assertSame(0, $metrics->get('lloc'));
        self::assertSame(0, $metrics->get('cloc'));
    }

    #[Test]
    public function itCountsSingleLine(): void
    {
        $code = '<?php echo "hello";';

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('loc'));
        self::assertSame(1, $metrics->get('lloc'));
        self::assertSame(0, $metrics->get('cloc'));
    }

    #[Test]
    public function itCountsMultipleLines(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function foo(): void
    {
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // 10 lines total (heredoc doesn't add trailing newline)
        self::assertSame(10, $metrics->get('loc'));
        // 2 empty lines (lines 2, 4)
        // LLOC = 10 - 2 - 0 = 8
        self::assertSame(8, $metrics->get('lloc'));
        self::assertSame(0, $metrics->get('cloc'));
    }

    #[Test]
    public function itCountsSingleLineComment(): void
    {
        $code = <<<'PHP'
<?php

// This is a comment
class Test {}
PHP;

        $metrics = $this->collectMetrics($code);

        // 4 lines
        self::assertSame(4, $metrics->get('loc'));
        // 1 comment line
        self::assertSame(1, $metrics->get('cloc'));
        // 1 empty line (line 2)
        // LLOC = 4 - 1 - 1 = 2
        self::assertSame(2, $metrics->get('lloc'));
    }

    #[Test]
    public function itCountsMultiLineComment(): void
    {
        $code = <<<'PHP'
<?php

/*
 * Multi-line
 * comment
 */
class Test {}
PHP;

        $metrics = $this->collectMetrics($code);

        // 7 lines
        self::assertSame(7, $metrics->get('loc'));
        // 4 comment lines (lines 3-6)
        self::assertSame(4, $metrics->get('cloc'));
        // 1 empty line (line 2)
        // LLOC = 7 - 1 - 4 = 2
        self::assertSame(2, $metrics->get('lloc'));
    }

    #[Test]
    public function itCountsDocBlock(): void
    {
        $code = <<<'PHP'
<?php

/**
 * DocBlock comment
 */
class Test
{
    /**
     * Method doc
     * @return void
     */
    public function foo(): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        // 13 lines
        self::assertSame(13, $metrics->get('loc'));
        // Doc blocks: lines 3-5 (3 lines) + lines 8-11 (4 lines) = 7
        self::assertSame(7, $metrics->get('cloc'));
    }

    #[Test]
    public function itCountsHashComment(): void
    {
        $code = <<<'PHP'
<?php

# Hash style comment
class Test {}
PHP;

        $metrics = $this->collectMetrics($code);

        // 4 lines
        self::assertSame(4, $metrics->get('loc'));
        // 1 comment line
        self::assertSame(1, $metrics->get('cloc'));
    }

    #[Test]
    public function itHandlesInlineComment(): void
    {
        $code = <<<'PHP'
<?php

$x = 1; // inline comment
PHP;

        $metrics = $this->collectMetrics($code);

        // 3 lines
        self::assertSame(3, $metrics->get('loc'));
        // Line 3 has code AND a comment — it's NOT a pure comment line
        self::assertSame(0, $metrics->get('cloc'));
        // LLOC = 3 - 1 empty - 0 pure comments = 2
        self::assertSame(2, $metrics->get('lloc'));
    }

    #[Test]
    public function itDoesNotReduceLlocForInlineComment(): void
    {
        $code = <<<'PHP'
<?php

$a = 1; // inline comment
$b = 2; /* block inline */ $c = 3;
// pure comment line
$d = 4;
PHP;

        $metrics = $this->collectMetrics($code);

        // 6 lines
        self::assertSame(6, $metrics->get('loc'));
        // Only line 5 is a pure comment line (line 3 and 4 have code tokens too)
        self::assertSame(1, $metrics->get('cloc'));
        // LLOC = 6 - 1 empty - 1 pure comment = 4
        self::assertSame(4, $metrics->get('lloc'));
    }

    #[Test]
    public function itCountsMixedCommentStyles(): void
    {
        $code = <<<'PHP'
<?php

// Single line comment
/* Block comment */
# Hash comment
/**
 * DocBlock
 */
class Test {}
PHP;

        $metrics = $this->collectMetrics($code);

        // 9 lines
        self::assertSame(9, $metrics->get('loc'));
        // Comments: line 3 (//), line 4 (/**/), line 5 (#), lines 6-8 (docblock) = 6
        self::assertSame(6, $metrics->get('cloc'));
    }

    #[Test]
    public function itHandlesOnlyEmptyLines(): void
    {
        $code = "\n\n\n";

        $metrics = $this->collectMetrics($code);

        // 4 lines (3 newlines = 4 lines)
        self::assertSame(4, $metrics->get('loc'));
        // All empty
        self::assertSame(0, $metrics->get('lloc'));
        self::assertSame(0, $metrics->get('cloc'));
    }

    #[Test]
    public function itHandlesOnlyComments(): void
    {
        $code = <<<'PHP'
<?php
// comment 1
// comment 2
// comment 3
PHP;

        $metrics = $this->collectMetrics($code);

        // 4 lines
        self::assertSame(4, $metrics->get('loc'));
        // 3 comment lines
        self::assertSame(3, $metrics->get('cloc'));
        // LLOC = 4 - 0 - 3 = 1 (just the <?php line)
        self::assertSame(1, $metrics->get('lloc'));
    }

    #[Test]
    public function itCountsComplexFile(): void
    {
        $code = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Service;

/**
 * User service handles user operations.
 *
 * @author Test
 */
class UserService
{
    // Configuration
    private array $config;

    /**
     * Constructor.
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getUser(int $id): ?array
    {
        // TODO: implement
        return null;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // 30 lines total
        self::assertSame(30, $metrics->get('loc'));
        // Comments: lines 7-11 (5), line 14 (1), lines 17-19 (3), line 27 (1) = 10
        self::assertSame(10, $metrics->get('cloc'));
    }

    #[Test]
    public function itResetsState(): void
    {
        // LocCollector doesn't have state to reset, but we test it doesn't break
        $this->collector->reset();

        // Should still work after reset
        $code = '<?php class A {}';
        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('loc'));
    }

    #[Test]
    public function itHandlesWhitespaceOnlyLines(): void
    {
        $code = "<?php\n   \n\t\nclass Test {}";

        $metrics = $this->collectMetrics($code);

        // 4 lines
        self::assertSame(4, $metrics->get('loc'));
        // 2 empty lines (whitespace only)
        // LLOC = 4 - 2 = 2
        self::assertSame(2, $metrics->get('lloc'));
        self::assertSame(0, $metrics->get('cloc'));
    }

    #[Test]
    public function itHandlesTrailingNewline(): void
    {
        $code = "<?php\nclass Test {}\n";

        $metrics = $this->collectMetrics($code);

        // 3 lines (trailing newline creates empty line)
        self::assertSame(3, $metrics->get('loc'));
        // 1 empty line
        // LLOC = 3 - 1 = 2
        self::assertSame(2, $metrics->get('lloc'));
    }

    #[Test]
    public function itGetsMetricDefinitions(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(4, $definitions);

        $metricNames = array_map(fn($d) => $d->name, $definitions);
        self::assertContains('loc', $metricNames);
        self::assertContains('lloc', $metricNames);
        self::assertContains('cloc', $metricNames);
        self::assertContains('classLoc', $metricNames);

        // File-level metrics (loc, lloc, cloc)
        foreach (\array_slice($definitions, 0, 3) as $definition) {
            self::assertSame(SymbolLevel::File, $definition->collectedAt);

            $namespaceStrategies = $definition->getStrategiesForLevel(SymbolLevel::Namespace_);
            self::assertCount(2, $namespaceStrategies);
            self::assertContains(AggregationStrategy::Sum, $namespaceStrategies);
            self::assertContains(AggregationStrategy::Average, $namespaceStrategies);

            $projectStrategies = $definition->getStrategiesForLevel(SymbolLevel::Project);
            self::assertCount(2, $projectStrategies);
            self::assertContains(AggregationStrategy::Sum, $projectStrategies);
            self::assertContains(AggregationStrategy::Average, $projectStrategies);
        }

        // Class-level metric (classLoc)
        $classLocDef = $definitions[3];
        self::assertSame(SymbolLevel::Class_, $classLocDef->collectedAt);

        $namespaceStrategies = $classLocDef->getStrategiesForLevel(SymbolLevel::Namespace_);
        self::assertCount(4, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Sum, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Average, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Max, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Percentile95, $namespaceStrategies);
    }

    #[Test]
    public function itMeasuresClassLocForSingleClass(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Service;

class UserService
{
    public function create(): void
    {
        // logic
    }

    public function delete(): void
    {
        // logic
    }
}
PHP;
        $metrics = $this->collectMetrics($code);

        // Class spans lines 5-16 = 12 lines
        self::assertSame(12, $metrics->get('classLoc:App\Service\UserService'));
    }

    #[Test]
    public function itMeasuresClassLocForMultipleClasses(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class Foo
{
    public function bar(): void {}
}

class Baz
{
    public function qux(): void {}
}
PHP;
        $metrics = $this->collectMetrics($code);

        self::assertNotNull($metrics->get('classLoc:App\Foo'));
        self::assertNotNull($metrics->get('classLoc:App\Baz'));
    }

    #[Test]
    public function itSkipsAnonymousClassForClassLoc(): void
    {
        $code = <<<'PHP'
<?php
$obj = new class {
    public function foo(): void {}
};
PHP;
        $metrics = $this->collectMetrics($code);

        // No classLoc keys for anonymous classes
        self::assertNull($metrics->get('classLoc:'));
    }

    #[Test]
    public function itMeasuresClassLocWithoutNamespace(): void
    {
        $code = <<<'PHP'
<?php
class GlobalClass
{
    public function foo(): void {}
}
PHP;
        $metrics = $this->collectMetrics($code);

        self::assertNotNull($metrics->get('classLoc:GlobalClass'));
    }

    #[Test]
    public function itGetsClassesWithMetrics(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Model;

class Order
{
    public function process(): void {}
}
PHP;
        $this->collectMetrics($code);

        $classes = $this->collector->getClassesWithMetrics();

        self::assertCount(1, $classes);
        self::assertSame('App\Model', $classes[0]->namespace);
        self::assertSame('Order', $classes[0]->class);
        self::assertNotNull($classes[0]->metrics->get('classLoc'));
    }

    #[Test]
    public function itGetsClassesWithMetricsWithoutNamespace(): void
    {
        $code = <<<'PHP'
<?php
class Standalone
{
    public function run(): void {}
}
PHP;
        $this->collectMetrics($code);

        $classes = $this->collector->getClassesWithMetrics();

        self::assertCount(1, $classes);
        self::assertNull($classes[0]->namespace);
        self::assertSame('Standalone', $classes[0]->class);
    }

    private function collectMetrics(string $code): \Qualimetrix\Core\Metric\MetricBag
    {
        // Create actual temp file
        $filePath = $this->tempDir . '/test_' . uniqid() . '.php';
        file_put_contents($filePath, $code);

        $file = new SplFileInfo($filePath);

        // Parse to get AST (even though LOC doesn't use it)
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $code !== '' ? ($parser->parse($code) ?? []) : [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->collector->getVisitor());
        $traverser->traverse($ast);

        return $this->collector->collect($file, $ast);
    }
}
