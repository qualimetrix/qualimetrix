<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Size;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\JudgedMetrics;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;
use Qualimetrix\Analysis\Finding\Contract\Population\KeyPresent;
use Qualimetrix\Analysis\Finding\Contract\Population\KindIn;

use Qualimetrix\Analysis\Finding\Contract\Population\PopulationGate;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\ThresholdCrossing;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Rule that checks number of methods per class.
 *
 * Too many methods indicate a class may be doing too much
 * and should be split into smaller focused classes.
 */
#[CliAlias('method-count-warning', 'warning')]
#[CliAlias('method-count-error', 'error')]
final class MethodCountRule extends AbstractRule
{
    public const string NAME = 'size.method-count';
    public const string DOCS_PAGE = 'rules/size.md';

    public const int REMEDIATION_MINUTES = 20;

    public const ChannelShape SHAPE = ChannelShape::Magnitude;
    public function getName(): string
    {
        return self::NAME;
    }

    public static function getDescription(): string
    {
        return 'Checks number of methods per class';
    }

    /**
     * @return class-string<MethodCountOptions>
     */
    public static function getOptionsClass(): string
    {
        return MethodCountOptions::class;
    }

    /**
     * `size.method-count` reports the class's method count
     * (`$methodCountValue` — see the emission above) as `metricValue`,
     * judged worse the higher it goes:
     * {@see MethodCountOptions::getSeverity()}'s `$value >= $this->error`
     * / `$value >= $this->warning`.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::NAME => ChannelDeclaration::judging(
                WorseDirection::Higher,
                JudgedMetrics::of(MetricName::SIZE_METHOD_COUNT),
                SymbolLevel::Class_,
            )->withGates(
                new PopulationGate('class-coordinate', new FindingChannel(self::NAME), SymbolLevel::Class_, 'declaration', new KindIn('class-coordinate', [SymbolType::Class_]), 'The subject is outside the class coordinate.'),
                new PopulationGate('method-count', new FindingChannel(self::NAME), SymbolLevel::Class_, 'declaration', new KeyPresent('method-count', [MetricName::SIZE_METHOD_COUNT]), 'Method count was not published.'),
            ),
        ];
    }

    /**
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof MethodCountOptions || !$this->options->isEnabled()) {
            return [];
        }

        $findings = [];
        $declaration = self::channelDeclarations()[self::NAME];

        foreach ($context->metrics->allClassDeclarations() as $classInfo) {
            $subject = $classInfo->subject ?? throw new LogicException('Method count findings require an exact class declaration subject');
            $metrics = null;
            if (!$context->admit(self::NAME, new FindingChannel(self::NAME), SymbolLevel::Class_, PopulationIdentity::subject($subject), $declaration, (static function () use ($context, $subject, &$metrics): iterable {
                yield GateInput::kind('class-coordinate', $subject->toSymbolPath()->getType());
                $metrics = $context->metrics->getSubject($subject);
                yield GateInput::metrics('method-count', $metrics);
            })())) {
                continue;
            }
            $methodCount = $metrics->get(MetricName::SIZE_METHOD_COUNT);

            $methodCountValue = (int) $methodCount;
            /** @var MethodCountOptions $effectiveOptions */
            $effectiveOptions = $this->getEffectiveOptions($context, $this->options, $subject);
            $finding = $this->findingForClass($classInfo, $subject, $methodCountValue, $effectiveOptions);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function findingForClass(
        SymbolInfo $classInfo,
        MetricSubject $subject,
        int $methodCount,
        MethodCountOptions $options,
    ): ?Finding {
        $severity = $options->getSeverity($methodCount);
        if ($severity === null) {
            return null;
        }

        $threshold = $severity === Severity::Error ? $options->error : $options->warning;

        return new Finding(
            location: new Location($classInfo->file, $classInfo->line),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            code: self::NAME,
            message: \sprintf('Method count is %d, ' . ThresholdCrossing::of($methodCount, $threshold)->value . ' threshold of %d. Consider splitting into smaller focused classes', $methodCount, $threshold),
            severity: $severity,
            metricValue: $methodCount,
            recommendation: \sprintf('Methods: %d (threshold: %d) — too many methods', $methodCount, $threshold),
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
