<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\Inheritance;

use LogicException;
use Psr\Log\LoggerInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\JudgedMetrics;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\ThresholdCrossing;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
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
    public const string NAME = 'design.dit';
    public const string DOCS_PAGE = 'rules/design.md';

    public const int REMEDIATION_MINUTES = 30;

    public const ChannelShape SHAPE = ChannelShape::Magnitude;
    public function __construct(RuleOptionsInterface $options, private readonly ?LoggerInterface $logger = null)
    {
        parent::__construct($options);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public static function getDescription(): string
    {
        return 'Checks Depth of Inheritance Tree (deep hierarchies increase complexity)';
    }

    /**
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof InheritanceOptions || !$this->options->isEnabled()) {
            return [];
        }

        $findings = [];
        $outcomes = [InheritanceOutcome::Exact->name => 0, InheritanceOutcome::Floor->name => 0, InheritanceOutcome::Loop->name => 0];
        foreach ($context->metrics->allDeclarations() as $classInfo) {
            $subject = $classInfo->subject ?? throw new LogicException('Inheritance findings require an exact class declaration subject');
            [$finding, $outcome] = $this->analyzeDeclaration($subject, new Location($classInfo->file, $classInfo->line), $context, $this->options);
            ++$outcomes[$outcome->name];
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }
        $this->warnAboutIncompleteChains($outcomes[InheritanceOutcome::Floor->name], $outcomes[InheritanceOutcome::Loop->name]);

        return $findings;
    }

    /** @return array{?Finding, InheritanceOutcome} */
    private function analyzeDeclaration(MetricSubject $subject, Location $location, AnalysisContext $context, InheritanceOptions $options): array
    {
        if ($subject->toSymbolPath()->getType() !== SymbolType::Class_) {
            return [null, InheritanceOutcome::Exact];
        }
        // One logical name can have different parents in different bodies.
        $metrics = $context->metrics->getSubject($subject);
        $dit = $metrics->get(MetricName::DESIGN_DIT);
        $outcome = $this->publishedOutcome($dit, $metrics->get(MetricName::DESIGN_DIT_UNRESOLVED));
        if ($dit === null) {
            return [null, $outcome];
        }
        /** @var InheritanceOptions $effectiveOptions */
        $effectiveOptions = $this->getEffectiveOptions($context, $options, $subject);

        return [$this->findingForClass($location, $subject, (int) $dit, $effectiveOptions, $outcome), $outcome];
    }

    private function publishedOutcome(int|float|null $dit, int|float|null $unresolved): InheritanceOutcome
    {
        if ($unresolved !== 1) {
            return InheritanceOutcome::Exact;
        }

        return $dit === null ? InheritanceOutcome::Loop : InheritanceOutcome::Floor;
    }

    private function warnAboutIncompleteChains(int $floors, int $loops): void
    {
        $parts = [];
        if ($floors !== 0) {
            $parts[] = \sprintf('%d incomplete inheritance chain(s) publish DIT lower bounds', $floors);
        }
        if ($loops !== 0) {
            $parts[] = \sprintf('%d cyclic inheritance chain(s) have no numeric DIT', $loops);
        }
        if ($parts !== []) {
            $this->logger?->warning('DIT: ' . implode('; ', $parts) . '.');
        }
    }

    private function findingForClass(
        Location $location,
        MetricSubject $subject,
        int $ditValue,
        InheritanceOptions $options,
        InheritanceOutcome $outcome,
    ): ?Finding {
        if ($ditValue >= $options->error) {
            $severity = Severity::Error;
            $threshold = $options->error;
        } elseif ($ditValue >= $options->warning) {
            $severity = Severity::Warning;
            $threshold = $options->warning;
        } else {
            return null;
        }

        return new Finding(
            location: $location,
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            code: self::NAME,
            message: \sprintf(
                ($outcome === InheritanceOutcome::Floor ? 'DIT is at least %d, ' : 'DIT (Depth of Inheritance) is %d, ') . ThresholdCrossing::of($ditValue, $threshold)->value . ' threshold of %d. Prefer composition over deep inheritance',
                $ditValue,
                $threshold,
            ),
            severity: $severity,
            metricValue: $ditValue,
            recommendation: \sprintf($outcome === InheritanceOutcome::Floor ? 'DIT is at least %d (threshold: %d) — deep inheritance, fragile hierarchy' : 'DIT: %d (threshold: %d) — deep inheritance, fragile hierarchy', $ditValue, $threshold),
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
     * `design.dit` reports DIT (`$ditValue` — see the emission
     * above) as `metricValue`, judged worse the higher it goes:
     * {@see InheritanceOptions::getSeverity()}'s `$value >= $this->error`
     * / `$value >= $this->warning`.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::NAME => ChannelDeclaration::judging(
                WorseDirection::Higher,
                JudgedMetrics::of(MetricName::DESIGN_DIT),
                SymbolLevel::Class_,
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
