<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\Security;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Rules\Security\HardcodedCredentialsOptions;
use Qualimetrix\Rules\Security\HardcodedCredentialsRule;

#[CoversClass(HardcodedCredentialsRule::class)]
#[CoversClass(HardcodedCredentialsOptions::class)]
final class HardcodedCredentialsRuleTest extends TestCase
{
    #[Test]
    public function itHasCorrectNameAndCategory(): void
    {
        $rule = new HardcodedCredentialsRule(new HardcodedCredentialsOptions());

        self::assertSame('security.hardcoded-credentials', $rule->getName());
        self::assertSame(RuleCategory::Security, $rule->getCategory());
        self::assertSame('Detects hardcoded credentials in code', $rule->getDescription());
    }

    #[Test]
    public function itRequires(): void
    {
        $rule = new HardcodedCredentialsRule(new HardcodedCredentialsOptions());

        self::assertSame(['security.hardcodedCredentials'], $rule->requires());
    }

    #[Test]
    public function itReturnsNoViolationsWhenDisabled(): void
    {
        $rule = new HardcodedCredentialsRule(new HardcodedCredentialsOptions(enabled: false));

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.hardcodedCredentials', ['subjectKind' => 'file', 'line' => 1, 'pattern' => 'variable'])
                ->withEntry('security.hardcodedCredentials', ['subjectKind' => 'file', 'line' => 2, 'pattern' => 'variable']),
        );

        $violations = $rule->analyze($context);

