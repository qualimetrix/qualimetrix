<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Integration;

use PhpParser\NodeTraverser;

use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellCollector;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellOptions;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellVisitor;
use Qualimetrix\Analysis\Evidence\CodeSmell\EvalRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\ExitRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\DataBag;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use SplFileInfo;

/**
 * Regression test: CodeSmellCollector stores per-occurrence entries via DataBag,
 * and AbstractCodeSmellRule creates per-occurrence findings with correct lines.
 *
 * Previously, the collector only stored counts and the rule created a single
 * finding at line 1. This was fixed to propagate line numbers from the visitor.
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
        $registrar = (new DeclarationRegistrarFactory())->createForFile();
        $traverser->addVisitor($registrar);
        $indexAwareVisitor = $collector->getVisitor();
        self::assertInstanceOf(DeclarationIndexAwareInterface::class, $indexAwareVisitor);
        $indexAwareVisitor->useDeclarationIndex($registrar->index());
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
        $registrar = (new DeclarationRegistrarFactory())->createForFile();
        $traverser->addVisitor($registrar);
        $visitor = $collector->getVisitor();
        self::assertInstanceOf(CodeSmellVisitor::class, $visitor);
        $visitor->useDeclarationIndex($registrar->index());
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

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
    public function ruleShouldCreatePerOccurrenceFindingsWithCorrectLines(): void
    {
        $rule = new EvalRule(new CodeSmellOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/example.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/example.php'), null);

        // Simulate MetricBag with entry data
        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.eval', ['subjectKind' => 'file', 'line' => 5])
            ->withEntry('codeSmell.eval', ['subjectKind' => 'file', 'line' => 16]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        // Rule should create per-occurrence findings at actual lines
        self::assertCount(2, $findings);
        self::assertSame(5, $findings[0]->location->line);
        self::assertSame(16, $findings[1]->location->line);
    }

    #[Test]
    public function ruleCreatesSingleFindingWithCorrectLineForSingleOccurrence(): void
    {
        $rule = new EvalRule(new CodeSmellOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/test.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/test.php'), null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.eval', ['subjectKind' => 'file', 'line' => 42]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(42, $findings[0]->location->line);
    }

    #[Test]
    public function collectorRecordsNoEntriesAndRuleEmitsNoFindingsWhenCodeIsClean(): void
    {
        // Fixture: clean PHP without any eval()/exit()/die() — the collector
        // must not record entries and the rule must not emit a spurious
        // finding at line 1 (the original bug surfaced exactly there).
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
        $registrar = (new DeclarationRegistrarFactory())->createForFile();
        $traverser->addVisitor($registrar);
        $indexAwareVisitor2 = $collector->getVisitor();
        self::assertInstanceOf(DeclarationIndexAwareInterface::class, $indexAwareVisitor2);
        $indexAwareVisitor2->useDeclarationIndex($registrar->index());
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
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metrics);

        $context = new AnalysisContext($repository);

        // The rule must produce no findings — not a single spurious one
        // at line 1, which was the original symptom.
        self::assertSame([], (new EvalRule(new CodeSmellOptions()))->analyze($context));
    }

    #[Test]
    public function ruleEmitsNoFindingsWhenMetricBagHasNoEntriesForSmellType(): void
    {
        // Direct rule-level check: with an empty MetricBag (no entries at
        // all for the smell key) the rule must return an empty list — not
        // a single placeholder finding at line 1.
        $rule = new ExitRule(new CodeSmellOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/empty.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/empty.php'), null);

        $metricBag = new MetricBag();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertSame([], $findings);
        self::assertSame(0, $metricBag->entryCount('codeSmell.exit'));
    }
}
