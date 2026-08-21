<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Size;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
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
use Qualimetrix\Core\Symbol\SymbolType;

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

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks number of classes per namespace';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Size;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [MetricName::SIZE_CLASS_COUNT];
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
     * (`$classCount` — see the emission above) as `metricValue`, judged
     * worse the higher it goes: {@see ClassCountOptions::getSeverity()}'s
     * `$value >= $this->error` (line 67) / `$value >= $this->warning`
     * (line 71).
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
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof ClassCountOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Namespace_) as $namespaceInfo) {
            $subject = $namespaceInfo->subject
                ?? MetricSubject::aggregate($namespaceInfo->symbolPath);
            // Skip parent namespaces — only analyze leaf namespaces
            $namespace = $namespaceInfo->symbolPath->namespace;
            if ($namespace !== null && $context->namespaceTree !== null && !$context->namespaceTree->isLeaf($namespace)) {
                continue;
            }

            $metrics = $context->metrics->get($namespaceInfo->symbolPath);

            // Get aggregated classCount (sum from all files in namespace)
            $classCount = (int) ($metrics->get(MetricName::agg(MetricName::SIZE_CLASS_COUNT, AggregationStrategy::Sum)) ?? 0);

            if ($classCount === 0) {
                continue;
            }

            /** @var ClassCountOptions $effectiveOptions */
            $effectiveOptions = $this->getEffectiveOptions($context, $this->options, $subject);
            $severity = $effectiveOptions->getSeverity($classCount);

            if ($severity !== null) {
                $threshold = $severity === Severity::Error ? $effectiveOptions->error : $effectiveOptions->warning;

                $violations[] = new Violation(
                    location: new Location($namespaceInfo->file),
                    subject: $subject,
                    symbolPath: $namespaceInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: self::NAME,
                    message: \sprintf('Class count is %d, exceeds threshold of %d. Consider splitting into sub-namespaces', $classCount, $threshold),
                    severity: $severity,
                    metricValue: $classCount,
                    recommendation: \sprintf('Classes: %d (threshold: %d) — too many classes in namespace', $classCount, $threshold),
                    threshold: $threshold,
                );
            }
        }

        return $violations;
    }

    /**
     * Declared, never inferred from the options class: `@qmx-threshold` can
     * retune this rule. See
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOverrideSupportReader},
     * which also explains why this is a constant and why it is declared last.
     */
    public const bool SUPPORTS_THRESHOLD_OVERRIDE = true;
}
