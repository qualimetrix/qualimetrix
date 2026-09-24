<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellOptions;
use Qualimetrix\Analysis\Evidence\CodeSmell\EmptyCatchRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(EmptyCatchRule::class)]
final class EmptyCatchRuleTest extends TestCase
{
    #[Test]
    public function itExposesItsRuleNameAndDescription(): void
    {
        $rule = new EmptyCatchRule(new CodeSmellOptions());

        self::assertSame('code-smell.empty-catch', $rule->getName());
        self::assertSame('Detects empty catch blocks', $rule->getDescription());
    }

    #[Test]
    public function itDeclaresCodeSmellOptionsAsItsOptionsClass(): void
    {
        self::assertSame(CodeSmellOptions::class, EmptyCatchRule::getOptionsClass());
    }

    #[Test]
    public function itSkipsMetricLookupAndReturnsNoFindingsWhenDisabled(): void
    {
        $rule = new EmptyCatchRule(new CodeSmellOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itReturnsNoFindingsWhenNoEmptyCatchIsRecorded(): void
    {
        $rule = new EmptyCatchRule(new CodeSmellOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Clean.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Clean.php'), null);

        $metricBag = new MetricBag();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itReportsAnErrorFindingAtTheRecordedLineWhenAnEmptyCatchIsDetected(): void
    {
        $rule = new EmptyCatchRule(new CodeSmellOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Smelly.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Smelly.php'), null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.empty_catch', ['subjectKind' => 'file', 'line' => 20]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertSame(20, $findings[0]->location->line);
        self::assertSame('Empty catch block detected - exceptions should not be silently ignored', $findings[0]->message);
        self::assertSame('code-smell.empty-catch', $findings[0]->ruleName);
        self::assertSame(1.0, $findings[0]->metricValue);
        // A comment-only catch is still reported, so the advice must not offer a comment as a remedy.
        self::assertSame(
            'Log or rethrow the exception, or handle it explicitly. A comment alone does not clear this finding; suppress an intentional ignore with `@qmx-ignore code-smell.empty-catch` and a reason.',
            $findings[0]->recommendation,
        );
    }

    /**
     * Pins `occurrence` to `SMELL_TYPE` read off a finding produced by
     * {@see EmptyCatchRule::analyze()} itself, so a future edit to
     * `SMELL_TYPE` reddens this test even though it looks like a
     * same-shaped refactor of the `codeSmell.{$type}` bag key
     * `AbstractCodeSmellRule::analyze()` shares with every other
     * code-smell rule.
     */
    #[Test]
    public function itKeysOccurrenceToItsOwnSmellType(): void
    {
        $rule = new EmptyCatchRule(new CodeSmellOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Smelly.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Smelly.php'), null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.empty_catch', ['subjectKind' => 'file', 'line' => 20]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(
            OccurrenceKey::semantic('empty_catch', [
                'type' => 'empty_catch',
                'extra' => '',
                'hasExtra' => false,
                'promoted' => false,
                'hasPromoted' => false,
            ])->value,
            $findings[0]->occurrenceKey?->value,
        );
    }
}
