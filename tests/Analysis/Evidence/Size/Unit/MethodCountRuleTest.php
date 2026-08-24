<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Size\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Size\MethodCountOptions;
use Qualimetrix\Analysis\Evidence\Size\MethodCountRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(MethodCountRule::class)]
#[CoversClass(MethodCountOptions::class)]
final class MethodCountRuleTest extends TestCase
{
    #[Test]
    public function itGetsName(): void
    {
        $rule = new MethodCountRule(new MethodCountOptions());

        self::assertSame('size.method-count', $rule->getName());
    }

    #[Test]
    public function itGetsDescription(): void
    {
        $rule = new MethodCountRule(new MethodCountOptions());

        self::assertSame('Checks number of methods per class', $rule->getDescription());
    }

    #[Test]
    public function itGetsCategory(): void
    {
        $rule = new MethodCountRule(new MethodCountOptions());

        self::assertSame(RuleCategory::Size, $rule->getCategory());
    }

    #[Test]
    public function itRequiresMethodCount(): void
    {
        $rule = new MethodCountRule(new MethodCountOptions());

        self::assertSame(['methodCount'], $rule->requires());
    }

    #[Test]
    public function itGetsOptionsClass(): void
    {
        self::assertSame(MethodCountOptions::class, MethodCountRule::getOptionsClass());
    }

    #[Test]
    public function itGetsCliAliases(): void
    {
        self::assertSame(
            ['method-count-warning' => 'warning', 'method-count-error' => 'error'],
            CliAliasReader::read(MethodCountRule::class),
        );
    }

    #[Test]
    public function itRejectsWrongOptionsTypeInConstructor(): void
    {
        self::expectException(InvalidArgumentException::class);

        new MethodCountRule(new class implements \Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface {
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
    public function itReturnsEmptyWhenDisabled(): void
    {
        $rule = new MethodCountRule(new MethodCountOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('allDeclarations');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itReturnsEmptyWhenBelowThreshold(): void
    {
        $rule = new MethodCountRule(new MethodCountOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        $metricBag = (new MetricBag())->with('methodCount', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itGeneratesWarning(): void
    {
        $rule = new MethodCountRule(new MethodCountOptions(warning: 10, error: 20));

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        $metricBag = (new MetricBag())->with('methodCount', 15);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame('Method count is 15, exceeds threshold of 10. Consider splitting into smaller focused classes', $findings[0]->message);
        self::assertSame(15, $findings[0]->metricValue);
        self::assertSame('size.method-count', $findings[0]->ruleName);
        self::assertSame('size.method-count', $findings[0]->code);
    }

    #[Test]
    public function itGeneratesError(): void
    {
        $rule = new MethodCountRule(new MethodCountOptions(warning: 10, error: 20));

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        $metricBag = (new MetricBag())->with('methodCount', 25);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertSame('Method count is 25, exceeds threshold of 20. Consider splitting into smaller focused classes', $findings[0]->message);
    }

    #[Test]
    #[DataProvider('thresholdDataProvider')]
    public function itRespectsBoundaryThresholds(
        int $methodCount,
        int $warning,
        int $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new MethodCountRule(new MethodCountOptions(warning: $warning, error: $error));

        $symbolPath = SymbolPath::forClass('App\Test', 'TestClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('test.php'), 10);

        $metricBag = (new MetricBag())->with('methodCount', $methodCount);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        if ($expectedSeverity === null) {
            self::assertCount(0, $findings);
        } else {
            self::assertCount(1, $findings);
            self::assertSame($expectedSeverity, $findings[0]->severity);
        }
    }

    /**
     * @return iterable<string, array{int, int, int, ?Severity}>
     */
    public static function thresholdDataProvider(): iterable
    {
        yield 'below warning' => [14, 15, 25, null];
        yield 'at warning' => [15, 15, 25, Severity::Warning];
        yield 'above warning, below error' => [20, 15, 25, Severity::Warning];
        yield 'at error' => [25, 15, 25, Severity::Error];
        yield 'above error' => [30, 15, 25, Severity::Error];
    }

    #[Test]
    public function itLoadsOptionsDefaultsFromArray(): void
    {
        $options = MethodCountOptions::fromArray(['enabled' => true]);

        self::assertTrue($options->isEnabled());
        self::assertSame(20, $options->warning);
        self::assertSame(30, $options->error);
    }

    #[Test]
    public function itLoadsOptionsCustomValuesFromArray(): void
    {
        $options = MethodCountOptions::fromArray([
            'enabled' => true,
            'warning' => 10,
            'error' => 20,
        ]);

        self::assertTrue($options->isEnabled());
        self::assertSame(10, $options->warning);
        self::assertSame(20, $options->error);
    }

    #[Test]
    public function itDisablesOptionsWhenLoadedFromEmptyArray(): void
    {
        $options = MethodCountOptions::fromArray([]);

        self::assertFalse($options->isEnabled());
    }
    #[Test]
    public function itProjectsDuplicateLogicalClassScoresToIndependentExactDeclarations(): void
    {
        $class = SymbolPath::forClass('App\\Service', 'Twin');
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')->willReturn([
            self::subjectInfo($class, RelativePath::fromString('src/A.php'), 100),
            self::subjectInfo($class, RelativePath::fromString('src/B.php'), 200),
        ]);
        $repository->method('get')->willReturn((new MetricBag())->with('methodCount', 15));

        $findings = (new MethodCountRule(new MethodCountOptions(warning: 10, error: 20)))
            ->analyze(new AnalysisContext($repository));

        self::assertCount(2, $findings);
        $subjects = array_map(static fn($finding): string => $finding->subject->toCanonical(), $findings);
        sort($subjects);
        self::assertSame([
            'declaration:class:App\\Service\\Twin@src/A.php',
            'declaration:class:App\\Service\\Twin@src/B.php',
        ], $subjects);
    }

    private static function subjectInfo(\Qualimetrix\Core\Symbol\SymbolPath $symbolPath, ?\Qualimetrix\Core\Path\RelativePath $file, ?int $line): \Qualimetrix\Core\Symbol\SymbolInfo
    {
        $type = $symbolPath->getType();
        if (\in_array($type, [\Qualimetrix\Core\Symbol\SymbolType::File, \Qualimetrix\Core\Symbol\SymbolType::Namespace_, \Qualimetrix\Core\Symbol\SymbolType::Project], true)) {
            return new \Qualimetrix\Core\Symbol\SymbolInfo(\Qualimetrix\Core\Symbol\MetricSubject::aggregate($symbolPath), $file, $line);
        }

        \assert($file !== null);
        $kind = $type === \Qualimetrix\Core\Symbol\SymbolType::Class_ ? null : ($type === \Qualimetrix\Core\Symbol\SymbolType::Function_ ? \Qualimetrix\Core\Symbol\CallableKind::Function : \Qualimetrix\Core\Symbol\CallableKind::Method);

        return new \Qualimetrix\Core\Symbol\SymbolInfo(
            \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of($symbolPath, $file, \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
            $file,
            $line,
            $kind,
        );
    }
}