        self::assertCount(0, $violations);
    }

    #[Test]
    public function itReturnsNoViolationsWhenNoFindings(): void
    {
        $rule = new HardcodedCredentialsRule(new HardcodedCredentialsOptions());

        $context = $this->createContext(new MetricBag());

        $violations = $rule->analyze($context);

        self::assertCount(0, $violations);
    }

    #[Test]
    public function itCreatesOneViolationForSingleFinding(): void
    {
        $rule = new HardcodedCredentialsRule(new HardcodedCredentialsOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.hardcodedCredentials', ['subjectKind' => 'file', 'line' => 15, 'pattern' => 'variable']),
        );

        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(15, $violations[0]->location->line);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame('security.hardcoded-credentials', $violations[0]->ruleName);
        self::assertStringContainsString('variable assignment', $violations[0]->message);
    }

    #[Test]
    public function itCreatesMultipleViolationsForMultipleFindings(): void
    {
        $rule = new HardcodedCredentialsRule(new HardcodedCredentialsOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.hardcodedCredentials', ['subjectKind' => 'file', 'line' => 10, 'pattern' => 'variable'])
                ->withEntry('security.hardcodedCredentials', ['subjectKind' => 'file', 'line' => 25, 'pattern' => 'array_key'])
                ->withEntry('security.hardcodedCredentials', ['subjectKind' => 'file', 'line' => 42, 'pattern' => 'define']),
        );

        $violations = $rule->analyze($context);

        self::assertCount(3, $violations);
        self::assertSame(10, $violations[0]->location->line);
        self::assertSame(25, $violations[1]->location->line);
        self::assertSame(42, $violations[2]->location->line);
    }

    #[Test]
    public function itPreservesRepositoryFileAndEntryOrderAcrossBatches(): void
    {
        $firstPath = RelativePath::fromString('src/Zeta.php');
        $secondPath = RelativePath::fromString('src/Alpha.php');
        $firstInfo = new SymbolInfo(SymbolPath::forFile($firstPath), $firstPath, null);
        $secondInfo = new SymbolInfo(SymbolPath::forFile($secondPath), $secondPath, null);
        $metrics = [
            $firstPath->value() => (new MetricBag())
                ->withEntry('security.hardcodedCredentials', ['subjectKind' => 'file', 'line' => 20, 'pattern' => 'property'])
                ->withEntry('security.hardcodedCredentials', ['subjectKind' => 'file', 'line' => 10, 'pattern' => 'variable']),
            $secondPath->value() => (new MetricBag())
                ->withEntry('security.hardcodedCredentials', ['subjectKind' => 'file', 'line' => 5, 'pattern' => 'define']),
        ];

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([$firstInfo, $secondInfo]);
        $repository->method('get')->willReturnCallback(
            static fn(SymbolPath $path): MetricBag => $metrics[$path->toString()],
        );

        $violations = (new HardcodedCredentialsRule(new HardcodedCredentialsOptions()))
            ->analyze(new AnalysisContext(metrics: $repository));

        self::assertSame([
            ['src/Zeta.php', 20],
            ['src/Zeta.php', 10],
            ['src/Alpha.php', 5],
        ], array_map(
            static fn(Violation $violation): array => [$violation->location->pathString(), $violation->location->line],
            $violations,
        ));
    }

    #[Test]
    public function itGroupsEqualPatternEvidenceRegardlessOfLineAndNeverReceivesSecretValues(): void
    {
        $rule = new HardcodedCredentialsRule(new HardcodedCredentialsOptions());
        $violations = $rule->analyze($this->createContext(
            (new MetricBag())
                ->withEntry('security.hardcodedCredentials', ['subjectKind' => 'file', 'line' => 10, 'pattern' => 'variable'])
                ->withEntry('security.hardcodedCredentials', ['subjectKind' => 'file', 'line' => 20, 'pattern' => 'variable'])
                ->withEntry('security.hardcodedCredentials', ['subjectKind' => 'file', 'line' => 30, 'pattern' => 'define']),
        ));

        self::assertSame($violations[0]->occurrenceKey?->value, $violations[1]->occurrenceKey?->value);
        self::assertNotSame($violations[0]->occurrenceKey?->value, $violations[2]->occurrenceKey?->value);
        self::assertNotNull($violations[0]->occurrenceKey);
        self::assertStringNotContainsString('secret', $violations[0]->occurrenceKey->value);
    }

    #[Test]
    public function itProducesCorrectMessageForEnumCasePattern(): void
    {
        $rule = new HardcodedCredentialsRule(new HardcodedCredentialsOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.hardcodedCredentials', ['subjectKind' => 'file', 'line' => 10, 'pattern' => 'enum_case']),
        );

        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertStringContainsString('enum case', $violations[0]->message);
        self::assertSame('security.hardcoded-credentials', $violations[0]->violationCode);
    }

    #[Test]
    public function itProjectsEveryCredentialPatternToItsExactMessage(): void
    {
        $patterns = [
            'variable',
            'array_key',
            'class_const',
            'define',
            'property',
            'parameter',
            'enum_case',
            'unknown',
        ];
        $metrics = new MetricBag();
        foreach ($patterns as $line => $pattern) {
            $metrics = $metrics->withEntry('security.hardcodedCredentials', [
                'subjectKind' => 'file',
                'line' => $line + 1,
                'pattern' => $pattern,
            ]);
        }

        $rule = new HardcodedCredentialsRule(new HardcodedCredentialsOptions());
        $violations = $rule->analyze($this->createContext($metrics));

        self::assertSame([
            'Hardcoded credential in variable assignment — use environment variables or a secrets manager',
            'Hardcoded credential in array key — use environment variables or a secrets manager',
            'Hardcoded credential in class constant — use environment variables or a secrets manager',
            'Hardcoded credential in define() call — use environment variables or a secrets manager',
            'Hardcoded credential in property default — use environment variables or a secrets manager',
            'Hardcoded credential in parameter default — use environment variables or a secrets manager',
            'Hardcoded credential in enum case — use environment variables or a secrets manager',
            'Hardcoded credential found — use environment variables or a secrets manager',
        ], array_map(
            static fn(Violation $violation): string => $violation->message,
            $violations,
        ));
    }

    #[Test]
    public function itLoadsOptionsFromArray(): void
    {
        $options = HardcodedCredentialsOptions::fromArray(['enabled' => false]);
        self::assertFalse($options->isEnabled());

        $options = HardcodedCredentialsOptions::fromArray([]);
        self::assertTrue($options->isEnabled());
    }

    #[Test]
    public function itComputesSeverityCorrectly(): void
    {
        $options = new HardcodedCredentialsOptions();

        self::assertSame(Severity::Error, $options->getSeverity(1));
        self::assertNull($options->getSeverity(0));
    }

    #[Test]
    public function itRejectsWrongOptionsTypeInConstructor(): void
    {
        self::expectException(InvalidArgumentException::class);

        $options = self::createStub(\Qualimetrix\Core\Rule\RuleOptionsInterface::class);
        new HardcodedCredentialsRule($options);
    }

    private function createContext(MetricBag $metrics): AnalysisContext
    {
        $filePath = SymbolPath::forFile(RelativePath::fromString('src/Config/Database.php'));
        $fileInfo = new SymbolInfo(
            symbolPath: $filePath,
            file: RelativePath::fromString('src/Config/Database.php'),
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
