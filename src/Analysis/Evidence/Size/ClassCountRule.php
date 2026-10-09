<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Size;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\JudgedMetrics;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;

use Qualimetrix\Analysis\Finding\Contract\Population\KeyPresent;
use Qualimetrix\Analysis\Finding\Contract\Population\KeyThreshold;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationGate;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\ThresholdCrossing;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Rule that checks number of classes per namespace.
 *
 * Too many classes in a namespace indicate it may be doing too much
 * and should be split into sub-namespaces.
 */
#[CliAlias('class-count-warning', 'warning')]
#[CliAlias('class-count-error', 'error')]
final class ClassCountRule extends AbstractRule
{
    public const string NAME = 'size.class-count';
    public const string DOCS_PAGE = 'rules/size.md';

    public const int REMEDIATION_MINUTES = 30;

    public const ChannelShape SHAPE = ChannelShape::Magnitude;
    public function getName(): string
    {
        return self::NAME;
    }

    public static function getDescription(): string
    {
        return 'Checks number of classes per namespace';
    }

    /**
     * @return class-string<ClassCountOptions>
     */
    public static function getOptionsClass(): string
    {
        return ClassCountOptions::class;
    }

    /**
     * `size.class-count` reports the namespace's class count
     * (`$classCount` — see the emission below) as `metricValue`, judged
     * worse the higher it goes: {@see ClassCountOptions::getSeverity()}'s
     * `$value >= $this->error` / `$value >= $this->warning`.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::NAME => ChannelDeclaration::judging(
                WorseDirection::Higher,
                JudgedMetrics::of(MetricName::SIZE_CLASS_COUNT),
                SymbolLevel::Namespace_,
            )->withGates(
                new PopulationGate('own-count', new FindingChannel(self::NAME), SymbolLevel::Namespace_, 'namespace', new KeyPresent('own-count', [MetricName::SIZE_CLASS_COUNT]), 'The own class count was not published.'),
                new PopulationGate('nonempty-count', new FindingChannel(self::NAME), SymbolLevel::Namespace_, 'namespace', new KeyThreshold('nonempty-count', [MetricName::SIZE_CLASS_COUNT], '>', 0, 'zero', true), 'The namespace has no own classes.'),
            ),
        ];
    }

    /**
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof ClassCountOptions || !$this->options->isEnabled()) {
            return [];
        }

        $declaration = self::channelDeclarations()[self::NAME];
        $findings = [];

        foreach ($context->metrics->all(SymbolLevel::Namespace_) as $namespaceInfo) {
            $subject = $namespaceInfo->subject
                ?? MetricSubject::aggregate($namespaceInfo->symbolPath);
            $metrics = $context->metrics->get($namespaceInfo->symbolPath);
            if (!$context->admit(
                self::NAME,
                new FindingChannel(self::NAME),
                SymbolLevel::Namespace_,
                PopulationIdentity::aggregate($namespaceInfo->symbolPath),
                $declaration,
                [
                    GateInput::metrics('own-count', $metrics), GateInput::metrics('nonempty-count', $metrics),
                ],
            )) {
                continue;
            }

            $classCount = (int) $metrics->get(MetricName::SIZE_CLASS_COUNT);
            /** @var ClassCountOptions $effectiveOptions */
            $effectiveOptions = $this->getEffectiveOptions($context, $this->options, $subject);
            $severity = $effectiveOptions->getSeverity($classCount);

            if ($severity !== null) {
                $threshold = $severity === Severity::Error ? $effectiveOptions->error : $effectiveOptions->warning;

                $findings[] = new Finding(
                    location: new Location($namespaceInfo->file),
                    subject: $subject,
                    symbolPath: $namespaceInfo->symbolPath,
                    ruleName: $this->getName(),
                    code: self::NAME,
                    message: \sprintf('Class count is %d, ' . ThresholdCrossing::of($classCount, $threshold)->value . ' threshold of %d. Consider splitting into sub-namespaces', $classCount, $threshold),
                    severity: $severity,
                    metricValue: $classCount,
                    recommendation: \sprintf('Classes: %d (threshold: %d) — too many classes in namespace', $classCount, $threshold),
                    threshold: $threshold,
                );
            }
        }

        return $findings;
    }

    /**
     * Declared, never inferred from the options class: `@qmx-threshold` can
     * retune this rule. See
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOverrideSupportReader},
     * which also explains why this is a constant and why it is declared last.
     */
    public const bool SUPPORTS_THRESHOLD_OVERRIDE = true;
}
