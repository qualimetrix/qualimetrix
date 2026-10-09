<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Cohesion\Unit;

use InvalidArgumentException;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomExcludedMethods;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomOptions;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

#[CoversClass(LcomRule::class)]
#[CoversClass(LcomExcludedMethods::class)]
#[CoversClass(LcomOptions::class)]
final class LcomRuleTest extends TestCase
{
    #[Test]
    public function itGetsName(): void
    {
        $rule = new LcomRule(new LcomOptions());

        self::assertSame('cohesion.lcom', $rule->getName());
    }

    #[Test]
    public function itGetsDescription(): void
    {
        $rule = new LcomRule(new LcomOptions());

        self::assertSame(
            'Checks Lack of Cohesion of Methods (high values indicate class should be split)',
            $rule::getDescription(),
        );
    }

    #[Test]
    public function itGetsOptionsClass(): void
    {
        self::assertSame(
            LcomOptions::class,
            LcomRule::getOptionsClass(),
        );
    }

    #[Test]
    public function itThrowsExceptionForWrongOptionsType(): void
    {
        $wrongOptions = self::createStub(\Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface::class);

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Expected');

        new LcomRule($wrongOptions);
    }

    #[Test]
    public function itReturnsEmptyWhenDisabled(): void
    {
        $rule = new LcomRule(new LcomOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('allClassDeclarations');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itReturnsEmptyWhenNoClasses(): void
    {
        $rule = new LcomRule(new LcomOptions());

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itGeneratesWarning(): void
    {
        $rule = new LcomRule(new LcomOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'GodClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/GodClass.php'), 10);

        // LCOM of 4 is above warning threshold (3) but below error (5)
        $metricBag = (new MetricBag())
            ->with('cohesion.lcom', 4)
            ->with('size.method-count', 5)
            ->with('design.is-readonly', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertStringContainsString('LCOM (Lack of Cohesion) is 4', $findings[0]->message);
        self::assertStringContainsString('exceeds threshold of 3', $findings[0]->message);
        self::assertStringContainsString('Class could be split into 4 cohesive parts', $findings[0]->message);
        self::assertSame(4, $findings[0]->metricValue);
        self::assertSame('cohesion.lcom', $findings[0]->ruleName);
    }

    #[Test]
    public function itGeneratesError(): void
    {
        $rule = new LcomRule(new LcomOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'VeryLargeClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/VeryLargeClass.php'), 10);

        // LCOM of 5 is above error threshold (4)
        $metricBag = (new MetricBag())
            ->with('cohesion.lcom', 5)
            ->with('size.method-count', 10)
            ->with('design.is-readonly', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertSame(5, $findings[0]->metricValue);
    }

    #[Test]
    public function itProducesNoFindingForCohesiveClass(): void
    {
        $rule = new LcomRule(new LcomOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'CohesiveClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/CohesiveClass.php'), 10);

        // LCOM of 1 means perfectly cohesive (below warning threshold 2)
        $metricBag = (new MetricBag())->with('cohesion.lcom', 1);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(0, $findings);
    }

    #[Test]
    public function itSkipsClassWithoutLcomMetric(): void
    {
        $rule = new LcomRule(new LcomOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'SomeClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/SomeClass.php'), 10);

        // No 'cohesion.lcom' metric
        $metricBag = new MetricBag();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(0, $findings);
    }

    #[Test]
    public function itPreservesReadonlyMinMethodAndExactOverrideEligibility(): void
    {
        $classInfo = self::subjectInfo(SymbolPath::forClass('App', 'Candidate'), RelativePath::fromString('src/Candidate.php'), 10);
        $subject = $classInfo->subject;
        self::assertNotNull($subject);
        $contextFor = static function (MetricBag $bag, array $overrides = []) use ($classInfo): AnalysisContext {
            $repository = self::createStub(MetricRepositoryInterface::class);
            $repository->method('allClassDeclarations')->willReturn([$classInfo]);
            $repository->method('getSubject')->willReturn($bag);

            return new AnalysisContext($repository, thresholdOverrides: $overrides);
        };
        $rule = new LcomRule(new LcomOptions(warning: 3, error: 5, excludeReadonly: true, minMethods: 3));

        self::assertSame([], $rule->analyze($contextFor(
            (new MetricBag())->with('cohesion.lcom', 4)->with('size.method-count', 3)->with('design.is-readonly', 1),
        )));
        self::assertSame([], $rule->analyze($contextFor(
            (new MetricBag())->with('cohesion.lcom', 4)->with('size.method-count', 2)->with('design.is-readonly', 0),
        )));

        $eligible = (new MetricBag())->with('cohesion.lcom', 3)->with('size.method-count', 3)->with('design.is-readonly', 0);
        $findings = $rule->analyze($contextFor($eligible));
        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame($subject->toCanonical(), $findings[0]->subject->toCanonical());

        self::assertSame([], $rule->analyze($contextFor($eligible, [
            'src/Candidate.php' => [new ThresholdOverride('cohesion.lcom', 4, 6, 1, $subject, ControlScope::Class_, 100)],
        ])));
    }

    // Options tests

    #[Test]
    public function itLoadsOptionsFromArray(): void
    {
        $options = LcomOptions::fromResolved(ResolvedOptionsFixture::values(LcomOptions::class, [
            'enabled' => false,
            'warning' => 3,
            'error' => 5,
        ]));

        self::assertFalse($options->enabled);
        self::assertSame(3, $options->warning);
        self::assertSame(5, $options->error);
    }

    #[Test]
    public function itUsesConstructorDefaultsForAnEmptyBodyAndHonoursExplicitDisablement(): void
    {
        self::assertEquals(new LcomOptions(), LcomOptions::fromResolved(ResolvedOptionsFixture::values(LcomOptions::class, [])));
        self::assertFalse(LcomOptions::fromResolved(ResolvedOptionsFixture::values(LcomOptions::class, ['enabled' => false]))->isEnabled());
    }

    #[Test]
    public function itHasCorrectOptionDefaults(): void
    {
        $options = new LcomOptions();

        self::assertTrue($options->enabled);
        self::assertSame(3, $options->warning);
        self::assertSame(5, $options->error);
    }

    #[Test]
    #[DataProvider('thresholdDataProvider')]
    public function itRespectsBoundaryThresholds(
        int $lcom,
        int $warning,
        int $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new LcomRule(
            new LcomOptions(
                warning: $warning,
                error: $error,
            ),
        );

        $symbolPath = SymbolPath::forClass('App', 'TestClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('test.php'), 1);

        $metricBag = (new MetricBag())
            ->with('cohesion.lcom', $lcom)
            ->with('size.method-count', 5)
            ->with('design.is-readonly', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        if ($expectedSeverity === null) {
            self::assertCount(0, $findings);
        } else {
            self::assertCount(1, $findings);
            self::assertSame($expectedSeverity, $findings[0]->severity);
            $selectedThreshold = $expectedSeverity === Severity::Error ? $error : $warning;
            self::assertStringContainsString(($lcom === $selectedThreshold ? 'reaches' : 'exceeds') . ' threshold of', $findings[0]->message);
        }
    }

    /**
     * @return iterable<string, array{int, int, int, ?Severity}>
     */
    public static function thresholdDataProvider(): iterable
    {
        // Higher LCOM is worse
        yield 'below warning threshold' => [1, 2, 4, null];
        yield 'at warning threshold' => [2, 2, 4, Severity::Warning];
        yield 'above warning, below error' => [3, 2, 4, Severity::Warning];
        yield 'at error threshold' => [4, 2, 4, Severity::Error];
        yield 'above error threshold' => [6, 2, 4, Severity::Error];
    }

    #[Test]
    public function itGetsCliAliases(): void
    {
        $aliases = CliAliasReader::read(LcomRule::class);

        self::assertArrayHasKey('lcom-warning', $aliases);
        self::assertArrayHasKey('lcom-error', $aliases);
        self::assertArrayHasKey('lcom-exclude-methods', $aliases);
        self::assertSame('warning', $aliases['lcom-warning']);
        self::assertSame('error', $aliases['lcom-error']);
        self::assertSame('excludeMethods', $aliases['lcom-exclude-methods']);
    }

    #[Test]
    public function itLoadsExcludeMethodsFromArray(): void
    {
        $options = LcomOptions::fromResolved(ResolvedOptionsFixture::values(LcomOptions::class, [
            'exclude_methods' => ['getName', 'getDescription'],
        ]));

        self::assertSame(['getName', 'getDescription'], $options->excludeMethods);
    }

    #[Test]
    public function itLoadsExcludeMethodsFromArraySnakeCase(): void
    {
        $options = LcomOptions::fromResolved(ResolvedOptionsFixture::values(LcomOptions::class, [
            'excludeMethods' => ['getName', 'getDescription'],
        ]));

        self::assertSame(['getName', 'getDescription'], $options->excludeMethods);
    }

    #[Test]
    public function itRefusesScalarExcludeMethods(): void
    {
        try {
            LcomOptions::fromResolved(ResolvedOptionsFixture::values(LcomOptions::class, [
                'exclude_methods' => 'getName',
            ]));
            self::fail('A scalar method exclusion must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame(
                '"rules.fixture.exclude_methods" in configuration file "/project/qmx.yaml" must be a list, got string.',
                $refusal->getMessage(),
            );
        }
    }

    #[Test]
    public function itSetsExcludeMethodsToNullWhenNotProvided(): void
    {
        $options = LcomOptions::fromResolved(ResolvedOptionsFixture::values(LcomOptions::class, [
            'warning' => 3,
            'error' => 5,
        ]));

        self::assertNull($options->excludeMethods);
    }

    #[Test]
    public function itPreservesExcludeMethodsOnOverride(): void
    {
        $options = LcomOptions::fromResolved(ResolvedOptionsFixture::values(LcomOptions::class, [
            'exclude_methods' => ['getName', 'getDescription'],
        ]));

        $overridden = $options->withOverride(warning: 4, error: 6);

        self::assertSame(4, $overridden->warning);
        self::assertSame(6, $overridden->error);
        self::assertSame(['getName', 'getDescription'], $overridden->excludeMethods);
    }
    #[Test]
    public function itProjectsDuplicateLogicalClassScoresToIndependentExactDeclarations(): void
    {
        $class = SymbolPath::forClass('App\\Service', 'Twin');
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([
            self::subjectInfo($class, RelativePath::fromString('src/A.php'), 100),
            self::subjectInfo($class, RelativePath::fromString('src/B.php'), 200),
        ]);
        $repository->method('getSubject')->willReturn(
            (new MetricBag())->with('cohesion.lcom', 4)->with('size.method-count', 5)->with('design.is-readonly', 0),
        );

        $findings = (new LcomRule(new LcomOptions()))
            ->analyze(new AnalysisContext($repository));

        self::assertCount(2, $findings);
        $subjects = array_map(static fn($finding): string => $finding->subject->toCanonical(), $findings);
        sort($subjects);
        self::assertSame([
            'declaration:class:App\\Service\\Twin@src/A.php',
            'declaration:class:App\\Service\\Twin@src/B.php',
        ], $subjects);
    }

    #[Test]
    public function itReportsDistinctUnmatchedExclusionsOnlyForWholeProjectMethodFacts(): void
    {
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([]);
        $method = self::subjectInfo(SymbolPath::forMethod('App', 'Worker', 'bridge'), RelativePath::fromString('src/Worker.php'), 10);
        $function = self::subjectInfo(SymbolPath::forGlobalFunction('App', 'helper'), RelativePath::fromString('src/functions.php'), 10);
        $hookSubject = self::subjectInfo(SymbolPath::forMethod('App', 'Worker', 'hookOnly'), RelativePath::fromString('src/Worker.php'), 20);
        $hook = new \Qualimetrix\Core\Symbol\SymbolInfo(
            $hookSubject->subject ?? throw new InvalidArgumentException('The hook fixture requires an exact subject.'),
            $hookSubject->file,
            $hookSubject->line,
            \Qualimetrix\Core\Symbol\CallableKind::PropertyHook,
        );
        $repository->method('allCallables')->willReturn([$method, $function, $hook]);
        $rule = new LcomRule(new LcomOptions(excludeMethods: ['BRIDGE', 'brigde', 'BRIGDE', 'helper', 'hookOnly']));

        $findings = $rule->analyze(new AnalysisContext($repository));
        self::assertCount(3, $findings);
        self::assertSame('cohesion.unmatched-exclude-method', $findings[0]->ruleName);
        self::assertSame('cohesion.unmatched-exclude-method', $findings[0]->code);
        self::assertSame('The exclude_methods name "brigde" matched no method declared in this project.', $findings[0]->message);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame(1, $findings[0]->metricValue);
        self::assertSame('project:', $findings[0]->subject->toCanonical());
        self::assertSame(SymbolPath::forProject()->toCanonical(), $findings[0]->symbolPath->toCanonical());
        self::assertTrue($findings[0]->location->isNone());
        self::assertNotNull($findings[0]->occurrenceKey);
        self::assertNotNull($findings[1]->occurrenceKey);
        self::assertSame(
            \Qualimetrix\Analysis\Finding\Contract\OccurrenceKey::semantic('unmatched-exclude-method', ['method' => 'brigde'])->value,
            $findings[0]->occurrenceKey->value,
        );
        self::assertStringContainsString('"helper"', $findings[1]->message);
        self::assertStringContainsString('"hookOnly"', $findings[2]->message);
        self::assertNotSame($findings[0]->occurrenceKey->value, $findings[1]->occurrenceKey->value);
        $uppercase = (new LcomRule(new LcomOptions(excludeMethods: ['BRIGDE'])))
            ->analyze(new AnalysisContext($repository));
        self::assertNotNull($uppercase[0]->occurrenceKey);
        self::assertSame($findings[0]->occurrenceKey->value, $uppercase[0]->occurrenceKey->value);
        self::assertStringContainsString('"BRIGDE"', $uppercase[0]->message);
        self::assertSame([], $rule->analyze(new AnalysisContext($repository, projectScope: new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement([\Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeDoor::Paths]))));
        self::assertSame([], (new LcomRule(new LcomOptions(enabled: false, excludeMethods: ['missing'])))
            ->analyze(new AnalysisContext($repository)));

        $declaration = LcomRule::channelDeclarations()['cohesion.unmatched-exclude-method'];
        self::assertSame([\Qualimetrix\Core\Symbol\SymbolLevel::Project], $declaration->levels);
        self::assertSame(\Qualimetrix\Core\Observation\WorseDirection::Higher, $declaration->direction);
        self::assertNull($declaration->judges);
        self::assertFalse($declaration->usesProducerWarningBoundary);
        self::assertTrue(LcomRule::channelDeclarations()[LcomRule::NAME]->usesProducerWarningBoundary);
    }

    #[Test]
    public function itAccountsEachNormalizedMethodSelectorIncludingHealthyMatchesAndUnknownScope(): void
    {
        $channel = new \Qualimetrix\Analysis\Finding\Contract\FindingChannel('cohesion.unmatched-exclude-method');
        $publication = new \Qualimetrix\Analysis\Finding\Contract\ChannelPublication(new \Qualimetrix\Analysis\Finding\Contract\RuleEnablement([
            new \Qualimetrix\Analysis\Finding\Contract\EnablementDecision(
                new \Qualimetrix\Analysis\Finding\Contract\Selection\SelectionCellAddress(LcomRule::NAME, $channel, \Qualimetrix\Core\Symbol\SymbolLevel::Project, \Qualimetrix\Analysis\Finding\Contract\ChannelSelectionRole::Selectable),
                new \Qualimetrix\Analysis\Finding\Contract\Selection\AuthoredCellDecision(\Qualimetrix\Analysis\Finding\Contract\Selection\CellSwitch::On, \Qualimetrix\Analysis\Finding\Contract\Selection\CellAdmission::Direct),
            ),
        ], null));
        foreach ([true, false] as $whole) {
            $repository = self::createMock(MetricRepositoryInterface::class);
            $repository->expects($whole ? self::once() : self::never())->method('allCallables')->willReturn([
                self::subjectInfo(SymbolPath::forMethod('App', 'Service', 'known'), RelativePath::fromString('src/Service.php'), 1),
            ]);
            $scope = new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement($whole ? [] : [\Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeDoor::Paths]);
            $session = new \Qualimetrix\Analysis\Finding\Population\PopulationSession($publication);
            $context = (new AnalysisContext($repository, projectScope: $scope))->withPopulationTrace($session);
            $findings = \Qualimetrix\Analysis\Evidence\Cohesion\LcomExcludedMethods::findings($context, new LcomOptions(excludeMethods: ['KNOWN', 'known', 'Missing', 'MISSING']));
            self::assertCount($whole ? 1 : 0, $findings);
            self::assertSame($whole ? 2 : 0, $session->freeze()->judgedCount());
            self::assertSame($whole ? 0 : 2, $session->freeze()->unjudgedCount());
            if (!$whole) {
                self::assertSame('configured-method-selector', $session->freeze()->abstentions()[0]->unit);
                self::assertSame(['known', 'missing'], $session->freeze()->abstentions()[0]->examples);
            }
        }
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
