<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Rules;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Metrics\CodeSmell\CodeSmellCollector;
use Qualimetrix\Metrics\CodeSmell\CodeSmellVisitor;
use Qualimetrix\Rules\CodeSmell\CodeSmellOptions;
use Qualimetrix\Rules\CodeSmell\EvalRule;
use Qualimetrix\Rules\CodeSmell\ExitRule;
use SplFileInfo;

/**
 * Regression test: CodeSmellCollector stores per-occurrence entries via DataBag,
 * and AbstractCodeSmellRule creates per-occurrence violations with correct lines.
 *
 * Previously, the collector only stored counts and the rule created a single
 * violation at line 1. This was fixed to propagate line numbers from the visitor.
 */
#[CoversClass(CodeSmellCollector::class)]
#[CoversClass(CodeSmellVisitor::class)]
#[CoversClass(EvalRule::class)]
#[CoversClass(ExitRule::class)]
#[Group('regression')]
final class CodeSmellLinePrecisionTest extends TestCase
{
    #[Test]
    public function collectorShouldStorePerOccurrenceLineData(): void
    {
        // Fixture: eval() at two distinct lines
        $code = <<<'PHP'
<?php

// line 3

eval('$x = 1;');

// lines 7-14 are filler
//
//
//
//
//
//
//

eval('$y = 2;');
PHP;

        $collector = new CodeSmellCollector();

        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector->getVisitor());
        $traverser->traverse($ast);

        $metrics = $collector->collect(new SplFileInfo(__FILE__), $ast);

        // The count should be 2
        self::assertSame(2, $metrics->entryCount('codeSmell.eval'));

        // Collector now stores per-occurrence line data via entries
        $entries = $metrics->entries('codeSmell.eval');
        self::assertSame(
            5,
            $entries[0]['line'],
            'Collector should store line data for first eval() occurrence at line 5',
        );
        self::assertSame(
            16,
            $entries[1]['line'],
            'Collector should store line data for second eval() occurrence at line 16',
        );
    }

    #[Test]
    public function visitorCollectsLineDataAndCollectorPropagatesIt(): void
    {
        // This test documents that the visitor has line data
        // and the collector correctly propagates it.
        $code = <<<'PHP'
<?php

// line 3

eval('$x = 1;');

// lines 7-14 are filler
//
//
//
//
//
//
//

eval('$y = 2;');
PHP;

        $collector = new CodeSmellCollector();

        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $visitor = $collector->getVisitor();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        // The visitor has the line data
        \assert($visitor instanceof CodeSmellVisitor);
        $locations = $visitor->getLocationsByType('eval');

        self::assertCount(2, $locations);
        self::assertSame(5, $locations[0]->line, 'Visitor correctly records line 5 for first eval()');
        self::assertSame(16, $locations[1]->line, 'Visitor correctly records line 16 for second eval()');

        // The collector stores line data via DataBag entries
        $metrics = $collector->collect(new SplFileInfo(__FILE__), $ast);

        self::assertSame(2, $metrics->entryCount('codeSmell.eval'));

        $entries = $metrics->entries('codeSmell.eval');
        self::assertSame(5, $entries[0]['line']);
        self::assertSame(16, $entries[1]['line']);
    }

    #[Test]
    public function ruleShouldCreatePerOccurrenceViolationsWithCorrectLines(): void
    {
        $rule = new EvalRule(new CodeSmellOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/example.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/example.php'), null);

        // Simulate MetricBag with entry data
        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.eval', ['line' => 5])
            ->withEntry('codeSmell.eval', ['line' => 16]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        // Rule should create per-occurrence violations at actual lines
        self::assertCount(2, $violations);
        self::assertSame(5, $violations[0]->location->line);
        self::assertSame(16, $violations[1]->location->line);
    }

    #[Test]
    public function ruleCreatesSingleViolationWithCorrectLineForSingleOccurrence(): void
    {
        $rule = new EvalRule(new CodeSmellOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/test.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/test.php'), null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.eval', ['line' => 42]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(42, $violations[0]->location->line);
    }

    #[Test]
    public function collectorRecordsNoEntriesAndRuleEmitsNoViolationsWhenCodeIsClean(): void
    {
        // Fixture: clean PHP without any eval()/exit()/die() — the collector
        // must not record entries and the rule must not emit a spurious
        // violation at line 1 (the original bug surfaced exactly there).
        $code = <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

final class Greeter
{
    public function greet(string $name): string
    {
        return 'Hello, ' . $name;
    }
}
PHP;

        $collector = new CodeSmellCollector();

        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector->getVisitor());
        $traverser->traverse($ast);

        $metrics = $collector->collect(new SplFileInfo(__FILE__), $ast);

        // Collector should record zero entries for every smell type when
        // the code contains none of them.
        self::assertSame(0, $metrics->entryCount('codeSmell.eval'));
        self::assertSame([], $metrics->entries('codeSmell.eval'));
        self::assertSame(0, $metrics->entryCount('codeSmell.exit'));
        self::assertSame([], $metrics->entries('codeSmell.exit'));

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/clean.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/clean.php'), null);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metrics);

        $context = new AnalysisContext($repository);

        // The rule must produce no violations — not a single spurious one
        // at line 1, which was the original symptom.
        self::assertSame([], (new EvalRule(new CodeSmellOptions()))->analyze($context));
    }

    #[Test]
    public function ruleEmitsNoViolationsWhenMetricBagHasNoEntriesForSmellType(): void
    {
        // Direct rule-level check: with an empty MetricBag (no entries at
        // all for the smell key) the rule must return an empty list — not
        // a single placeholder violation at line 1.
        $rule = new ExitRule(new CodeSmellOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/empty.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/empty.php'), null);

        $metricBag = new MetricBag();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertSame([], $violations);
        self::assertSame(0, $metricBag->entryCount('codeSmell.exit'));
    }
}
