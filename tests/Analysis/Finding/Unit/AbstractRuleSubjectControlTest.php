<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Finding\Rule\Override\StandardOverrideValidator;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

final class AbstractRuleSubjectControlTest extends TestCase
{
    #[Test]
    public function itPassesTheMetricSubjectToTheThresholdResolutionSeam(): void
    {
        $subject = MetricSubject::declaration(DeclarationPath::of(SymbolPath::forMethod('App', 'Foo', 'run'), RelativePath::fromString('src/Foo.php'), DeclarationOrdinal::fromRank(0)));
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

    public const ChannelShape SHAPE = ChannelShape::Magnitude;

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
