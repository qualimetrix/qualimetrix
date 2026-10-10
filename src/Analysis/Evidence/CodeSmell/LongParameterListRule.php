<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;

use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\ThresholdCrossing;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Rule that checks number of parameters per method/function.
 *
 * Too many parameters indicate a method may need a parameter object
 * or the method is doing too much.
 *
 * Besides the published `code-smell.parameter-count` value, also reads
 * `code-smell.is-vo-constructor` to relax the threshold for value-object
 * constructors.
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

    public static function getDescription(): string
    {
        return 'Checks number of parameters per method';
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
     * $this->voError` / `$value >= $this->voWarning`
     * for the VO branch, and {@see LongParameterListOptions::getSeverity()}'s
     * `$value >= $this->error` / `$value >= $this->warning`
     * for the regular branch. One declaration covers both.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::NAME => self::judgingHigher(
                [MetricName::CODE_SMELL_PARAMETER_COUNT],
                SymbolLevel::Callable,
            )->withGates(
                self::populationGate('callable-coordinate', self::NAME, SymbolLevel::Callable, 'callable', self::kindIn('callable-coordinate', [SymbolType::Method, SymbolType::Function_]), 'The subject is outside the declared symbol coordinate.'),
                self::populationGate('published-value', self::NAME, SymbolLevel::Callable, 'callable', self::keyPresent('published-value', [MetricName::CODE_SMELL_PARAMETER_COUNT]), 'The rule metric was not published.'),
            ),
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
        $populationDeclaration = self::channelDeclarations()[self::NAME];

        foreach ($context->metrics->allCallables() as $symbolInfo) {
            $subject = $symbolInfo->subject ?? throw new LogicException('Long parameter list findings require an exact callable subject');
            $declaration = $subject->declarationPath() ?? throw new LogicException('Long parameter list findings require a declaration subject');
            $symbolType = $declaration->logical->getType();

            $metrics = $this->admittedMetrics($context, $subject, $populationDeclaration, static fn(MetricBag $metrics): array => [GateInput::metrics('published-value', $metrics)], [GateInput::kind('callable-coordinate', $declaration->logical->getType())], unit: 'callable', level: SymbolLevel::Callable);
            if ($metrics === null) {
                continue;
            }
            $parameterCount = $metrics->get(MetricName::CODE_SMELL_PARAMETER_COUNT);

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
        $kind = $symbolType === SymbolType::Function_ ? 'Function' : 'Method';
        return $this->thresholdFinding(
            $symbolInfo,
            $parameterCountValue,
            $effectiveOptions->getSeverity($parameterCountValue),
            ['warning' => $effectiveOptions->warning, 'error' => $effectiveOptions->error],
            static fn(int|float $threshold, ThresholdCrossing $crossing): array => [
                \sprintf('%s has %d parameters, %s threshold of %d. Consider introducing a parameter object', $kind, $parameterCountValue, $crossing->value, $threshold),
                \sprintf('Parameters: %d (threshold: %d) — consider introducing a parameter object', $parameterCountValue, $threshold),
            ],
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
        return $this->thresholdFinding(
            $symbolInfo,
            $parameterCount,
            $effectiveOptions->getVoSeverity($parameterCount),
            ['warning' => $effectiveOptions->voWarning, 'error' => $effectiveOptions->voError],
            static fn(int|float $threshold, ThresholdCrossing $crossing): array => [
                \sprintf('VO constructor has %d promoted parameters, %s threshold of %d. Consider splitting the value object', $parameterCount, $crossing->value, $threshold),
                \sprintf('Parameters: %d (VO threshold: %d) — consider splitting the value object', $parameterCount, $threshold),
            ],
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
