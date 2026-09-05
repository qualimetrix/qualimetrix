<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Security\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Security\SensitiveParameterOptions;
use Qualimetrix\Analysis\Evidence\Security\SensitiveParameterRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(SensitiveParameterRule::class)]
#[CoversClass(SensitiveParameterOptions::class)]
final class SensitiveParameterRuleTest extends TestCase
{
    #[Test]
    public function itHasCorrectNameAndCategory(): void
    {
        $rule = new SensitiveParameterRule(new SensitiveParameterOptions());

        self::assertSame('security.sensitive-parameter', $rule->getName());
        self::assertStringContainsString('SensitiveParameter', $rule->getDescription());
    }

    #[Test]
    public function itReturnsNoFindingsWhenDisabled(): void
    {
        $rule = new SensitiveParameterRule(new SensitiveParameterOptions(enabled: false));

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.sensitive-parameter', ['subjectKind' => 'file', 'line' => 1, 'paramName' => 'password'])
                ->withEntry('security.sensitive-parameter', ['subjectKind' => 'file', 'line' => 2, 'paramName' => 'password']),
        );

        self::assertCount(0, $rule->analyze($context));
    }

    #[Test]
    public function itReturnsNoFindingsWhenNoFindings(): void
    {
        $rule = new SensitiveParameterRule(new SensitiveParameterOptions());

        $context = $this->createContext(new MetricBag());

        self::assertCount(0, $rule->analyze($context));
    }

    #[Test]
    public function itCreatesFindingForSingleFinding(): void
    {
        $rule = new SensitiveParameterRule(new SensitiveParameterOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.sensitive-parameter', ['subjectKind' => 'file', 'line' => 12, 'paramName' => 'password']),
        );

        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(12, $findings[0]->location->line);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame('security.sensitive-parameter', $findings[0]->ruleName);
        self::assertStringContainsString('SensitiveParameter', $findings[0]->message);
    }

    #[Test]
    public function itCreatesMultipleFindingsForMultipleFindings(): void
    {
        $rule = new SensitiveParameterRule(new SensitiveParameterOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.sensitive-parameter', ['subjectKind' => 'file', 'line' => 5, 'paramName' => 'password'])
                ->withEntry('security.sensitive-parameter', ['subjectKind' => 'file', 'line' => 10, 'paramName' => 'password'])
                ->withEntry('security.sensitive-parameter', ['subjectKind' => 'file', 'line' => 22, 'paramName' => 'password']),
        );

        $findings = $rule->analyze($context);

        self::assertCount(3, $findings);
        self::assertSame(5, $findings[0]->location->line);
        self::assertSame(10, $findings[1]->location->line);
        self::assertSame(22, $findings[2]->location->line);
    }

    #[Test]
    public function itLoadsOptionsFromArray(): void
    {
        $options = SensitiveParameterOptions::fromArray(['enabled' => false]);
        self::assertFalse($options->isEnabled());

        $options = SensitiveParameterOptions::fromArray([]);
        self::assertTrue($options->isEnabled());
    }

    #[Test]
    public function itComputesSeverityCorrectly(): void
    {
        $options = new SensitiveParameterOptions();

        self::assertSame(Severity::Warning, $options->getSeverity(1));
        self::assertNull($options->getSeverity(0));
    }

    #[Test]
    public function itRejectsWrongOptionsTypeInConstructor(): void
    {
        self::expectException(InvalidArgumentException::class);

        $options = self::createStub(\Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface::class);
        new SensitiveParameterRule($options);
    }

    private function createContext(MetricBag $metrics): AnalysisContext
    {
        $filePath = SymbolPath::forFile(RelativePath::fromString('src/Auth/AuthService.php'));
        $fileInfo = new SymbolInfo(
            symbolPath: $filePath,
            file: RelativePath::fromString('src/Auth/AuthService.php'),
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
