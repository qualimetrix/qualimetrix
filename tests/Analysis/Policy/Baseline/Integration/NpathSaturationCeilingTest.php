<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Integration;

use DateTimeImmutable;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Complexity\NpathComplexityCollector;
use Qualimetrix\Analysis\Evidence\Complexity\NpathComplexityOptions;
use Qualimetrix\Analysis\Evidence\Complexity\NpathComplexityRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\Filter\BaselineCeilingStage;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;
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
        $recorded = self::emittedFinding(30);
        $current = self::emittedFinding(31);

        self::assertSame(1_000_000_000, $recorded->metricValue);
        self::assertSame(1_000_000_000, $current->metricValue);
        self::assertNotSame(self::source(30), self::source(31));
        self::assertSame($recorded->subject->toCanonical(), $current->subject->toCanonical());
        self::assertSame($recorded->occurrenceKey?->value, $current->occurrenceKey?->value);

        $baseline = new Baseline(
            generated: new DateTimeImmutable('2026-08-07T12:00:00+00:00'),
            scope: ['src'],
            entries: [new BaselineEntry(BaselineIdentity::forFinding($recorded), [1_000_000_000], 1)],
        );
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $declarations->declare(
            'complexity.npath.callable',
            ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Class_),
        );
        $stage = new BaselineCeilingStage($baseline, $declarations);

        self::assertSame([], $stage->apply([$current])->findings);
    }

    private static function emittedFinding(int $branchCount): \Qualimetrix\Analysis\Finding\Contract\Finding
    {
        $collector = new NpathComplexityCollector();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse(self::source($branchCount)) ?? [];
        $traverser = new NodeTraverser();
        $registrar = (new DeclarationRegistrarFactory())->createForFile();
        $traverser->addVisitor($registrar);
        $visitor = $collector->getVisitor();
        self::assertInstanceOf(DeclarationIndexAwareInterface::class, $visitor);
        $visitor->useDeclarationIndex($registrar->index());
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $collected = $collector->collect(new SplFileInfo(__FILE__), $ast);
        $npath = $collected->get('npath:App\\Subject::explode');
        self::assertIsInt($npath);

        $symbol = SymbolPath::forMethod('App', 'Subject', 'explode');
        $subject = MetricSubject::declaration(DeclarationPath::of($symbol, RelativePath::fromString('src/Subject.php'), DeclarationOrdinal::fromRank(0)));
        $metrics = (new MetricBag())->with(MetricName::COMPLEXITY_NPATH, $npath);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([
            new SymbolInfo($subject, RelativePath::fromString('src/Subject.php'), 8, CallableKind::Method),
        ]);
        $repository->method('getSubject')->willReturn($metrics);

        $findings = (new NpathComplexityRule(new NpathComplexityOptions()))
            ->analyzeLevel(SymbolLevel::Callable, new AnalysisContext($repository));

        self::assertCount(1, $findings);

        return $findings[0];
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
