<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\Security;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Security\SecurityPatternOptions;
use Qualimetrix\Rules\Security\SqlInjectionRule;

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
    public function itReturnsNoViolationsWhenDisabled(): void
    {
        $rule = new SqlInjectionRule(new SecurityPatternOptions(enabled: false));

        $context = $this->createContext(
            (new MetricBag())->withEntry('security.sql_injection', ['subjectKind' => 'file', 'line' => 1, 'superglobal' => '']),
        );

        self::assertCount(0, $rule->analyze($context));
    }

    #[Test]
    public function itReturnsNoViolationsWhenNoFindings(): void
    {
        $rule = new SqlInjectionRule(new SecurityPatternOptions());

        $context = $this->createContext(new MetricBag());

        self::assertCount(0, $rule->analyze($context));
    }

    #[Test]
    public function itCreatesViolationForSingleFinding(): void
    {
        $rule = new SqlInjectionRule(new SecurityPatternOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.sql_injection', ['subjectKind' => 'file', 'line' => 15, 'superglobal' => '']),
        );

        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(15, $violations[0]->location->line);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame('security.sql-injection', $violations[0]->ruleName);
        self::assertSame('Potential SQL injection — use parameterized queries instead of direct superglobal interpolation', $violations[0]->message);
        self::assertSame('file:src/Controller/UserController.php', $violations[0]->subject->toCanonical());
        self::assertSame('Use parameterized queries or prepared statements.', $violations[0]->recommendation);
        self::assertTrue($violations[0]->location->precise);
    }

    #[Test]
    public function itCreatesMultipleViolationsForMultipleFindings(): void
    {
        $rule = new SqlInjectionRule(new SecurityPatternOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.sql_injection', ['subjectKind' => 'file', 'line' => 10, 'superglobal' => ''])
                ->withEntry('security.sql_injection', ['subjectKind' => 'file', 'line' => 25, 'superglobal' => ''])
                ->withEntry('security.sql_injection', ['subjectKind' => 'file', 'line' => 42, 'superglobal' => '']),
        );

        $violations = $rule->analyze($context);

        self::assertCount(3, $violations);
        self::assertSame(10, $violations[0]->location->line);
        self::assertSame(25, $violations[1]->location->line);
        self::assertSame(42, $violations[2]->location->line);
    }

    #[Test]
    public function itGroupsOnlySemanticPatternEvidenceRatherThanLinesOrRawContext(): void
    {
        $rule = new SqlInjectionRule(new SecurityPatternOptions());
        $violations = $rule->analyze($this->createContext(
            (new MetricBag())
                ->withEntry('security.sql_injection', ['subjectKind' => 'file', 'line' => 10, 'superglobal' => '_GET'])
                ->withEntry('security.sql_injection', ['subjectKind' => 'file', 'line' => 20, 'superglobal' => '_GET'])
                ->withEntry('security.sql_injection', ['subjectKind' => 'file', 'line' => 30, 'superglobal' => '_POST']),
        ));

        self::assertSame($violations[0]->occurrenceKey?->value, $violations[1]->occurrenceKey?->value);
        self::assertNotSame($violations[0]->occurrenceKey?->value, $violations[2]->occurrenceKey?->value);
    }

    #[Test]
    public function itIncludesSuperglobalInViolationMessage(): void
    {
        $rule = new SqlInjectionRule(new SecurityPatternOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.sql_injection', ['subjectKind' => 'file', 'line' => 15, 'superglobal' => '_GET']),
        );

        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertStringContainsString('($_GET)', $violations[0]->message);
        self::assertStringContainsString('SQL injection', $violations[0]->message);
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

        $options = self::createStub(\Qualimetrix\Core\Rule\RuleOptionsInterface::class);
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
