<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellOptions;
use Qualimetrix\Analysis\Evidence\CodeSmell\SuperglobalsRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(SuperglobalsRule::class)]
final class SuperglobalsRuleTest extends TestCase
{
    #[Test]
    public function itReportsItsNameAndDescription(): void
    {
        $rule = new SuperglobalsRule(new CodeSmellOptions());

        self::assertSame('code-smell.superglobals', $rule->getName());
        self::assertSame('Detects direct access to superglobals', $rule->getDescription());
    }

    #[Test]
    public function itDeclaresCodeSmellOptionsAsItsOptionsClass(): void
    {
        self::assertSame(CodeSmellOptions::class, SuperglobalsRule::getOptionsClass());
    }

    #[Test]
    public function itProducesNoFindingsWhenDisabled(): void
    {
        $rule = new SuperglobalsRule(new CodeSmellOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itProducesNoFindingsWhenNoSmellsArePresent(): void
    {
        $rule = new SuperglobalsRule(new CodeSmellOptions());

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
    public function itReportsAFindingForEachDirectSuperglobalAccess(): void
    {
        $rule = new SuperglobalsRule(new CodeSmellOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Smelly.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Smelly.php'), null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.superglobals', ['subjectKind' => 'file', 'line' => 5])
            ->withEntry('codeSmell.superglobals', ['subjectKind' => 'file', 'line' => 18])
            ->withEntry('codeSmell.superglobals', ['subjectKind' => 'file', 'line' => 33]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(3, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame(5, $findings[0]->location->line);
        self::assertSame(18, $findings[1]->location->line);
        self::assertSame(33, $findings[2]->location->line);
        self::assertSame('Direct superglobal access detected - use dependency injection', $findings[0]->message);
        self::assertSame('code-smell.superglobals', $findings[0]->ruleName);
        self::assertSame(1.0, $findings[0]->metricValue);
    }

    /**
     * Pins `occurrence` to `SMELL_TYPE` read off a finding produced by
     * {@see SuperglobalsRule::analyze()} itself, so a future edit to
     * `SMELL_TYPE` reddens this test even though it looks like a
     * same-shaped refactor of the `codeSmell.{$type}` bag key
     * `AbstractCodeSmellRule::analyze()` shares with every other
     * code-smell rule.
     */
    #[Test]
    public function itKeysOccurrenceToItsOwnSmellType(): void
    {
        $rule = new SuperglobalsRule(new CodeSmellOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Smelly.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Smelly.php'), null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.superglobals', ['subjectKind' => 'file', 'line' => 5]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(
            OccurrenceKey::semantic('superglobals', [
                'type' => 'superglobals',
                'extra' => '',
                'hasExtra' => false,
                'promoted' => false,
                'hasPromoted' => false,
            ])->value,
            $findings[0]->occurrenceKey?->value,
        );
    }
}
