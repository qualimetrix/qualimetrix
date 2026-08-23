<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Rule that checks LCOM (Lack of Cohesion of Methods) at class level.
 *
 * LCOM measures how well methods in a class work together:
 * - LCOM = 1: all methods share at least one property (cohesive)
 * - LCOM > 1: class could potentially be split into multiple classes
 */
#[CliAlias('lcom-warning', 'warning')]
#[CliAlias('lcom-error', 'error')]
#[CliAlias('lcom-exclude-readonly', 'excludeReadonly')]
#[CliAlias('lcom-min-methods', 'minMethods')]
#[CliAlias('lcom-exclude-methods', 'excludeMethods')]
final class LcomRule extends AbstractRule
{
    public const string NAME = 'cohesion.lcom';
    public const string DOCS_PAGE = 'rules/cohesion.md';

    public const int REMEDIATION_MINUTES = 45;
    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks Lack of Cohesion of Methods (high values indicate class should be split)';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Cohesion;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [MetricName::STRUCTURE_LCOM, MetricName::STRUCTURE_METHOD_COUNT, MetricName::STRUCTURE_IS_READONLY];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof LcomOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->allDeclarations() as $classInfo) {
            $violation = $this->violationForClass($classInfo, $context, $this->options);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    private function violationForClass(SymbolInfo $classInfo, AnalysisContext $context, LcomOptions $options): ?Violation
    {
        $subject = $classInfo->subject ?? throw new LogicException('LCOM findings require an exact class declaration subject');
        if ($subject->toSymbolPath()->getType() !== SymbolType::Class_) {
            return null;
        }

        $metrics = $context->metrics->get($subject->toSymbolPath());
        $lcomValue = $this->eligibleLcom($metrics, $options);
        if ($lcomValue === null) {
            return null;
        }

        /** @var LcomOptions $effectiveOptions */
        $effectiveOptions = $this->getEffectiveOptions($context, $options, $subject);
        $severity = $effectiveOptions->getSeverity($lcomValue);
        if ($severity === null) {
            return null;
        }

        $threshold = $severity === Severity::Error ? $effectiveOptions->error : $effectiveOptions->warning;
        $message = \sprintf(
            'LCOM (Lack of Cohesion) is %d, exceeds threshold of %d. Class could be split into %d cohesive parts',
            $lcomValue,
            $threshold,
            $lcomValue,
        );
        $recommendation = \sprintf('LCOM4: %d (threshold: %d) — class has %d unrelated method groups', $lcomValue, $threshold, $lcomValue);
        $location = new Location($classInfo->file, $classInfo->line);
        $symbolPath = $subject->toSymbolPath();

        return new Violation(
            location: $location,
            subject: $subject,
            symbolPath: $symbolPath,
            ruleName: $this->getName(),
            violationCode: self::NAME,
            message: $message,
            severity: $severity,
            metricValue: $lcomValue,
            recommendation: $recommendation,
            threshold: $threshold,
        );
    }

    private function eligibleLcom(MetricBag $metrics, LcomOptions $options): ?int
    {
        if ($options->excludeReadonly && $metrics->get(MetricName::STRUCTURE_IS_READONLY) === 1) {
            return null;
        }
        $methodCount = (int) ($metrics->get(MetricName::STRUCTURE_METHOD_COUNT) ?? 0);
        if ($methodCount < $options->minMethods) {
            return null;
        }
        $lcom = $metrics->get(MetricName::STRUCTURE_LCOM);

        return $lcom !== null ? (int) $lcom : null;
    }

    /**
     * @return class-string<LcomOptions>
     */
    public static function getOptionsClass(): string
    {
        return LcomOptions::class;
    }

    /**
     * `cohesion.lcom` reports LCOM4 (`$lcomValue` — see the emission above)
     * as `metricValue`, judged worse the higher it goes:
     * {@see LcomOptions::getSeverity()}'s `$value >= $this->error` (line 94)
     * / `$value >= $this->warning` (line 98).
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            (new ViolationChannel(self::NAME, self::NAME))->toKey() => ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Class_),
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
