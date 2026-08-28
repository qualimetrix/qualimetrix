<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
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
    public const string DOCS_PAGE = 'rules/design.md';

    public const int REMEDIATION_MINUTES = 20;

    public const ChannelShape SHAPE = ChannelShape::Magnitude;
    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks Number of Children (many direct subclasses indicate wide impact)';
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [MetricName::DESIGN_NOC];
    }

    /**
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof NocOptions || !$this->options->isEnabled()) {
            return [];
        }

        $findings = [];

        foreach ($context->metrics->allDeclarations() as $classInfo) {
            $finding = $this->findingForClass($classInfo, $context, $this->options);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function findingForClass(SymbolInfo $classInfo, AnalysisContext $context, NocOptions $options): ?Finding
    {
        $subject = $classInfo->subject ?? throw new LogicException('NOC findings require an exact class declaration subject');
        if ($subject->toSymbolPath()->getType() !== SymbolType::Class_) {
            return null;
        }

        $noc = $context->metrics->get($subject->toSymbolPath())->get(MetricName::DESIGN_NOC);
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

        return new Finding(
            location: new Location($classInfo->file, $classInfo->line),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            code: self::NAME,
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
            self::NAME => ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Class_),
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
