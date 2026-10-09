<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\GodClass;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Population\FlagExcludes;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;
use Qualimetrix\Analysis\Finding\Contract\Population\KeyThreshold;
use Qualimetrix\Analysis\Finding\Contract\Population\KindIn;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationGate;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\Population\RuleValueThreshold;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Rule that detects God Classes using Lanza & Marinescu criteria.
 *
 * A God Class is overly complex, large, and lacks cohesion.
 * Detection is based on 4 criteria: WMC, LCOM4, TCC, and class LOC.
 * A class is flagged when it matches minCriteria of the evaluable criteria.
 *
 * Reads `complexity.wmc`, `cohesion.lcom`, `cohesion.tcc`, `size.class-loc`
 * as the four criteria, `size.method-count` for the minMembers gate, and
 * `design.is-readonly` as an exclusion gate; none of these are published —
 * the channel's own value is the matched-criteria count.
 */
#[CliAlias('god-class-wmc-threshold', 'wmcThreshold')]
#[CliAlias('god-class-lcom-threshold', 'lcomThreshold')]
#[CliAlias('god-class-tcc-threshold', 'tccThreshold')]
#[CliAlias('god-class-class-loc-threshold', 'classLocThreshold')]
#[CliAlias('god-class-min-criteria', 'minCriteria')]
#[CliAlias('god-class-min-methods', 'minMethods')]
#[CliAlias('god-class-exclude-readonly', 'excludeReadonly')]
final class GodClassRule extends AbstractRule
{
    public const string NAME = 'design.god-class';
    public const string DOCS_PAGE = 'rules/design.md';

    public const int REMEDIATION_MINUTES = 120;

    public const ChannelShape SHAPE = ChannelShape::Magnitude;
    public function getName(): string
    {
        return self::NAME;
    }

    public static function getDescription(): string
    {
        return 'Detects God Classes (overly complex, large, low cohesion)';
    }

    /**
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof GodClassOptions || !$this->options->isEnabled()) {
            return [];
        }

        $declaration = self::channelDeclarations()[self::NAME];
        $findings = [];

        foreach ($context->metrics->allClassDeclarations() as $classInfo) {
            $finding = $this->evaluateClass($context, $classInfo, $declaration);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function evaluateClass(AnalysisContext $context, SymbolInfo $classInfo, ChannelDeclaration $declaration): ?Finding
    {
        $subject = $classInfo->subject;
        if ($subject === null) {
            return null;
        }
        $effectiveOptions = null;
        $results = [];
        $inputs = (function () use ($subject, $context, &$effectiveOptions, &$results): iterable {
            yield GateInput::kind('logicalKind', $subject->toSymbolPath()->getType());
            $metrics = $context->metrics->getSubject($subject);
            $effectiveOptions = $this->getEffectiveOptions($context, $this->options, $subject);
            \assert($effectiveOptions instanceof GodClassOptions);
            yield GateInput::metrics('excludeReadonly', $metrics, option: $effectiveOptions->excludeReadonly);
            yield GateInput::metrics('minMethods', $metrics, $effectiveOptions->minMethods);
            $results = GodClassCriteriaEvaluator::evaluate($metrics, $effectiveOptions);
            yield GateInput::ruleNumber('minCriteria', \count($results), $effectiveOptions->minCriteria);
        })();
        if (!$context->admit(self::NAME, new FindingChannel(self::NAME), SymbolLevel::Class_, PopulationIdentity::subject($subject), $declaration, $inputs)) {
            return null;
        }
        $evaluableCount = \count($results);

        $matched = array_values(array_filter(
            $results,
            static fn(GodClassCriterionResult $result): bool => $result->matched,
        ));
        $matchedCount = \count($matched);

        $severity = $this->determineSeverity($matchedCount, $evaluableCount, $effectiveOptions);
        if ($severity === null) {
            return null;
        }

        return new Finding(
            location: new Location($classInfo->file, $classInfo->line),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            code: self::NAME,
            message: \sprintf(
                'God Class detected (%d/%d criteria): %s',
                $matchedCount,
                $evaluableCount,
                implode(', ', array_map(
                    static fn(GodClassCriterionResult $result): string => $result->message,
                    $matched,
                )),
            ),
            severity: $severity,
            metricValue: $matchedCount,
            recommendation: 'Apply the Single Responsibility Principle. Extract cohesive method groups into separate classes.',
        );
    }

    /**
     * Error when every evaluable criterion matched, Warning when at least
     * minCriteria matched, null (no finding) otherwise.
     */
    private function determineSeverity(int $matchedCount, int $evaluableCount, GodClassOptions $options): ?Severity
    {
        if ($matchedCount === $evaluableCount) {
            return Severity::Error;
        }

        if ($matchedCount >= $options->minCriteria) {
            return Severity::Warning;
        }

        return null;
    }

    /**
     * @return class-string<GodClassOptions>
     */
    public static function getOptionsClass(): string
    {
        return GodClassOptions::class;
    }

    /**
     * `design.god-class` reports `$matchedCount` — the tally of how many of
     * the (up to 4) evaluable God Class criteria matched — as `metricValue`
     * (see the emission above), not any individual criterion's value. Higher
     * is worse: {@see determineSeverity()} returns `Severity::Error` when
     * `$matchedCount === $evaluableCount` (all evaluable criteria
     * matched) and `Severity::Warning` when `$matchedCount >=
     * $options->minCriteria` — both branches escalate as the
     * count grows.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::NAME => ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Class_)->withGates(
                new PopulationGate('logical-class-kind', new FindingChannel(self::NAME), SymbolLevel::Class_, 'declaration', new KindIn('logicalKind', [SymbolType::Class_]), 'Only class declarations are judged.'),
                new PopulationGate('readonly-class', new FindingChannel(self::NAME), SymbolLevel::Class_, 'declaration', new FlagExcludes('excludeReadonly', MetricName::DESIGN_IS_READONLY, activeWhen: true), 'Readonly classes are excluded by configuration.'),
                new PopulationGate('method-floor', new FindingChannel(self::NAME), SymbolLevel::Class_, 'declaration', new KeyThreshold('minMethods', [MetricName::SIZE_METHOD_COUNT], '>=', 'minMethods', missing: 'zero'), 'The class has too few methods for god-class judgement.'),
                new PopulationGate('evaluable-criteria', new FindingChannel(self::NAME), SymbolLevel::Class_, 'declaration', new RuleValueThreshold('minCriteria', '>=', 'minCriteria'), 'Too few god-class criteria can be evaluated.'),
            ),
        ];
    }

    /**
     * Declared, never inferred from the options class: `@qmx-threshold` can
     * retune this rule. See
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOverrideSupportReader},
     * which also explains why this is a constant and why it is declared last.
     */
    public const bool SUPPORTS_THRESHOLD_OVERRIDE = true;
}
