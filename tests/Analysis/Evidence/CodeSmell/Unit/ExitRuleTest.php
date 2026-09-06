<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellOptions;
use Qualimetrix\Analysis\Evidence\CodeSmell\ExitRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(ExitRule::class)]
final class ExitRuleTest extends TestCase
{
    #[Test]
    public function nameAndDescriptionAreCorrect(): void
    {
        $rule = new ExitRule(new CodeSmellOptions());

        self::assertSame('code-smell.exit', $rule->getName());
        self::assertSame('Detects usage of exit() and die()', $rule->getDescription());
    }

    #[Test]
    public function optionsClassIsCorrect(): void
    {
        self::assertSame(CodeSmellOptions::class, ExitRule::getOptionsClass());
    }

    #[Test]
    public function disabledRuleReturnsNoFindings(): void
    {
        $rule = new ExitRule(new CodeSmellOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function noSmellsProducesNoFindings(): void
    {
        $rule = new ExitRule(new CodeSmellOptions());

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
    public function smellDetectedProducesFinding(): void
    {
        $rule = new ExitRule(new CodeSmellOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Smelly.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Smelly.php'), null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.exit', ['subjectKind' => 'file', 'line' => 10])
            ->withEntry('codeSmell.exit', ['subjectKind' => 'file', 'line' => 35]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(2, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame(10, $findings[0]->location->line);
        self::assertSame(35, $findings[1]->location->line);
        self::assertSame('exit()/die() usage detected - use exceptions instead', $findings[0]->message);
        self::assertSame('code-smell.exit', $findings[0]->ruleName);
        self::assertSame(1.0, $findings[0]->metricValue);
    }

    /**
     * Pins `occurrence` to `SMELL_TYPE` read off a finding produced by
     * {@see ExitRule::analyze()} itself, so a future edit to `SMELL_TYPE`
     * reddens this test even though it looks like a same-shaped refactor of
     * the `codeSmell.{$type}` bag key `AbstractCodeSmellRule::analyze()`
     * shares with every other code-smell rule.
     */
    #[Test]
    public function itKeysOccurrenceToItsOwnSmellType(): void
    {
        $rule = new ExitRule(new CodeSmellOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Smelly.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Smelly.php'), null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.exit', ['subjectKind' => 'file', 'line' => 10]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(
            OccurrenceKey::semantic('exit', [
                'type' => 'exit',
                'extra' => '',
                'hasExtra' => false,
                'promoted' => false,
                'hasPromoted' => false,
            ])->value,
            $findings[0]->occurrenceKey?->value,
        );
    }
}
