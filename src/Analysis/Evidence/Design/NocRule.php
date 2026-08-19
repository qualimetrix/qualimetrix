<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
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
 * Rule that checks NOC (Number of Children) at class level.
 *
 * NOC measures how many classes directly extend (inherit from) a given class.
 * High NOC indicates:
 * - Wide reuse through inheritance
 * - High impact of changes (affects many subclasses)
 * - Potential need for interface instead of class inheritance
 * - Possible violation of Liskov Substitution Principle
 */
#[CliAlias('noc-warning', 'warning')]
#[CliAlias('noc-error', 'error')]
final class NocRule extends AbstractRule
{
    public const string NAME = 'design.noc';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks Number of Children (many direct subclasses indicate wide impact)';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Design;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [MetricName::STRUCTURE_NOC];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof NocOptions || !$this->options->isEnabled()) {
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

    private function violationForClass(SymbolInfo $classInfo, AnalysisContext $context, NocOptions $options): ?Violation
    {
        $subject = $classInfo->subject ?? throw new LogicException('NOC findings require an exact class declaration subject');
        if ($subject->toSymbolPath()->getType() !== SymbolType::Class_) {
            return null;
        }

        $noc = $context->metrics->get($subject->toSymbolPath())->get(MetricName::STRUCTURE_NOC);
        if ($noc === null || $noc === 0) {
            return null;
        }

        $nocValue = (int) $noc;
        /** @var NocOptions $effectiveOptions */
        $effectiveOptions = $this->getEffectiveOptions($context, $options, $subject);
        $severity = $effectiveOptions->getSeverity($nocValue);
        if ($severity === null) {
            return null;
        }

        $threshold = $severity === Severity::Error ? $effectiveOptions->error : $effectiveOptions->warning;

        return new Violation(
            location: new Location($classInfo->file, $classInfo->line),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            violationCode: self::NAME,
            message: \sprintf(
                'NOC (Number of Children) is %d, exceeds threshold of %d. Consider using interfaces instead of inheritance',
                $nocValue,
                $threshold,
            ),
            severity: $severity,
            metricValue: $nocValue,
            recommendation: \sprintf('NOC: %d (threshold: %d) — too many direct subclasses', $nocValue, $threshold),
            threshold: $threshold,
        );
    }

    /**
     * @return class-string<NocOptions>
     */
    public static function getOptionsClass(): string
    {
        return NocOptions::class;
    }

    /**
     * `design.noc` reports NOC (`$nocValue` — see the emission above) as
     * `metricValue`, judged worse the higher it goes:
     * {@see NocOptions::getSeverity()}'s `$value >= $this->error` (line 76)
     * / `$value >= $this->warning` (line 80).
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
     * Declared, never inferred from the options class: `@qmx-threshold` can
     * retune this rule. See
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOverrideSupportReader},
     * which also explains why this is a constant and why it is declared last.
     */
    public const bool SUPPORTS_THRESHOLD_OVERRIDE = true;
}
