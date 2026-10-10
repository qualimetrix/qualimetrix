<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Maintainability;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;

use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Rule that checks Maintainability Index at callable level.
 *
 * MI thresholds (lower is worse):
 * - MI >= 40: good (no finding)
 * - MI 20-39: warning
 * - MI < 20: error
 *
 * Besides the published `maintainability.mi` value, also reads
 * `size.method-statement-count` to skip methods below `minStatements`.
 */
#[CliAlias('mi-warning', 'warning')]
#[CliAlias('mi-error', 'error')]
#[CliAlias('mi-exclude-tests', 'excludeTests')]
#[CliAlias('mi-min-statements', 'minStatements')]
final class MaintainabilityRule extends AbstractRule
{
    public const string NAME = 'maintainability.mi';
    public const string DOCS_PAGE = 'rules/maintainability.md';

    public const int REMEDIATION_MINUTES = 60;

    public const ChannelShape SHAPE = ChannelShape::Magnitude;
    public function getName(): string
    {
        return self::NAME;
    }

    public static function getDescription(): string
    {
        return 'Checks Maintainability Index (lower values indicate harder to maintain code)';
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
        $declaration = self::channelDeclarations()[self::NAME];

        foreach ($context->metrics->allCallables() as $methodInfo) {
            $subject = $methodInfo->subject ?? throw new LogicException('Maintainability findings require an exact callable subject');
            $metrics = null;
            $options = $this->getEffectiveOptions($context, $this->options, $subject);
            if (!$context->admit(self::NAME, new FindingChannel(self::NAME), SymbolLevel::Callable, PopulationIdentity::subject($subject, 'callable'), $declaration, (function () use ($context, $subject, $methodInfo, $options, &$metrics): iterable {
                yield GateInput::boundName('exclude-tests', $options->excludeTests ? !$this->isTestFile($methodInfo->file) : true, $options->excludeTests);
                $metrics = $context->metrics->getSubject($subject);
                yield GateInput::metrics('minimum-statements', $metrics, $options->minStatements);
                yield GateInput::metrics('maintainability', $metrics);
            })())) {
                continue;
            }
            $mi = $metrics->get(MetricName::MAINTAINABILITY_MI);

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
     * `maintainability.mi` reports the Maintainability Index itself
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
            self::NAME => self::judgingLower(
                [MetricName::MAINTAINABILITY_MI],
                SymbolLevel::Callable,
            )->withGates(
                self::populationGate('exclude-tests', self::NAME, SymbolLevel::Callable, 'callable', self::nameMatches('exclude-tests', true), 'The configured test-file exclusion applies.'),
                self::populationGate('minimum-statements', self::NAME, SymbolLevel::Callable, 'callable', self::keyThreshold('minimum-statements', [MetricName::SIZE_METHOD_STATEMENT_COUNT], '>=', 'minimum-statements', 'zero', true), 'Statement count is below the configured minimum.'),
                self::populationGate('maintainability', self::NAME, SymbolLevel::Callable, 'callable', self::keyPresent('maintainability', [MetricName::MAINTAINABILITY_MI]), 'Maintainability was not published.'),
            ),
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
