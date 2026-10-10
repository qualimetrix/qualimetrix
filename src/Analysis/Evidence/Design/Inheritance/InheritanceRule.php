<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\Inheritance;

use LogicException;
use Psr\Log\LoggerInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\ThresholdCrossing;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
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

        $declaration = self::channelDeclarations()[self::NAME];
        $findings = [];
        $obstructions = [];
        $outcomes = [InheritanceOutcome::Exact->name => 0, InheritanceOutcome::Floor->name => 0, InheritanceOutcome::Loop->name => 0];
        foreach ($context->metrics->allDeclarations() as $classInfo) {
            $subject = $classInfo->subject ?? throw new LogicException('Inheritance findings require an exact class declaration subject');
            [$finding, $outcome, $diagnostics] = $this->analyzeDeclaration($subject, $classInfo, $context, $this->options, $declaration);
            ++$outcomes[$outcome->name];
            foreach ($diagnostics as $diagnostic) {
                $obstructions[$diagnostic['cause'] . "\0" . $diagnostic['name']] = $diagnostic;
            }
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }
        $this->warnAboutIncompleteChains($outcomes[InheritanceOutcome::Floor->name], $outcomes[InheritanceOutcome::Loop->name], $obstructions);

        return $findings;
    }

    /** @return array{?Finding, InheritanceOutcome, list<array<string, scalar>>} */
    private function analyzeDeclaration(MetricSubject $subject, SymbolInfo $info, AnalysisContext $context, InheritanceOptions $options, ChannelDeclaration $declaration): array
    {
        $dit = null;
        $outcome = InheritanceOutcome::Exact;
        $diagnostics = [];
        $metrics = $this->admittedMetrics(
            $context,
            $subject,
            $declaration,
            function (MetricBag $metrics) use (&$dit, &$outcome, &$diagnostics): iterable {
                $diagnostics = $metrics->entries(MetricName::DESIGN_DIT_UNRESOLVED);
                $dit = $metrics->get(MetricName::DESIGN_DIT);
                $outcome = $this->publishedOutcome($dit, $metrics->get(MetricName::DESIGN_DIT_UNRESOLVED));
                yield GateInput::metrics('dit-present', $metrics);
            },
            [GateInput::kind('logicalKind', $subject->toSymbolPath()->getType())],
            level: SymbolLevel::Class_,
        );
        if ($metrics === null) {
            return [null, $outcome, $diagnostics];
        }
        /** @var InheritanceOptions $effectiveOptions */
        $effectiveOptions = $this->getEffectiveOptions($context, $options, $subject);

        return [$this->findingForClass($info, (int) $dit, $effectiveOptions, $outcome), $outcome, $diagnostics];
    }

    private function publishedOutcome(int|float|null $dit, int|float|null $unresolved): InheritanceOutcome
    {
        if ($unresolved !== 1) {
            return InheritanceOutcome::Exact;
        }

        return $dit === null ? InheritanceOutcome::Loop : InheritanceOutcome::Floor;
    }

    /** @param array<string, array<string, scalar>> $obstructions */
    private function warnAboutIncompleteChains(int $floors, int $loops, array $obstructions): void
    {
        $parts = [];
        if ($floors !== 0) {
            $parts[] = \sprintf('%d incomplete inheritance chain(s) publish DIT lower bounds', $floors);
        }
        if ($loops !== 0) {
            $parts[] = \sprintf('%d cyclic inheritance chain(s) have no numeric DIT', $loops);
        }
        if ($parts !== []) {
            $samples = $this->obstructionSummary($obstructions);
            if ($samples !== null) {
                $parts[] = $samples;
            }
            $this->logger?->warning('DIT: ' . implode('; ', $parts) . '.');
        }
    }

    /** @param array<string, array<string, scalar>> $obstructions */
    private function obstructionSummary(array $obstructions): ?string
    {
        ksort($obstructions, \SORT_STRING);
        $samples = [];
        foreach (\array_slice($obstructions, 0, 5) as $obstruction) {
            $name = (string) $obstruction['name'];
            $samples[] = match ($obstruction['cause']) {
                ExternalChainOutcome::NoMapForIt->name => $name . ' (no composer install; run composer install)',
                ExternalChainOutcome::Loop->name => $name . ' (cycle)',
                default => $name . ' (source could not be placed or read)',
            };
        }

        return $samples === [] ? null : 'design.dit-unresolved: ' . implode(', ', $samples)
            . (\count($obstructions) > 5 ? \sprintf(', and %d more', \count($obstructions) - 5) : '');
    }

    private function findingForClass(
        SymbolInfo $info,
        int $ditValue,
        InheritanceOptions $options,
        InheritanceOutcome $outcome,
    ): ?Finding {
        return $this->thresholdFinding(
            $info,
            $ditValue,
            $options->getSeverity($ditValue),
            ['warning' => $options->warning, 'error' => $options->error],
            static fn(int|float $threshold, ThresholdCrossing $crossing): array => [
                \sprintf(($outcome === InheritanceOutcome::Floor ? 'DIT is at least %d, ' : 'DIT (Depth of Inheritance) is %d, ') . '%s threshold of %d. Prefer composition over deep inheritance', $ditValue, $crossing->value, $threshold),
                \sprintf($outcome === InheritanceOutcome::Floor ? 'DIT is at least %d (threshold: %d) — deep inheritance, fragile hierarchy' : 'DIT: %d (threshold: %d) — deep inheritance, fragile hierarchy', $ditValue, $threshold),
            ],
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
            self::NAME => self::judgingHigher(
                [MetricName::DESIGN_DIT],
                SymbolLevel::Class_,
            )->withGates(
                self::populationGate('logical-class-kind', self::NAME, SymbolLevel::Class_, 'declaration', self::kindIn('logicalKind', [SymbolType::Class_]), 'Only class declarations are judged.'),
                self::populationGate('dit-present', self::NAME, SymbolLevel::Class_, 'declaration', self::keyPresent('dit-present', [MetricName::DESIGN_DIT]), 'Numeric inheritance depth was not published.'),
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
