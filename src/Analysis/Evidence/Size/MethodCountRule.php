<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Size;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;

use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\ThresholdCrossing;
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
            self::NAME => self::judgingHigher(
                [MetricName::SIZE_METHOD_COUNT],
                SymbolLevel::Class_,
            )->withGates(
                self::populationGate('class-coordinate', self::NAME, SymbolLevel::Class_, 'declaration', self::kindIn('class-coordinate', [SymbolType::Class_]), 'The subject is outside the class coordinate.'),
                self::populationGate('method-count', self::NAME, SymbolLevel::Class_, 'declaration', self::keyPresent('method-count', [MetricName::SIZE_METHOD_COUNT]), 'Method count was not published.'),
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

        foreach ($this->admittedDeclarations($context, self::channelDeclarations()[self::NAME], $context->metrics->allClassDeclarations(), SymbolLevel::Class_, 'method-count', 'class-coordinate') as [$classInfo, $subject, $metrics]) {
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
        return $this->thresholdFinding(
            $classInfo,
            $methodCount,
            $options->getSeverity($methodCount),
            ['warning' => $options->warning, 'error' => $options->error],
            static fn(int|float $threshold, ThresholdCrossing $crossing): array => [
                \sprintf('Method count is %d, %s threshold of %d. Consider splitting into smaller focused classes', $methodCount, $crossing->value, $threshold),
                \sprintf('Methods: %d (threshold: %d) — too many methods', $methodCount, $threshold),
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
