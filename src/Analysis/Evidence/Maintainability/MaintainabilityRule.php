<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Maintainability;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;

/**
 * Rule that checks Maintainability Index at method level.
 *
 * MI thresholds (lower is worse):
 * - MI >= 40: good (no finding)
 * - MI 20-39: warning
 * - MI < 20: error
 */
#[CliAlias('mi-warning', 'warning')]
#[CliAlias('mi-error', 'error')]
#[CliAlias('mi-exclude-tests', 'excludeTests')]
#[CliAlias('mi-min-statements', 'minStatements')]
final class MaintainabilityRule extends AbstractRule
{
    public const string NAME = 'maintainability.index';
    public const string DOCS_PAGE = 'rules/maintainability.md';

    public const int REMEDIATION_MINUTES = 60;

    public const ChannelShape SHAPE = ChannelShape::Magnitude;
    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks Maintainability Index (lower values indicate harder to maintain code)';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Maintainability;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [MetricName::MAINTAINABILITY_MI, MetricName::SIZE_METHOD_STATEMENT_COUNT];
    }

    /**
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof MaintainabilityOptions || !$this->options->isEnabled()) {
            return [];
        }

        $findings = [];

        foreach ($context->metrics->allCallables() as $methodInfo) {
            $subject = $methodInfo->subject ?? throw new LogicException('Maintainability findings require an exact callable subject');
            // Skip test files if configured
            if ($this->options->excludeTests && $this->isTestFile($methodInfo->file)) {
                continue;
            }

            $metrics = $context->metrics->getSubject($subject);

            // Skip methods with too few statements.
            $statementCount = (int) ($metrics->get(MetricName::SIZE_METHOD_STATEMENT_COUNT) ?? 0);
            if ($statementCount < $this->options->minStatements) {
                continue;
            }

            $mi = $metrics->get(MetricName::MAINTAINABILITY_MI);

            if ($mi === null) {
                continue;
            }

            $miValue = (float) $mi;
            /** @var MaintainabilityOptions $effectiveOptions */
            $effectiveOptions = $this->getEffectiveOptions($context, $this->options, $subject);
            $finding = $this->findingForMetric($methodInfo, $subject, $miValue, $effectiveOptions);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function findingForMetric(
        SymbolInfo $methodInfo,
        MetricSubject $subject,
        float $miValue,
        MaintainabilityOptions $options,
    ): ?Finding {
        $severity = $options->getSeverity($miValue);
        if ($severity === null) {
            return null;
        }

        $threshold = $severity === Severity::Error ? $options->error : $options->warning;

        return new Finding(
            location: new Location($methodInfo->file, $methodInfo->line),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            code: self::NAME,
            message: \sprintf(
                'Maintainability Index is %.1f, below threshold of %.1f. Reduce complexity and size to improve maintainability',
                $miValue,
                $threshold,
            ),
            severity: $severity,
            metricValue: round($miValue, 1),
            recommendation: \sprintf('MI: %.1f (threshold: %.1f) — code is hard to change safely', $miValue, $threshold),
            threshold: $threshold,
        );
    }

    /**
     * @return class-string<MaintainabilityOptions>
     */
    public static function getOptionsClass(): string
    {
        return MaintainabilityOptions::class;
    }

    /**
     * `maintainability.index` reports the Maintainability Index itself
     * (`round($miValue, 1)`) as `metricValue` — see the emission above —
     * and is judged worse the lower it goes, per
     * `MaintainabilityOptions::getSeverity()`'s `$value < $this->error` /
     * `$value < $this->warning` comparisons (strict `<`, intentionally: the
     * threshold is the first acceptable value for the better category).
     *
     * Keyed by the channel's own name — the whole name
     * equal `self::NAME` here.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::NAME => ChannelDeclaration::magnitude(WorseDirection::Lower, SymbolLevel::Callable),
        ];
    }

    private function isTestFile(?RelativePath $file): bool
    {
        if ($file === null) {
            return false;
        }

        $value = $file->value();

        return str_ends_with($value, 'Test.php')
            || str_starts_with($value, 'tests/')
            || str_starts_with($value, 'Tests/')
            || str_contains($value, '/tests/')
            || str_contains($value, '/Tests/');
    }

    /**
     * Declared, never inferred from the options class: `@qmx-threshold` can
     * retune this rule. See
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOverrideSupportReader},
     * which also explains why this is a constant and why it is declared last.
     */
    public const bool SUPPORTS_THRESHOLD_OVERRIDE = true;
}
