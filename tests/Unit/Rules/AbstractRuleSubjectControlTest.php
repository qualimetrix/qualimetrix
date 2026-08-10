<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\Override\OverrideValidatorInterface;
use Qualimetrix\Core\Rule\Override\StandardOverrideValidator;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Rule\RuleOptionsInterface;
use Qualimetrix\Core\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Core\Suppression\ControlScope;
use Qualimetrix\Core\Suppression\ThresholdOverride;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\AbstractRule;

final class AbstractRuleSubjectControlTest extends TestCase
{
    #[Test]
    public function itPassesTheMetricSubjectToTheThresholdResolutionSeam(): void
    {
        $subject = MetricSubject::declaration(new DeclarationPath(
            SymbolPath::forMethod('App', 'Foo', 'run'),
            RelativePath::fromString('src/Foo.php'),
            100,
        ));
        $override = new ThresholdOverride('test.subject-control', 50, 60, 10, $subject, ControlScope::Callable, 20);
        $context = new AnalysisContext(
            self::createStub(MetricRepositoryInterface::class),
            thresholdOverrides: ['src/Foo.php' => [$override]],
        );
        $rule = new SubjectControlHarness(new SubjectControlOptions(10, 20));

        self::assertNull($rule->severityFor($context, $subject, 30));
        self::assertSame(Severity::Warning, $rule->severityFor($context, $subject, 55));
    }
}

final class SubjectControlHarness extends AbstractRule
{
    public static function getOptionsClass(): string
    {
        return SubjectControlOptions::class;
    }

    public function getName(): string
    {
        return 'test.subject-control';
    }

    public function getDescription(): string
    {
        return 'Test harness';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Complexity;
    }

    public function requires(): array
    {
        return [];
    }

    public function analyze(AnalysisContext $context): array
    {
        return [];
    }

    public function severityFor(AnalysisContext $context, MetricSubject $subject, int $value): ?Severity
    {
        return $this->getEffectiveSeverity($context, $this->options, $subject, $value);
    }
}

final readonly class SubjectControlOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface
{
    public function __construct(private int $warning, private int $error) {}

    public static function fromArray(array $config): self
    {
        return new self(10, 20);
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        return $value >= $this->error ? Severity::Error : ($value >= $this->warning ? Severity::Warning : null);
    }

    public function withOverride(int|float|null $warning, int|float|null $error): static
    {
        return new self((int) ($warning ?? $this->warning), (int) ($error ?? $this->error));
    }

    public static function getOverrideValidator(): OverrideValidatorInterface
    {
        return StandardOverrideValidator::instance();
    }
}
