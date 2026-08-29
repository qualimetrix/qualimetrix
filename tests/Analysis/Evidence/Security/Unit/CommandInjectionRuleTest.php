<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Security\Unit;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Security\CommandInjectionRule;
use Qualimetrix\Analysis\Evidence\Security\SecurityPatternOptions;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(CommandInjectionRule::class)]
#[CoversClass(SecurityPatternOptions::class)]
final class CommandInjectionRuleTest extends TestCase
{
    #[Test]
    public function itHasCorrectNameAndCategory(): void
    {
        $rule = new CommandInjectionRule(new SecurityPatternOptions());

        self::assertSame('security.command-injection', $rule->getName());
        self::assertSame('Detects potential command injection vulnerabilities', $rule->getDescription());
    }

    #[Test]
    public function itRequires(): void
    {
        $rule = new CommandInjectionRule(new SecurityPatternOptions());

        self::assertSame(['security.command_injection'], $rule->requires());
    }

    #[Test]
    public function itReturnsNoFindingsWhenDisabled(): void
    {
        $rule = new CommandInjectionRule(new SecurityPatternOptions(enabled: false));

        $context = $this->createContext(
            (new MetricBag())->withEntry('security.command_injection', ['subjectKind' => 'file', 'line' => 1, 'superglobal' => '']),
        );

        self::assertCount(0, $rule->analyze($context));
    }

    #[Test]
    public function itReturnsNoFindingsWhenNoFindings(): void
    {
        $rule = new CommandInjectionRule(new SecurityPatternOptions());

        $context = $this->createContext(new MetricBag());

        self::assertCount(0, $rule->analyze($context));
    }

    #[Test]
    public function itFailsBeforeEntryProjectionWhenAFileSymbolHasNoContainerPath(): void
    {
        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Missing.php'));
        $fileInfo = new SymbolInfo($symbolPath, null, null);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([$fileInfo]);
        $repository->method('get')->willReturn(
            (new MetricBag())->withEntry('security.command_injection', ['line' => 10]),
        );

        self::expectException(LogicException::class);
        self::expectExceptionMessage('File symbol must carry a relative path');

        (new CommandInjectionRule(new SecurityPatternOptions()))->analyze(new AnalysisContext($repository));
    }

    #[Test]
    public function itCreatesFindingForSingleFinding(): void
    {
        $rule = new CommandInjectionRule(new SecurityPatternOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.command_injection', ['subjectKind' => 'file', 'line' => 20, 'superglobal' => '']),
        );

        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(20, $findings[0]->location->line);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertSame('security.command-injection', $findings[0]->ruleName);
        self::assertSame('Potential command injection — use escapeshellarg() before passing user input to shell commands', $findings[0]->message);
        self::assertSame('file:src/Service/DeployService.php', $findings[0]->subject->toCanonical());
        self::assertSame('src/Service/DeployService.php', $findings[0]->location->pathString());
        self::assertTrue($findings[0]->location->precise);
        self::assertSame(1.0, $findings[0]->metricValue);
        self::assertSame('Use escapeshellarg() for arguments or avoid shell commands entirely.', $findings[0]->recommendation);
        self::assertSame(
            OccurrenceKey::semantic('command_injection', ['type' => 'command_injection', 'superglobal' => ''])->value,
            $findings[0]->occurrenceKey?->value,
        );
    }

    #[Test]
    public function itCreatesMultipleFindingsForMultipleFindings(): void
    {
        $rule = new CommandInjectionRule(new SecurityPatternOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.command_injection', ['subjectKind' => 'file', 'line' => 10, 'superglobal' => ''])
                ->withEntry('security.command_injection', ['subjectKind' => 'file', 'line' => 30, 'superglobal' => '']),
        );

        $findings = $rule->analyze($context);

        self::assertCount(2, $findings);
        self::assertSame(10, $findings[0]->location->line);
        self::assertSame(30, $findings[1]->location->line);
    }

    #[Test]
    public function itIncludesSuperglobalInFindingMessage(): void
    {
        $rule = new CommandInjectionRule(new SecurityPatternOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.command_injection', ['subjectKind' => 'file', 'line' => 20, 'superglobal' => '_REQUEST']),
        );

        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertStringContainsString('($_REQUEST)', $findings[0]->message);
        self::assertStringContainsString('command injection', $findings[0]->message);
    }

    #[Test]
    public function itRejectsWrongOptionsTypeInConstructor(): void
    {
        self::expectException(InvalidArgumentException::class);

        $options = self::createStub(\Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface::class);
        new CommandInjectionRule($options);
    }

    private function createContext(MetricBag $metrics): AnalysisContext
    {
        $filePath = SymbolPath::forFile(RelativePath::fromString('src/Service/DeployService.php'));
        $fileInfo = new SymbolInfo(
            symbolPath: $filePath,
            file: RelativePath::fromString('src/Service/DeployService.php'),
            line: null,
        );

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$fileInfo]);
        $repository->method('get')
            ->willReturn($metrics);

        return new AnalysisContext(metrics: $repository);
    }
}
