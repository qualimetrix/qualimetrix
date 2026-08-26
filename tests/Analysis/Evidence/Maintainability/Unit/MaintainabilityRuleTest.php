<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Maintainability\Unit;

use InvalidArgumentException;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Maintainability\HalsteadCollector;
use Qualimetrix\Analysis\Evidence\Maintainability\MaintainabilityIndexCollector;
use Qualimetrix\Analysis\Evidence\Maintainability\MaintainabilityOptions;
use Qualimetrix\Analysis\Evidence\Maintainability\MaintainabilityRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Size\MethodStatementCountCollector;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(MaintainabilityRule::class)]
#[CoversClass(MaintainabilityOptions::class)]
final class MaintainabilityRuleTest extends TestCase
{
    #[Test]
    public function itGetsName(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions());

        self::assertSame('maintainability.index', $rule->getName());
    }

    #[Test]
    public function itGetsDescription(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions());

        self::assertSame(
            'Checks Maintainability Index (lower values indicate harder to maintain code)',
            $rule->getDescription(),
        );
    }

    #[Test]
    public function itRequires(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions());

        self::assertSame(['mi', 'methodStatementCount'], $rule->requires());
    }

    #[Test]
    public function itGetsOptionsClass(): void
    {
        self::assertSame(
            MaintainabilityOptions::class,
            MaintainabilityRule::getOptionsClass(),
        );
    }

    #[Test]
    public function itThrowsExceptionForWrongOptionsType(): void
    {
        $wrongOptions = self::createStub(\Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface::class);

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Expected');

        new MaintainabilityRule($wrongOptions);
    }

    #[Test]
    public function itAnalyzeReturnsEmptyWhenDisabled(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itAnalyzeReturnsEmptyWhenNoMethods(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions());

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')
            ->willReturn([]);
        $repository->method('allDeclarations')
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itGeneratesWarningForBorderlineMi(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        // MI of 30 is below warning threshold (40) but above error (20)
        $metricBag = (new MetricBag())
            ->with('mi', 30.0)
            ->with('methodStatementCount', 15);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')
            ->willReturn([$methodInfo]);
        $repository->method('allDeclarations')
            ->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertStringContainsString('Maintainability Index is 30.0', $findings[0]->message);
        self::assertSame(30.0, $findings[0]->metricValue);
        self::assertSame('maintainability.index', $findings[0]->ruleName);
    }

    #[Test]
    public function itGeneratesErrorForVeryLowMi(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        // MI of 15 is below error threshold (20) - very poor maintainability
        $metricBag = (new MetricBag())
            ->with('mi', 15.0)
            ->with('methodStatementCount', 20);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')
            ->willReturn([$methodInfo]);
        $repository->method('allDeclarations')
            ->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertSame(15.0, $findings[0]->metricValue);
    }

    #[Test]
    public function itProducesNoFindingForHighMi(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'simple');
        $methodInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        // MI of 90 is good (above warning threshold 65)
        $metricBag = (new MetricBag())
            ->with('mi', 90.0)
            ->with('methodStatementCount', 12);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')
            ->willReturn([$methodInfo]);
        $repository->method('allDeclarations')
            ->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(0, $findings);
    }

    #[Test]
    public function itRoundsMetricValueToOneDecimal(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        $metricBag = (new MetricBag())
            ->with('mi', 25.67)
            ->with('methodStatementCount', 15);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')
            ->willReturn([$methodInfo]);
        $repository->method('allDeclarations')
            ->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(25.7, $findings[0]->metricValue);
        self::assertIsFloat($findings[0]->metricValue);
    }

    #[Test]
    public function itSkipsMethodWithoutMiMetric(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'method');
        $methodInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        // No 'mi' metric
        $metricBag = new MetricBag();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')
            ->willReturn([$methodInfo]);
        $repository->method('allDeclarations')
            ->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(0, $findings);
    }

    // Options tests

    #[Test]
    public function itLoadsOptionsFromArray(): void
    {
        $options = MaintainabilityOptions::fromArray([
            'enabled' => false,
            'warning' => 70.0,
            'error' => 55.0,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(70.0, $options->warning);
        self::assertSame(55.0, $options->error);
    }

    #[Test]
    public function itDisablesWhenLoadedFromEmptyArray(): void
    {
        $options = MaintainabilityOptions::fromArray([]);

        self::assertFalse($options->enabled);
    }

    #[Test]
    public function itHasOptionsDefaults(): void
    {
        $options = new MaintainabilityOptions();

        self::assertTrue($options->enabled);
        self::assertSame(40.0, $options->warning);
        self::assertSame(20.0, $options->error);
    }

    #[Test]
    #[DataProvider('thresholdDataProvider')]
    public function itRespectsThresholdBoundaries(
        float $mi,
        float $warning,
        float $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new MaintainabilityRule(
            new MaintainabilityOptions(
                warning: $warning,
                error: $error,
            ),
        );

        $symbolPath = SymbolPath::forMethod('App', 'Test', 'method');
        $methodInfo = self::subjectInfo($symbolPath, RelativePath::fromString('test.php'), 1);

        $metricBag = (new MetricBag())
            ->with('mi', $mi)
            ->with('methodStatementCount', 15);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')
            ->willReturn([$methodInfo]);
        $repository->method('allDeclarations')
            ->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        if ($expectedSeverity === null) {
            self::assertCount(0, $findings);
        } else {
            self::assertCount(1, $findings);
            self::assertSame($expectedSeverity, $findings[0]->severity);
        }
    }

    /**
     * @return iterable<string, array{float, float, float, ?Severity}>
     */
    public static function thresholdDataProvider(): iterable
    {
        // Note: Lower MI is worse, so "above" warning means good, "below" means bad
        yield 'above warning threshold (good)' => [70.0, 65.0, 50.0, null];
        yield 'at warning threshold (good)' => [65.0, 65.0, 50.0, null];
        yield 'below warning, above error' => [60.0, 65.0, 50.0, Severity::Warning];
        yield 'at error threshold' => [50.0, 65.0, 50.0, Severity::Warning];
        yield 'below error threshold' => [40.0, 65.0, 50.0, Severity::Error];
    }

    #[Test]
    public function itGetsCliAliases(): void
    {
        $aliases = CliAliasReader::read(MaintainabilityRule::class);

        self::assertArrayHasKey('mi-warning', $aliases);
        self::assertArrayHasKey('mi-error', $aliases);
        self::assertArrayHasKey('mi-exclude-tests', $aliases);
        self::assertArrayHasKey('mi-min-statements', $aliases);
        self::assertSame('warning', $aliases['mi-warning']);
        self::assertSame('error', $aliases['mi-error']);
        self::assertSame('excludeTests', $aliases['mi-exclude-tests']);
        self::assertSame('minStatements', $aliases['mi-min-statements']);
    }

    #[Test]
    public function itLoadsOptionsFromArrayWithExcludeTests(): void
    {
        $options = MaintainabilityOptions::fromArray([
            'exclude_tests' => false,
            'min_statements' => 20,
        ]);

        self::assertFalse($options->excludeTests);
        self::assertSame(20, $options->minStatements);
    }

    #[Test]
    public function itLoadsOptionsFromArrayWithCamelCase(): void
    {
        $options = MaintainabilityOptions::fromArray([
            'excludeTests' => false,
            'minStatements' => 15,
        ]);

        self::assertFalse($options->excludeTests);
        self::assertSame(15, $options->minStatements);
    }

    #[Test]
    public function itSkipsHelperUnderTopLevelTestsDir(): void
    {
        // Regression: project-relative paths start with 'tests/', no leading '/'.
        // A helper file (no Test.php suffix) under tests/ must still be recognized.
        $rule = new MaintainabilityRule(new MaintainabilityOptions(excludeTests: true));

        $symbolPath = SymbolPath::forMethod('App\Tests\Helpers', 'AssertionHelper', 'assertThing');
        $methodInfo = self::subjectInfo($symbolPath, RelativePath::fromString('tests/Helpers/AssertionHelper.php'), 10);

        $metricBag = (new MetricBag())
            ->with('mi', 15.0)
            ->with('methodStatementCount', 20);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')
            ->willReturn([$methodInfo]);
        $repository->method('allDeclarations')
            ->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(0, $findings);
    }

    #[Test]
    public function itSkipsTestFilesWhenExcluded(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions(excludeTests: true));

        $symbolPath = SymbolPath::forMethod('App\Tests', 'UserServiceTest', 'testCalculate');
        $methodInfo = self::subjectInfo($symbolPath, RelativePath::fromString('tests/Service/UserServiceTest.php'), 10);

        // Low MI that would normally trigger a finding
        $metricBag = (new MetricBag())
            ->with('mi', 15.0)
            ->with('methodStatementCount', 20);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')
            ->willReturn([$methodInfo]);
        $repository->method('allDeclarations')
            ->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        // Should be skipped because it's a test file
        self::assertCount(0, $findings);
    }

    #[Test]
    public function itIncludesTestFilesWhenNotExcluded(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions(excludeTests: false));

        $symbolPath = SymbolPath::forMethod('App\Tests', 'UserServiceTest', 'testCalculate');
        $methodInfo = self::subjectInfo($symbolPath, RelativePath::fromString('tests/Service/UserServiceTest.php'), 10);

        // Low MI that would trigger a finding
        $metricBag = (new MetricBag())
            ->with('mi', 15.0)
            ->with('methodStatementCount', 20);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')
            ->willReturn([$methodInfo]);
        $repository->method('allDeclarations')
            ->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        // Should NOT be skipped
        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
    }

    #[Test]
    public function itSkipsMethodsWithTooFewStatements(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions(minStatements: 15));

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        // Low MI and too few statements.
        $metricBag = (new MetricBag())
            ->with('mi', 15.0)
            ->with('methodStatementCount', 10);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')
            ->willReturn([$methodInfo]);
        $repository->method('allDeclarations')
            ->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        // Should be skipped because methodStatementCount < minStatements.
        self::assertCount(0, $findings);
    }

    #[Test]
    public function itIncludesMethodsWithSufficientStatements(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions(minStatements: 15));

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        // Low MI and enough statements.
        $metricBag = (new MetricBag())
            ->with('mi', 15.0)
            ->with('methodStatementCount', 20);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')
            ->willReturn([$methodInfo]);
        $repository->method('allDeclarations')
            ->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        // Should NOT be skipped
        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
    }

    #[Test]
    public function itKeepsMiAndEligibilityStableAcrossFormatting(): void
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

        $oneLineMetrics = $this->calculateMaintainabilityMetrics($oneLine);
        $multiLineMetrics = $this->calculateMaintainabilityMetrics($multiLine);

        self::assertSame(
            $oneLineMetrics->get('methodStatementCount'),
            $multiLineMetrics->get('methodStatementCount'),
        );
        self::assertSame($oneLineMetrics->get('mi'), $multiLineMetrics->get('mi'));

        $rule = new MaintainabilityRule(new MaintainabilityOptions(
            warning: 101.0,
            error: 0.0,
            excludeTests: false,
            minStatements: 3,
        ));
        $symbol = SymbolPath::forMethod('', 'Example', 'run');
        $method = self::subjectInfo($symbol, RelativePath::fromString('src/Example.php'), 1);
        $eligibility = [];

        foreach ([$oneLineMetrics, $multiLineMetrics] as $metrics) {
            $repository = self::createStub(MetricRepositoryInterface::class);
            $repository->method('allCallables')->willReturn([$method]);
            $repository->method('getSubject')->willReturn($metrics);
            $eligibility[] = \count($rule->analyze(new AnalysisContext($repository)));
        }

        self::assertSame([1, 1], $eligibility);
    }

    #[Test]
    public function itProjectsDuplicateLogicalCallableScoresToIndependentExactDeclarations(): void
    {
        $method = SymbolPath::forMethod('App\\Service', 'Twin', 'run');
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([
            self::subjectInfo($method, RelativePath::fromString('src/A.php'), 100),
            self::subjectInfo($method, RelativePath::fromString('src/B.php'), 200),
        ]);
        $repository->method('getSubject')->willReturn(
            (new MetricBag())->with('mi', 30.0)->with('methodStatementCount', 15),
        );

        $findings = (new MaintainabilityRule(new MaintainabilityOptions()))
            ->analyze(new AnalysisContext($repository));

        self::assertCount(2, $findings);
        $subjects = array_map(static fn($finding): string => $finding->subject->toCanonical(), $findings);
        sort($subjects);
        self::assertSame([
            'declaration:callable:App\\Service\\Twin::run@src/A.php',
            'declaration:callable:App\\Service\\Twin::run@src/B.php',
        ], $subjects);
    }

    private function calculateMaintainabilityMetrics(string $code): MetricBag
    {
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        self::assertNotNull($ast);

        $halstead = new HalsteadCollector();
        $statements = new MethodStatementCountCollector();
        $traverser = new NodeTraverser();
        $registrar = (new DeclarationRegistrarFactory())->createForFile();
        $traverser->addVisitor($registrar);
        $indexAwareVisitor = $halstead->getVisitor();
        self::assertInstanceOf(DeclarationIndexAwareInterface::class, $indexAwareVisitor);
        $indexAwareVisitor->useDeclarationIndex($registrar->index());
        $indexAwareVisitor2 = $statements->getVisitor();
        self::assertInstanceOf(DeclarationIndexAwareInterface::class, $indexAwareVisitor2);
        $indexAwareVisitor2->useDeclarationIndex($registrar->index());
        $traverser->addVisitor($halstead->getVisitor());
        $traverser->addVisitor($statements->getVisitor());
        $traverser->traverse($ast);

        $halsteadMetrics = $halstead->getCallablesWithMetrics(RelativePath::fromString('src/Example.php'))[0]->metrics;
        $statementMetrics = $statements->getCallablesWithMetrics(RelativePath::fromString('src/Example.php'))[0]->metrics;
        $source = $halsteadMetrics
            ->merge($statementMetrics)
            ->with('ccn', 2);

        return $source->merge((new MaintainabilityIndexCollector())->calculate($source));
    }
    private static function subjectInfo(\Qualimetrix\Core\Symbol\SymbolPath $symbolPath, ?\Qualimetrix\Core\Path\RelativePath $file, ?int $line): \Qualimetrix\Core\Symbol\SymbolInfo
    {
        $type = $symbolPath->getType();
        if (\in_array($type, [\Qualimetrix\Core\Symbol\SymbolType::File, \Qualimetrix\Core\Symbol\SymbolType::Namespace_, \Qualimetrix\Core\Symbol\SymbolType::Project], true)) {
            return new \Qualimetrix\Core\Symbol\SymbolInfo(\Qualimetrix\Core\Symbol\MetricSubject::aggregate($symbolPath), $file, $line);
        }

        \assert($file !== null);
        $kind = $type === \Qualimetrix\Core\Symbol\SymbolType::Class_ ? null : ($type === \Qualimetrix\Core\Symbol\SymbolType::Function_ ? \Qualimetrix\Core\Symbol\CallableKind::Function : \Qualimetrix\Core\Symbol\CallableKind::Method);

        return new \Qualimetrix\Core\Symbol\SymbolInfo(
            \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of($symbolPath, $file, \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
            $file,
            $line,
            $kind,
        );
    }
}
