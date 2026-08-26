<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

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
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Rule that checks number of parameters per method/function.
 *
 * Too many parameters indicate a method may need a parameter object
 * or the method is doing too much.
 *
 * @qmx-ignore health.cohesion -- Interface metadata methods such as requires() return external metric constants beside one cohesive analysis/projection component; LCOM4 cannot merge those stateless protocol methods.
 */
#[CliAlias('long-parameter-list-warning', 'warning')]
#[CliAlias('long-parameter-list-error', 'error')]
#[CliAlias('long-parameter-list-vo-warning', 'vo-warning')]
#[CliAlias('long-parameter-list-vo-error', 'vo-error')]
final class LongParameterListRule extends AbstractRule
{
    public const string NAME = 'code-smell.long-parameter-list';
    public const string DOCS_PAGE = 'rules/code-smell.md';

    public const int REMEDIATION_MINUTES = 20;

    public const ChannelShape SHAPE = ChannelShape::Magnitude;
    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks number of parameters per method';
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
            self::NAME => ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Callable),
        ];
    }

    /**
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof LongParameterListOptions || !$this->options->isEnabled()) {
            return [];
        }

        return $this->analyzeEnabledSymbols($context);
    }

    /**
     * @return list<Finding>
     */
    private function analyzeEnabledSymbols(AnalysisContext $context): array
    {
        \assert($this->options instanceof LongParameterListOptions);
        $options = $this->options;
        $findings = [];

        foreach ($context->metrics->allCallables() as $symbolInfo) {
            $subject = $symbolInfo->subject ?? throw new LogicException('Long parameter list findings require an exact callable subject');
            $declaration = $subject->declarationPath() ?? throw new LogicException('Long parameter list findings require a declaration subject');
            $symbolType = $declaration->logical->getType();

            if ($symbolType !== SymbolType::Method && $symbolType !== SymbolType::Function_) {
                continue;
            }

            $metrics = $context->metrics->getSubject($subject);
            $parameterCount = $metrics->get(MetricName::CODE_SMELL_PARAMETER_COUNT);
            if ($parameterCount === null) {
                continue;
            }

            $parameterCountValue = (int) $parameterCount;
            $isVoConstructor = $metrics->get(MetricName::CODE_SMELL_IS_VO_CONSTRUCTOR) === 1;
            $finding = $isVoConstructor
                ? $this->checkVoConstructor($symbolInfo, $subject, $parameterCountValue, $context, $options)
                : $this->checkSymbol($symbolInfo, $subject, $parameterCountValue, $symbolType, $context, $options);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function checkSymbol(
        SymbolInfo $symbolInfo,
        MetricSubject $subject,
        int $parameterCountValue,
        SymbolType $symbolType,
        AnalysisContext $context,
        LongParameterListOptions $options,
    ): ?Finding {
        /** @var LongParameterListOptions $effectiveOptions */
        $effectiveOptions = $this->getEffectiveOptions($context, $options, $subject);
        $severity = $effectiveOptions->getSeverity($parameterCountValue);

        if ($severity === null) {
            return null;
        }

        $threshold = $severity === Severity::Error ? $effectiveOptions->error : $effectiveOptions->warning;
        $kind = $symbolType === SymbolType::Function_ ? 'Function' : 'Method';

        return new Finding(
            location: new Location($symbolInfo->file, $symbolInfo->line),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            code: self::NAME,
            message: \sprintf('%s has %d parameters, exceeds threshold of %d. Consider introducing a parameter object', $kind, $parameterCountValue, $threshold),
            severity: $severity,
            metricValue: $parameterCountValue,
            recommendation: \sprintf('Parameters: %d (threshold: %d) — consider introducing a parameter object', $parameterCountValue, $threshold),
            threshold: $threshold,
        );
    }

    private function checkVoConstructor(
        SymbolInfo $symbolInfo,
        MetricSubject $subject,
        int $parameterCount,
        AnalysisContext $context,
        LongParameterListOptions $options,
    ): ?Finding {
        $override = $context->getThresholdOverride($this->getName(), $subject);
        $effectiveOptions = $override === null
            ? $options
            : $options->withVoOverride($override->warning, $override->error);
        $severity = $effectiveOptions->getVoSeverity($parameterCount);

        if ($severity === null) {
            return null;
        }

        $threshold = $severity === Severity::Error ? $effectiveOptions->voError : $effectiveOptions->voWarning;

        return new Finding(
            location: new Location($symbolInfo->file, $symbolInfo->line),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            code: self::NAME,
            message: \sprintf('VO constructor has %d promoted parameters, exceeds threshold of %d. Consider splitting the value object', $parameterCount, $threshold),
            severity: $severity,
            metricValue: $parameterCount,
            recommendation: \sprintf('Parameters: %d (VO threshold: %d) — consider splitting the value object', $parameterCount, $threshold),
            threshold: $threshold,
        );
    }

    /**
     * Declared, never inferred from the options class: `@qmx-threshold` can
     * retune this rule. See
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOverrideSupportReader},
     * which also explains why this is a constant and why it is declared last.
     */
    public const bool SUPPORTS_THRESHOLD_OVERRIDE = true;
}
