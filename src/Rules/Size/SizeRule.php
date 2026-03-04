<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Size;

use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\HierarchicalRuleInterface;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Rule\RuleLevel;
use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Rules\AbstractRule;
use InvalidArgumentException;

/**
 * Hierarchical rule that checks size at class and namespace levels.
 *
 * - Class level: checks number of methods in a class
 * - Namespace level: checks number of classes in a namespace
 */
final class SizeRule extends AbstractRule implements HierarchicalRuleInterface
{
    public const string NAME = 'size';

    public function __construct(
        RuleOptionsInterface $options,
    ) {
        if (!$options instanceof SizeOptions) {
            throw new InvalidArgumentException(
                \sprintf('Expected %s, got %s', SizeOptions::class, $options::class),
            );
        }
        parent::__construct($options);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks size at class and namespace levels';
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
        return ['classCount', 'methodCount'];
    }

    /**
     * @return list<RuleLevel>
     */
    public function getSupportedLevels(): array
    {
        return [RuleLevel::Class_, RuleLevel::Namespace_];
    }

    /**
     * Analyzes at a specific level.
     *
     * @return list<Violation>
     */
    public function analyzeLevel(RuleLevel $level, AnalysisContext $context): array
    {
        if (!$this->options instanceof SizeOptions) {
            return [];
        }

        $levelOptions = $this->options->forLevel($level);
        if (!$levelOptions->isEnabled()) {
            return [];
        }

        return match ($level) {
            RuleLevel::Class_ => $this->analyzeClassLevel($context),
            RuleLevel::Namespace_ => $this->analyzeNamespaceLevel($context),
            default => [],
        };
    }

    /**
     * Legacy analyze method for backward compatibility.
     *
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        // When called directly (not via analyzeLevel), analyze all enabled levels
        $violations = [];

        foreach ($this->getSupportedLevels() as $level) {
            if ($this->options instanceof SizeOptions && $this->options->isLevelEnabled($level)) {
                $violations = [...$violations, ...$this->analyzeLevel($level, $context)];
            }
        }

        return $violations;
    }

    /**
     * @return class-string<SizeOptions>
     */
    public static function getOptionsClass(): string
    {
        return SizeOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            // Class-level aliases
            'size-class-warning' => 'class.warning',
            'size-class-error' => 'class.error',
            // Namespace-level aliases (legacy compatibility)
            'ns-warning' => 'namespace.warning',
            'ns-error' => 'namespace.error',
        ];
    }

    /**
     * Analyzes class level — checks method count per class.
     *
     * @return list<Violation>
     */
    private function analyzeClassLevel(AnalysisContext $context): array
    {
        if (!$this->options instanceof SizeOptions) {
            return [];
        }
        $classOptions = $this->options->class;

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Class_) as $classInfo) {
            $metrics = $context->metrics->get($classInfo->symbolPath);
            $methodCount = $metrics->get('methodCount');

            if ($methodCount === null) {
                continue;
            }

            $methodCountValue = (int) $methodCount;
            $severity = $classOptions->getSeverity($methodCountValue);

            if ($severity !== null) {
                $violations[] = new Violation(
                    location: new Location($classInfo->file, $classInfo->line),
                    symbolPath: $classInfo->symbolPath,
                    ruleName: $this->getName(),
                    message: \sprintf('Class has %d methods', $methodCountValue),
                    severity: $severity,
                    metricValue: $methodCountValue,
                    level: RuleLevel::Class_,
                );
            }
        }

        return $violations;
    }

    /**
     * Analyzes namespace level — checks class count per namespace.
     *
     * @return list<Violation>
     */
    private function analyzeNamespaceLevel(AnalysisContext $context): array
    {
        if (!$this->options instanceof SizeOptions) {
            return [];
        }
        $namespaceOptions = $this->options->namespace;

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Namespace_) as $namespaceInfo) {
            $metrics = $context->metrics->get($namespaceInfo->symbolPath);

            // Get aggregated classCount (sum from all files in namespace)
            $classCount = (int) ($metrics->get('classCount.sum') ?? 0);

            if ($classCount === 0) {
                continue;
            }

            $severity = $namespaceOptions->getSeverity($classCount);

            if ($severity !== null) {
                $violations[] = new Violation(
                    location: new Location($namespaceInfo->file),
                    symbolPath: $namespaceInfo->symbolPath,
                    ruleName: $this->getName(),
                    message: \sprintf('Namespace has %d classes', $classCount),
                    severity: $severity,
                    metricValue: $classCount,
                    level: RuleLevel::Namespace_,
                );
            }
        }

        return $violations;
    }
}
