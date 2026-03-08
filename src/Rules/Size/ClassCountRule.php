<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Size;

use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Rules\AbstractRule;

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
        return ['classCount'];
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
            $metrics = $context->metrics->get($namespaceInfo->symbolPath);

            // Get aggregated classCount (sum from all files in namespace)
            $classCount = (int) ($metrics->get('classCount.sum') ?? 0);

            if ($classCount === 0) {
                continue;
            }

            $severity = $this->options->getSeverity($classCount);

            if ($severity !== null) {
                $threshold = $severity === Severity::Error ? $this->options->error : $this->options->warning;

                $violations[] = new Violation(
                    location: new Location($namespaceInfo->file),
                    symbolPath: $namespaceInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: self::NAME,
                    message: \sprintf('Class count is %d, exceeds threshold of %d. Consider splitting into sub-namespaces', $classCount, $threshold),
                    severity: $severity,
                    metricValue: $classCount,
                );
            }
        }

        return $violations;
    }
}
