<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Security\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Security\HardcodedCredentialsOptions;
use Qualimetrix\Analysis\Evidence\Security\HardcodedCredentialsRule;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

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
    public function itReturnsNoFindingsWhenDisabled(): void
    {
        $rule = new HardcodedCredentialsRule(new HardcodedCredentialsOptions(enabled: false));

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.hardcodedCredentials', ['subjectKind' => 'file', 'line' => 1, 'pattern' => 'variable'])
                ->withEntry('security.hardcodedCredentials', ['subjectKind' => 'file', 'line' => 2, 'pattern' => 'variable']),
        );

        $findings = $rule->analyze($context);

        self::assertCount(0, $findings);
    }

    #[Test]
    public function itReturnsNoFindingsWhenNoFindings(): void
    {
        $rule = new HardcodedCredentialsRule(new HardcodedCredentialsOptions());

        $context = $this->createContext(new MetricBag());

        $findings = $rule->analyze($context);

        self::assertCount(0, $findings);
    }

    #[Test]
    public function itCreatesOneFindingForSingleFinding(): void
    {
        $rule = new HardcodedCredentialsRule(new HardcodedCredentialsOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.hardcodedCredentials', ['subjectKind' => 'file', 'line' => 15, 'pattern' => 'variable']),
        );

        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(15, $findings[0]->location->line);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertSame('security.hardcoded-credentials', $findings[0]->ruleName);
        self::assertStringContainsString('variable assignment', $findings[0]->message);
    }

    #[Test]
    public function itCreatesMultipleFindingsForMultipleFindings(): void
    {
        $rule = new HardcodedCredentialsRule(new HardcodedCredentialsOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.hardcodedCredentials', ['subjectKind' => 'file', 'line' => 10, 'pattern' => 'variable'])
                ->withEntry('security.hardcodedCredentials', ['subjectKind' => 'file', 'line' => 25, 'pattern' => 'array_key'])
                ->withEntry('security.hardcodedCredentials', ['subjectKind' => 'file', 'line' => 42, 'pattern' => 'define']),
        );

        $findings = $rule->analyze($context);

        self::assertCount(3, $findings);
        self::assertSame(10, $findings[0]->location->line);
        self::assertSame(25, $findings[1]->location->line);
        self::assertSame(42, $findings[2]->location->line);
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

        $findings = (new HardcodedCredentialsRule(new HardcodedCredentialsOptions()))
            ->analyze(new AnalysisContext(metrics: $repository));

        self::assertSame([
            ['src/Zeta.php', 20],
            ['src/Zeta.php', 10],
            ['src/Alpha.php', 5],
        ], array_map(
            static fn(Finding $finding): array => [$finding->location->pathString(), $finding->location->line],
            $findings,
        ));
    }

    #[Test]
    public function itGroupsEqualPatternEvidenceRegardlessOfLineAndNeverReceivesSecretValues(): void
    {
        $rule = new HardcodedCredentialsRule(new HardcodedCredentialsOptions());
        $findings = $rule->analyze($this->createContext(
            (new MetricBag())
                ->withEntry('security.hardcodedCredentials', ['subjectKind' => 'file', 'line' => 10, 'pattern' => 'variable'])
                ->withEntry('security.hardcodedCredentials', ['subjectKind' => 'file', 'line' => 20, 'pattern' => 'variable'])
                ->withEntry('security.hardcodedCredentials', ['subjectKind' => 'file', 'line' => 30, 'pattern' => 'define']),
        ));

        self::assertSame($findings[0]->occurrenceKey?->value, $findings[1]->occurrenceKey?->value);
        self::assertNotSame($findings[0]->occurrenceKey?->value, $findings[2]->occurrenceKey?->value);
        self::assertNotNull($findings[0]->occurrenceKey);
        self::assertStringNotContainsString('secret', $findings[0]->occurrenceKey->value);
    }

    #[Test]
    public function itProducesCorrectMessageForEnumCasePattern(): void
    {
        $rule = new HardcodedCredentialsRule(new HardcodedCredentialsOptions());

        $context = $this->createContext(
            (new MetricBag())
                ->withEntry('security.hardcodedCredentials', ['subjectKind' => 'file', 'line' => 10, 'pattern' => 'enum_case']),
        );

        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertStringContainsString('enum case', $findings[0]->message);
        self::assertSame('security.hardcoded-credentials', $findings[0]->code);
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
        $findings = $rule->analyze($this->createContext($metrics));

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
            static fn(Finding $finding): string => $finding->message,
            $findings,
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

        $options = self::createStub(\Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface::class);
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
