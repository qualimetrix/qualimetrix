<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\Security;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Rules\Security\CommandInjectionRule;
use Qualimetrix\Rules\Security\SecurityPatternOptions;

#[CoversClass(CommandInjectionRule::class)]
#[CoversClass(SecurityPatternOptions::class)]
final class CommandInjectionRuleTest extends TestCase
{
    #[Test]
    public function itHasCorrectNameAndCategory(): void
    {
        $rule = new CommandInjectionRule(new SecurityPatternOptions());

        self::assertSame('security.command-injection', $rule->getName());
        self::assertSame(RuleCategory::Security, $rule->getCategory());
        self::assertSame('Detects potential command injection vulnerabilities', $rule->getDescription());
    }

    #[Test]
    public function itRequires(): void
    {
        $rule = new CommandInjectionRule(new SecurityPatternOptions());

        self::assertSame(['security.command_injection'], $rule->requires());
    }

    #[Test]
    public function itReturnsNoViolationsWhenDisabled(): void
    {
        $rule = new CommandInjectionRule(new SecurityPatternOptions(enabled: false));

        $context = $this->createContext(
            (new MetricBag())->withEntry('security.command_injection', ['subjectKind' => 'file', 'line' => 1, 'superglobal' => '']),
        );

        self::assertCount(0, $rule->analyze($context));
    }

    #[Test]
    public function itReturnsNoViolationsWhenNoFindings(): void
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
    public function itCreatesViolationForSingleFinding(): void
    {
        $rule = new CommandInjectionRule(new SecurityPatternOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.command_injection', ['subjectKind' => 'file', 'line' => 20, 'superglobal' => '']),
        );

        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(20, $violations[0]->location->line);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame('security.command-injection', $violations[0]->ruleName);
        self::assertSame('Potential command injection — use escapeshellarg() before passing user input to shell commands', $violations[0]->message);
        self::assertSame('file:src/Service/DeployService.php', $violations[0]->subject->toCanonical());
        self::assertSame('src/Service/DeployService.php', $violations[0]->location->pathString());
        self::assertTrue($violations[0]->location->precise);
        self::assertSame(1.0, $violations[0]->metricValue);
        self::assertSame('Use escapeshellarg() for arguments or avoid shell commands entirely.', $violations[0]->recommendation);
        self::assertSame(
            OccurrenceKey::semantic('command_injection', ['type' => 'command_injection', 'superglobal' => ''])->value,
            $violations[0]->occurrenceKey?->value,
        );
    }

    #[Test]
    public function itCreatesMultipleViolationsForMultipleFindings(): void
    {
        $rule = new CommandInjectionRule(new SecurityPatternOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.command_injection', ['subjectKind' => 'file', 'line' => 10, 'superglobal' => ''])
                ->withEntry('security.command_injection', ['subjectKind' => 'file', 'line' => 30, 'superglobal' => '']),
        );

        $violations = $rule->analyze($context);

        self::assertCount(2, $violations);
        self::assertSame(10, $violations[0]->location->line);
        self::assertSame(30, $violations[1]->location->line);
    }

    #[Test]
    public function itIncludesSuperglobalInViolationMessage(): void
    {
        $rule = new CommandInjectionRule(new SecurityPatternOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.command_injection', ['subjectKind' => 'file', 'line' => 20, 'superglobal' => '_REQUEST']),
        );

        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertStringContainsString('($_REQUEST)', $violations[0]->message);
        self::assertStringContainsString('command injection', $violations[0]->message);
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
