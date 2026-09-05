<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\UnreachableCodeOptions;
use Qualimetrix\Analysis\Evidence\CodeSmell\UnreachableCodeRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(UnreachableCodeRule::class)]
#[CoversClass(UnreachableCodeOptions::class)]
final class UnreachableCodeRuleTest extends TestCase
{
    #[Test]
    public function itGetName(): void
    {
        $rule = new UnreachableCodeRule(new UnreachableCodeOptions());

        self::assertSame('code-smell.unreachable-code', $rule->getName());
    }

    #[Test]
    public function itGetDescription(): void
    {
        $rule = new UnreachableCodeRule(new UnreachableCodeOptions());

        self::assertSame('Detects unreachable code after terminal statements', $rule->getDescription());
    }

    #[Test]
    public function itGetOptionsClass(): void
    {
        self::assertSame(UnreachableCodeOptions::class, UnreachableCodeRule::getOptionsClass());
    }

    #[Test]
    public function itGetCliAliases(): void
    {
        self::assertSame(
            ['unreachable-code-warning' => 'warning', 'unreachable-code-error' => 'error'],
            CliAliasReader::read(UnreachableCodeRule::class),
        );
    }

    #[Test]
    public function itConstructorRejectsWrongOptionsType(): void
    {
        self::expectException(InvalidArgumentException::class);

        new UnreachableCodeRule(new class implements \Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface {
            public static function fromArray(array $config): static
            {
                return new static();
            }

            public function isEnabled(): bool
            {
                return true;
            }

            public function getSeverity(int|float $value): ?Severity
            {
                return null;
            }
        });
    }

    #[Test]
    public function itAnalyzeDisabledReturnsEmpty(): void
    {
        $rule = new UnreachableCodeRule(new UnreachableCodeOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('allCallables');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itNoUnreachableCode(): void
    {
        $rule = new UnreachableCodeRule(new UnreachableCodeOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'create');
        $methodInfo = $this->exactDeclarationInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('code-smell.unreachable-code', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itWithUnreachableCode(): void
    {
        $rule = new UnreachableCodeRule(new UnreachableCodeOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'create');
        $methodInfo = $this->exactDeclarationInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())
            ->with('code-smell.unreachable-code', 2)
            ->with('code-smell.unreachable-code.first-line', 15);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertSame(15, $findings[0]->location->line);
        self::assertSame('Found 2 unreachable statement(s) after terminal statement (return/throw/exit/break/continue). Dead code should be removed', $findings[0]->message);
        self::assertSame(2, $findings[0]->metricValue);
        self::assertSame('code-smell.unreachable-code', $findings[0]->ruleName);
        self::assertSame('code-smell.unreachable-code', $findings[0]->code);
    }

    #[Test]
    public function itWithUnreachableCodeFallsBackToMethodLine(): void
    {
        $rule = new UnreachableCodeRule(new UnreachableCodeOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'create');
        $methodInfo = $this->exactDeclarationInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('code-smell.unreachable-code', 1);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(10, $findings[0]->location->line);
    }

    #[Test]
    public function itFallsBackToTheFirstLineWhenCallableMetadataHasNoLine(): void
    {
        $rule = new UnreachableCodeRule(new UnreachableCodeOptions());
        $file = RelativePath::fromString('src/Service/UserService.php');
        $methodInfo = new SymbolInfo(
            MetricSubject::declaration(DeclarationPath::of(SymbolPath::forMethod('App\\Service', 'UserService', 'create'), $file, DeclarationOrdinal::fromRank(0))),
            $file,
            null,
        );

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([$methodInfo]);
        $repository->method('getSubject')->willReturn((new MetricBag())->with('code-smell.unreachable-code', 1));

        $findings = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $findings);
        self::assertSame(1, $findings[0]->location->line);
    }

    #[Test]
    public function itCustomThresholds(): void
    {
        $options = UnreachableCodeOptions::fromArray([
            'enabled' => true,
            'warning' => 2,
            'error' => 3,
        ]);

        self::assertTrue($options->isEnabled());
        self::assertSame(2, $options->warning);
        self::assertSame(3, $options->error);

        // 1 unreachable statement — no finding with custom thresholds
        self::assertNull($options->getSeverity(1));
        // 2 — warning
        self::assertSame(Severity::Warning, $options->getSeverity(2));
        // 3 — error
        self::assertSame(Severity::Error, $options->getSeverity(3));
    }

    #[Test]
    public function itOptionsFromEmptyArrayDisabled(): void
    {
        $options = UnreachableCodeOptions::fromArray([]);

        self::assertFalse($options->isEnabled());
    }

    #[Test]
    public function itOptionsDefaultValues(): void
    {
        $options = new UnreachableCodeOptions();

        self::assertTrue($options->isEnabled());
        self::assertSame(1, $options->warning);
        self::assertSame(2, $options->error);
    }

    #[Test]
    public function itDefaultThresholdsWarningSingleUnreachable(): void
    {
        $options = new UnreachableCodeOptions();

        // 1 unreachable: warning (not error, unlike before)
        self::assertSame(Severity::Warning, $options->getSeverity(1));
        // 2+ unreachable: error
        self::assertSame(Severity::Error, $options->getSeverity(2));
    }

    private function exactDeclarationInfo(SymbolPath $symbolPath, string $file, int $line): SymbolInfo
    {
        $relativePath = RelativePath::fromString($file);

        return new SymbolInfo(
            MetricSubject::declaration(DeclarationPath::of($symbolPath, $relativePath, DeclarationOrdinal::fromRank(0))),
            $relativePath,
            $line,
        );
    }
}
