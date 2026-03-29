<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Size;

use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Rules\AbstractRule;

/**
 * Rule that checks number of classes per namespace.
 *
 * Too many classes in a namespace indicate it may be doing too much
 * and should be split into sub-namespaces.
 */
final class ClassCountRule extends AbstractRule
{
    public const string NAME = 'size.class-count';

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
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'class-count-warning' => 'warning',
            'class-count-error' => 'error',
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
            // Skip parent namespaces — only analyze leaf namespaces
            $namespace = $namespaceInfo->symbolPath->namespace;
            if ($namespace !== null && $context->namespaceTree !== null && !$context->namespaceTree->isLeaf($namespace)) {
                continue;
            }

            $metrics = $context->metrics->get($namespaceInfo->symbolPath);

            // Get aggregated classCount (sum from all files in namespace)
            $classCount = (int) ($metrics->get(MetricName::SIZE_CLASS_COUNT . '.sum') ?? 0);

            if ($classCount === 0) {
                continue;
            }

            /** @var ClassCountOptions $effectiveOptions */
            $effectiveOptions = $this->getEffectiveOptions($context, $this->options, $namespaceInfo->file, $namespaceInfo->line ?? 1);
            $severity = $effectiveOptions->getSeverity($classCount);

            if ($severity !== null) {
                $threshold = $severity === Severity::Error ? $effectiveOptions->error : $effectiveOptions->warning;

                $violations[] = new Violation(
                    location: new Location($namespaceInfo->file),
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
}
