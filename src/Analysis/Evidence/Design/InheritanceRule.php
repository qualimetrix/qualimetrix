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
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Rule that checks DIT (Depth of Inheritance Tree) at class level.
 *
 * DIT measures how deep a class is in the inheritance hierarchy:
 * - Deep inheritance increases coupling and complexity
 * - Prefer composition over deep inheritance
 */
#[CliAlias('dit-warning', 'warning')]
#[CliAlias('dit-error', 'error')]
final class InheritanceRule extends AbstractRule
{
    public const string NAME = 'design.inheritance';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks Depth of Inheritance Tree (deep hierarchies increase complexity)';
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
        return [MetricName::STRUCTURE_DIT];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof InheritanceOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->allDeclarations() as $classInfo) {
            $subject = $classInfo->subject ?? throw new LogicException('Inheritance findings require an exact class declaration subject');
            if ($subject->toSymbolPath()->getType() !== SymbolType::Class_) {
                continue;
            }
            $metrics = $context->metrics->get($subject->toSymbolPath());
            $dit = $metrics->get(MetricName::STRUCTURE_DIT);

            if ($dit === null) {
                continue;
            }

            $ditValue = (int) $dit;
            /** @var InheritanceOptions $effectiveOptions */
            $effectiveOptions = $this->getEffectiveOptions($context, $this->options, $subject);
            $violation = $this->violationForClass($classInfo, $subject, $ditValue, $effectiveOptions);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    private function violationForClass(
        SymbolInfo $classInfo,
        MetricSubject $subject,
        int $ditValue,
        InheritanceOptions $options,
    ): ?Violation {
        if ($ditValue >= $options->error) {
            $severity = Severity::Error;
            $threshold = $options->error;
        } elseif ($ditValue >= $options->warning) {
            $severity = Severity::Warning;
            $threshold = $options->warning;
        } else {
            return null;
        }

        return new Violation(
            location: new Location($classInfo->file, $classInfo->line),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            violationCode: self::NAME,
            message: \sprintf(
                'DIT (Depth of Inheritance) is %d, exceeds threshold of %d. Prefer composition over deep inheritance',
                $ditValue,
                $threshold,
            ),
            severity: $severity,
            metricValue: $ditValue,
            recommendation: \sprintf('DIT: %d (threshold: %d) — deep inheritance, fragile hierarchy', $ditValue, $threshold),
            threshold: $threshold,
        );
    }

    /**
     * @return class-string<InheritanceOptions>
     */
    public static function getOptionsClass(): string
    {
        return InheritanceOptions::class;
    }

    /**
     * `design.inheritance` reports DIT (`$ditValue` — see the emission
     * above) as `metricValue`, judged worse the higher it goes:
     * {@see InheritanceOptions::getSeverity()}'s `$value >= $this->error`
     * (line 73) / `$value >= $this->warning` (line 77).
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            (new ViolationChannel(self::NAME, self::NAME))->toKey() => ChannelDeclaration::magnitude(WorseDirection::Higher),
        ];
    }
}
