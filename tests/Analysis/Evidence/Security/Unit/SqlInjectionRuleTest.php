<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Security\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Security\SecurityPatternOptions;
use Qualimetrix\Analysis\Evidence\Security\SqlInjectionRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(SqlInjectionRule::class)]
#[CoversClass(SecurityPatternOptions::class)]
final class SqlInjectionRuleTest extends TestCase
{
    #[Test]
    public function itHasCorrectNameAndCategory(): void
    {
        $rule = new SqlInjectionRule(new SecurityPatternOptions());

        self::assertSame('security.sql-injection', $rule->getName());
        self::assertSame(RuleCategory::Security, $rule->getCategory());
        self::assertSame('Detects potential SQL injection vulnerabilities', $rule->getDescription());
    }

    #[Test]
    public function itRequires(): void
    {
        $rule = new SqlInjectionRule(new SecurityPatternOptions());

        self::assertSame(['security.sql_injection'], $rule->requires());
    }

    #[Test]
    public function itReturnsNoFindingsWhenDisabled(): void
    {
        $rule = new SqlInjectionRule(new SecurityPatternOptions(enabled: false));

        $context = $this->createContext(
            (new MetricBag())->withEntry('security.sql_injection', ['subjectKind' => 'file', 'line' => 1, 'superglobal' => '']),
        );

        self::assertCount(0, $rule->analyze($context));
    }

    #[Test]
    public function itReturnsNoFindingsWhenNoFindings(): void
    {
        $rule = new SqlInjectionRule(new SecurityPatternOptions());

        $context = $this->createContext(new MetricBag());

        self::assertCount(0, $rule->analyze($context));
    }

    #[Test]
    public function itCreatesFindingForSingleFinding(): void
    {
        $rule = new SqlInjectionRule(new SecurityPatternOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.sql_injection', ['subjectKind' => 'file', 'line' => 15, 'superglobal' => '']),
        );

        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(15, $findings[0]->location->line);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertSame('security.sql-injection', $findings[0]->ruleName);
        self::assertSame('Potential SQL injection — use parameterized queries instead of direct superglobal interpolation', $findings[0]->message);
        self::assertSame('file:src/Controller/UserController.php', $findings[0]->subject->toCanonical());
        self::assertSame('Use parameterized queries or prepared statements.', $findings[0]->recommendation);
        self::assertTrue($findings[0]->location->precise);
    }

    #[Test]
    public function itCreatesMultipleFindingsForMultipleFindings(): void
    {
        $rule = new SqlInjectionRule(new SecurityPatternOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.sql_injection', ['subjectKind' => 'file', 'line' => 10, 'superglobal' => ''])
                ->withEntry('security.sql_injection', ['subjectKind' => 'file', 'line' => 25, 'superglobal' => ''])
                ->withEntry('security.sql_injection', ['subjectKind' => 'file', 'line' => 42, 'superglobal' => '']),
        );

        $findings = $rule->analyze($context);

        self::assertCount(3, $findings);
        self::assertSame(10, $findings[0]->location->line);
        self::assertSame(25, $findings[1]->location->line);
        self::assertSame(42, $findings[2]->location->line);
    }

    #[Test]
    public function itGroupsOnlySemanticPatternEvidenceRatherThanLinesOrRawContext(): void
    {
        $rule = new SqlInjectionRule(new SecurityPatternOptions());
        $findings = $rule->analyze($this->createContext(
            (new MetricBag())
                ->withEntry('security.sql_injection', ['subjectKind' => 'file', 'line' => 10, 'superglobal' => '_GET'])
                ->withEntry('security.sql_injection', ['subjectKind' => 'file', 'line' => 20, 'superglobal' => '_GET'])
                ->withEntry('security.sql_injection', ['subjectKind' => 'file', 'line' => 30, 'superglobal' => '_POST']),
        ));

        self::assertSame($findings[0]->occurrenceKey?->value, $findings[1]->occurrenceKey?->value);
        self::assertNotSame($findings[0]->occurrenceKey?->value, $findings[2]->occurrenceKey?->value);
    }

    #[Test]
    public function itIncludesSuperglobalInFindingMessage(): void
    {
        $rule = new SqlInjectionRule(new SecurityPatternOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.sql_injection', ['subjectKind' => 'file', 'line' => 15, 'superglobal' => '_GET']),
        );

        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertStringContainsString('($_GET)', $findings[0]->message);
        self::assertStringContainsString('SQL injection', $findings[0]->message);
    }

    #[Test]
    public function itLoadsOptionsFromArray(): void
    {
        $options = SecurityPatternOptions::fromArray(['enabled' => false]);
        self::assertFalse($options->isEnabled());

        $options = SecurityPatternOptions::fromArray([]);
        self::assertTrue($options->isEnabled());
    }

    #[Test]
    public function itComputesSeverityCorrectly(): void
    {
        $options = new SecurityPatternOptions();

        self::assertSame(Severity::Error, $options->getSeverity(1));
        self::assertNull($options->getSeverity(0));
    }

    #[Test]
    public function itRejectsWrongOptionsTypeInConstructor(): void
    {
        self::expectException(InvalidArgumentException::class);

        $options = self::createStub(\Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface::class);
        new SqlInjectionRule($options);
    }

    private function createContext(MetricBag $metrics): AnalysisContext
    {
        $filePath = SymbolPath::forFile(RelativePath::fromString('src/Controller/UserController.php'));
        $fileInfo = new SymbolInfo(
            symbolPath: $filePath,
            file: RelativePath::fromString('src/Controller/UserController.php'),
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
