<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\UnusedPrivateOptions;
use Qualimetrix\Analysis\Evidence\CodeSmell\UnusedPrivateRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(UnusedPrivateRule::class)]
#[CoversClass(UnusedPrivateOptions::class)]
final class UnusedPrivateRuleTest extends TestCase
{
    #[Test]
    public function nameAndDescriptionAreCorrect(): void
    {
        $rule = new UnusedPrivateRule(new UnusedPrivateOptions());

        self::assertSame('code-smell.unused-private', $rule->getName());
        self::assertSame('Detects unused private methods, properties, and constants', $rule->getDescription());
        self::assertSame(RuleCategory::CodeSmell, $rule->getCategory());
    }

    #[Test]
    public function optionsClassIsCorrect(): void
    {
        self::assertSame(UnusedPrivateOptions::class, UnusedPrivateRule::getOptionsClass());
    }

    #[Test]
    public function disabledRuleReturnsNoViolations(): void
    {
        $rule = new UnusedPrivateRule(new UnusedPrivateOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('allDeclarations');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function noUnusedMembersProducesNoViolations(): void
    {
        $rule = new UnusedPrivateRule(new UnusedPrivateOptions());

        $symbolPath = SymbolPath::forClass('App', 'Clean');
        $classInfo = $this->exactClassInfo($symbolPath, 'src/Clean.php', 5);

        $metricBag = (new MetricBag())
            ->with('unusedPrivate.total', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function unusedMethodProducesViolation(): void
    {
        $rule = new UnusedPrivateRule(new UnusedPrivateOptions());

        $symbolPath = SymbolPath::forClass('App', 'Smelly');
        $classInfo = $this->exactClassInfo($symbolPath, 'src/Smelly.php', 5);

        $metricBag = (new MetricBag())
            ->with('unusedPrivate.total', 1)
            ->withEntry('unusedPrivate.method', ['line' => 15, 'name' => 'doLoadMappingFile']);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame(15, $violations[0]->location->line);
        self::assertSame('Unused private method `doLoadMappingFile`', $violations[0]->message);
        self::assertSame('code-smell.unused-private', $violations[0]->ruleName);
        self::assertSame(1, $violations[0]->metricValue);
    }

    #[Test]
    public function unusedPropertyProducesViolation(): void
    {
        $rule = new UnusedPrivateRule(new UnusedPrivateOptions());

        $symbolPath = SymbolPath::forClass('App', 'PropClass');
        $classInfo = $this->exactClassInfo($symbolPath, 'src/PropClass.php', 5);

        $metricBag = (new MetricBag())
            ->with('unusedPrivate.total', 1)
            ->withEntry('unusedPrivate.property', ['line' => 10, 'name' => 'cache']);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame('Unused private property `cache`', $violations[0]->message);
    }

    #[Test]
    public function unusedConstantProducesViolation(): void
    {
        $rule = new UnusedPrivateRule(new UnusedPrivateOptions());

        $symbolPath = SymbolPath::forClass('App', 'ConstClass');
        $classInfo = $this->exactClassInfo($symbolPath, 'src/ConstClass.php', 5);

        $metricBag = (new MetricBag())
            ->with('unusedPrivate.total', 1)
            ->withEntry('unusedPrivate.constant', ['line' => 8, 'name' => 'MAX_RETRIES']);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame('Unused private constant `MAX_RETRIES`', $violations[0]->message);
        self::assertSame(8, $violations[0]->location->line);
    }

    #[Test]
    public function multipleUnusedMembersProduceMultipleViolations(): void
    {
        $rule = new UnusedPrivateRule(new UnusedPrivateOptions());

        $symbolPath = SymbolPath::forClass('App', 'ManyUnused');
        $classInfo = $this->exactClassInfo($symbolPath, 'src/ManyUnused.php', 5);

        $metricBag = (new MetricBag())
            ->with('unusedPrivate.total', 4)
            ->withEntry('unusedPrivate.method', ['line' => 10, 'name' => 'foo'])
            ->withEntry('unusedPrivate.method', ['line' => 15, 'name' => 'bar'])
            ->withEntry('unusedPrivate.property', ['line' => 7, 'name' => 'baz'])
            ->withEntry('unusedPrivate.constant', ['line' => 8, 'name' => 'QUX']);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(4, $violations);

        self::assertSame([
            'Unused private method `foo`',
            'Unused private method `bar`',
            'Unused private property `baz`',
            'Unused private constant `QUX`',
        ], array_map(static fn($violation): string => $violation->message, $violations));
        self::assertSame([10, 15, 7, 8], array_map(static fn($violation): ?int => $violation->location->line, $violations));
        self::assertSame([4, 4, 4, 4], array_map(static fn($violation): int|float|null => $violation->metricValue, $violations));
        self::assertSame([true, true, true, true], array_map(static fn($violation): bool => $violation->location->precise, $violations));
    }

    #[Test]
    public function itPreservesTheUnnamedEntryWordingAndRecommendation(): void
    {
        $classInfo = $this->exactClassInfo(SymbolPath::forClass('App', 'Unnamed'), 'src/Unnamed.php', 5);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')->willReturn(
            (new MetricBag())
                ->with('unusedPrivate.total', 1)
                ->withEntry('unusedPrivate.property', ['line' => 9]),
        );

        $violations = (new UnusedPrivateRule(new UnusedPrivateOptions()))->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertSame('Unused private property', $violations[0]->message);
        self::assertSame('Remove the unused symbol to reduce dead code.', $violations[0]->recommendation);
        self::assertSame($classInfo->subject?->toCanonical(), $violations[0]->subject->toCanonical());
    }

    #[Test]
    public function itRejectsMissingExactAndDeclarationSubjectsBeforeReadingMetrics(): void
    {
        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('getSubject');
        $repository->method('allDeclarations')->willReturn([
            new SymbolInfo(SymbolPath::forClass('App', 'Legacy'), RelativePath::fromString('src/Legacy.php'), 1),
        ]);

        self::expectException(LogicException::class);
        self::expectExceptionMessage('Unused private findings require an exact class subject');

        (new UnusedPrivateRule(new UnusedPrivateOptions()))->analyze(new AnalysisContext($repository));
    }

    #[Test]
    public function itSkipsTypedNonClassDeclarationsBeforeReadingMetrics(): void
    {
        $path = RelativePath::fromString('src/helper.php');
        $function = new SymbolInfo(
            MetricSubject::declaration(new DeclarationPath(SymbolPath::forGlobalFunction('App', 'helper'), $path, 10)),
            $path,
            1,
        );
        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')->willReturn([$function]);
        $repository->expects(self::never())->method('getSubject');

        self::assertSame([], (new UnusedPrivateRule(new UnusedPrivateOptions()))->analyze(new AnalysisContext($repository)));
    }

    #[Test]
    public function itProducesSeparateExactSubjectFindingsForDuplicateLogicalClassDeclarations(): void
    {
        $logical = SymbolPath::forClass('App', 'Duplicated');
        $first = $this->exactClassInfo($logical, 'src/First.php', 5);
        $second = $this->exactClassInfo($logical, 'src/Second.php', 8);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')->willReturn([$first, $second]);
        $repository->method('getSubject')->willReturnCallback(
            static fn() => (new MetricBag())
                ->with('unusedPrivate.total', 1)
                ->withEntry('unusedPrivate.method', ['line' => 15, 'name' => 'stale']),
        );

        $violations = (new UnusedPrivateRule(new UnusedPrivateOptions()))->analyze(new AnalysisContext($repository));

        self::assertCount(2, $violations);
        self::assertSame($first->subject?->toCanonical(), $violations[0]->subject->toCanonical());
        self::assertSame($second->subject?->toCanonical(), $violations[1]->subject->toCanonical());
    }

    #[Test]
    public function optionsFromArray(): void
    {
        $options = UnusedPrivateOptions::fromArray(['enabled' => false]);
        self::assertFalse($options->isEnabled());

        $options = UnusedPrivateOptions::fromArray([]);
        self::assertTrue($options->isEnabled());
    }

    #[Test]
    public function optionsSeverity(): void
    {
        $options = new UnusedPrivateOptions();

        self::assertSame(Severity::Warning, $options->getSeverity(1));
        self::assertSame(Severity::Warning, $options->getSeverity(5));
        self::assertNull($options->getSeverity(0));
    }

    private function exactClassInfo(SymbolPath $symbolPath, string $file, int $line): SymbolInfo
    {
        $relativePath = RelativePath::fromString($file);

        return new SymbolInfo(
            MetricSubject::declaration(new DeclarationPath($symbolPath, $relativePath, $line)),
            $relativePath,
            $line,
        );
    }
}
