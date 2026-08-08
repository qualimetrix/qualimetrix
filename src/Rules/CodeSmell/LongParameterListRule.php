<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\Attribute\CliAlias;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\ChannelDeclaration;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Core\Violation\ViolationChannel;
use Qualimetrix\Rules\AbstractRule;

/**
 * Rule that checks number of parameters per method/function.
 *
 * Too many parameters indicate a method may need a parameter object
 * or the method is doing too much.
 */
#[CliAlias('long-parameter-list-warning', 'warning')]
#[CliAlias('long-parameter-list-error', 'error')]
#[CliAlias('long-parameter-list-vo-warning', 'vo-warning')]
#[CliAlias('long-parameter-list-vo-error', 'vo-error')]
final class LongParameterListRule extends AbstractRule
{
    public const string NAME = 'code-smell.long-parameter-list';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks number of parameters per method';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::CodeSmell;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [
            MetricName::CODE_SMELL_PARAMETER_COUNT,
            MetricName::CODE_SMELL_IS_VO_CONSTRUCTOR,
        ];
    }

    /**
     * @return class-string<LongParameterListOptions>
     */
    public static function getOptionsClass(): string
    {
        return LongParameterListOptions::class;
    }

    /**
     * `code-smell.long-parameter-list` has two emission call sites
     * (the VO-constructor branch and the regular branch — see
     * {@see checkSymbol()}) that resolve to the same literal channel key and
     * report the same magnitude (`$parameterCountValue`), differing only in
     * which threshold pair gates them. Both are `higher`-is-worse:
     * {@see LongParameterListOptions::getVoSeverity()}'s `$value >=
     * $this->voError` (line 110) / `$value >= $this->voWarning` (line 114)
     * for the VO branch, and {@see LongParameterListOptions::getSeverity()}'s
     * `$value >= $this->error` (line 94) / `$value >= $this->warning`
     * (line 98) for the regular branch. One declaration covers both.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            (new ViolationChannel(self::NAME, self::NAME))->toKey() => ChannelDeclaration::magnitude(WorseDirection::Higher),
        ];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof LongParameterListOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ([SymbolType::Method, SymbolType::Function_] as $type) {
            foreach ($context->metrics->all($type) as $symbolInfo) {
                $violation = $this->checkSymbol($symbolInfo, $type, $context);

                if ($violation !== null) {
                    $violations[] = $violation;
                }
            }
        }

        return $violations;
    }

    private function checkSymbol(SymbolInfo $symbolInfo, SymbolType $symbolType, AnalysisContext $context): ?Violation
    {
        /** @var LongParameterListOptions $options */
        $options = $this->options;

        $metrics = $context->metrics->get($symbolInfo->symbolPath);
        $parameterCount = $metrics->get(MetricName::CODE_SMELL_PARAMETER_COUNT);

        if ($parameterCount === null) {
            return null;
        }

        $parameterCountValue = (int) $parameterCount;
        $isVoConstructor = $metrics->get(MetricName::CODE_SMELL_IS_VO_CONSTRUCTOR) === 1;

        // VO constructors use relaxed thresholds since many promoted properties is valid design
        if ($isVoConstructor) {
            return $this->checkVoConstructor($symbolInfo, $parameterCountValue, $context, $options);
        }

        /** @var LongParameterListOptions $effectiveOptions */
        $effectiveOptions = $this->getEffectiveOptions($context, $options, $symbolInfo->file, $symbolInfo->line ?? 1);
        $severity = $effectiveOptions->getSeverity($parameterCountValue);

        if ($severity === null) {
            return null;
        }

        $threshold = $severity === Severity::Error ? $effectiveOptions->error : $effectiveOptions->warning;
        $kind = $symbolType === SymbolType::Function_ ? 'Function' : 'Method';

        return new Violation(
            location: new Location($symbolInfo->file, $symbolInfo->line),
            symbolPath: $symbolInfo->symbolPath,
            ruleName: $this->getName(),
            violationCode: self::NAME,
            message: \sprintf('%s has %d parameters, exceeds threshold of %d. Consider introducing a parameter object', $kind, $parameterCountValue, $threshold),
            severity: $severity,
            metricValue: $parameterCountValue,
            recommendation: \sprintf('Parameters: %d (threshold: %d) — consider introducing a parameter object', $parameterCountValue, $threshold),
            threshold: $threshold,
        );
    }

    private function checkVoConstructor(
        SymbolInfo $symbolInfo,
        int $parameterCount,
        AnalysisContext $context,
        LongParameterListOptions $options,
    ): ?Violation {
        $override = $context->getThresholdOverride($this->getName(), $symbolInfo->file, $symbolInfo->line ?? 1);
        $effectiveOptions = $override === null
            ? $options
            : $options->withVoOverride($override->warning, $override->error);
        $severity = $effectiveOptions->getVoSeverity($parameterCount);

        if ($severity === null) {
            return null;
        }

        $threshold = $severity === Severity::Error ? $effectiveOptions->voError : $effectiveOptions->voWarning;

        return new Violation(
            location: new Location($symbolInfo->file, $symbolInfo->line),
            symbolPath: $symbolInfo->symbolPath,
            ruleName: $this->getName(),
            violationCode: self::NAME,
            message: \sprintf('VO constructor has %d promoted parameters, exceeds threshold of %d. Consider splitting the value object', $parameterCount, $threshold),
            severity: $severity,
            metricValue: $parameterCount,
            recommendation: \sprintf('Parameters: %d (VO threshold: %d) — consider splitting the value object', $parameterCount, $threshold),
            threshold: $threshold,
        );
    }
}
