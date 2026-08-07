<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\BaselineCeiling;

use DateTimeImmutable;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\Baseline;
use Qualimetrix\Baseline\BaselineEntry;
use Qualimetrix\Baseline\BaselineIdentity;
use Qualimetrix\Baseline\Filter\BaselineCeilingStage;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleLevel;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\ChannelDeclaration;
use Qualimetrix\Metrics\Complexity\NpathComplexityCollector;
use Qualimetrix\Rules\Complexity\NpathComplexityOptions;
use Qualimetrix\Rules\Complexity\NpathComplexityRule;
use Qualimetrix\Tests\Support\Violation\StubChannelDeclarationRegistry;
use SplFileInfo;

/**
 * ADR 0017 at the collector, rule and ceiling seams.
 *
 * The two sources differ by one exponentially multiplying branch, yet both
 * emitted findings carry the visitor's hard saturation value. The ceiling can
 * only compare those emitted values, so it necessarily accepts the latter.
 */
final class NpathSaturationCeilingTest extends TestCase
{
    #[Test]
    public function itAcceptsTheSameSaturatedValueFromTwoIncreasinglyWorseSources(): void
    {
        $recorded = self::emittedViolation(30);
        $current = self::emittedViolation(31);

        self::assertSame(1_000_000_000, $recorded->metricValue);
        self::assertSame(1_000_000_000, $current->metricValue);
        self::assertNotSame(self::source(30), self::source(31));

        $baseline = new Baseline(
            generated: new DateTimeImmutable('2026-08-07T12:00:00+00:00'),
            scope: ['src'],
            entries: [new BaselineEntry(BaselineIdentity::forViolation($recorded), [1_000_000_000], 1)],
        );
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $declarations->declare(
            'complexity.npath#complexity.npath.method',
            ChannelDeclaration::magnitude(WorseDirection::Higher),
        );
        $stage = new BaselineCeilingStage($baseline, $declarations);

        self::assertSame([], $stage->apply([$current])->violations);
    }

    private static function emittedViolation(int $branchCount): \Qualimetrix\Core\Violation\Violation
    {
        $collector = new NpathComplexityCollector();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse(self::source($branchCount)) ?? [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector->getVisitor());
        $traverser->traverse($ast);

        $collected = $collector->collect(new SplFileInfo(__FILE__), $ast);
        $npath = $collected->get('npath:App\\Subject::explode');
        self::assertIsInt($npath);

        $symbol = SymbolPath::forMethod('App', 'Subject', 'explode');
        $metrics = (new MetricBag())->with(MetricName::COMPLEXITY_NPATH, $npath);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([
            new SymbolInfo($symbol, RelativePath::fromString('src/Subject.php'), 8),
        ]);
        $repository->method('get')->willReturn($metrics);

        $violations = (new NpathComplexityRule(new NpathComplexityOptions()))
            ->analyzeLevel(RuleLevel::Method, new AnalysisContext($repository));

        self::assertCount(1, $violations);

        return $violations[0];
    }

    private static function source(int $branchCount): string
    {
        $branches = '';
        for ($index = 0; $index < $branchCount; $index++) {
            $branches .= "if (\$flag{$index}) { \$value++; }\n";
        }

        return "<?php\n\nnamespace App;\n\nfinal class Subject\n{\n    public function explode(): void\n    {\n{$branches}    }\n}\n";
    }
}
