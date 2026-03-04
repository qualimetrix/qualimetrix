<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Architecture;

use AiMessDetector\Core\Dependency\CycleInterface;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\SymbolPath;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Rules\AbstractRule;
use InvalidArgumentException;

/**
 * Detects circular dependencies between classes.
 *
 * Circular dependencies (A depends on B, B depends on C, C depends on A) are
 * architectural anti-patterns that make code harder to test, understand, and maintain.
 *
 * This rule expects cycles to be provided via AnalysisContext::additionalData['cycles'].
 */
final class CircularDependencyRule extends AbstractRule
{
    public const string NAME = 'circular-dependency';

    public function __construct(
        RuleOptionsInterface $options,
    ) {
        if (!$options instanceof CircularDependencyOptions) {
            throw new InvalidArgumentException(
                \sprintf('Expected %s, got %s', CircularDependencyOptions::class, $options::class),
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
        return 'Detects circular dependencies between classes';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Architecture;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return []; // Requires dependency graph, not metrics
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options->isEnabled()) {
            return [];
        }

        if (!$this->options instanceof CircularDependencyOptions) {
            return [];
        }

        // Get cycles from additional data (populated by Analyzer)
        $cycles = $context->getAdditionalData('cycles');
        if ($cycles === null || !\is_array($cycles)) {
            return []; // No cycles data available
        }

        $violations = [];

        foreach ($cycles as $cycle) {
            if (!$cycle instanceof CycleInterface) {
                continue; // Skip invalid entries
            }
            $severity = $this->options->getSeverity($cycle->getSize());
            if ($severity === null) {
                continue; // Cycle too large or filtered out
            }

            // Use the first class in the cycle as the violation location
            $classes = $cycle->getClasses();
            $firstClass = $classes[0] ?? '';
            if ($firstClass === '') {
                continue; // Empty cycle
            }
            [$namespace, $className] = $this->splitFqn($firstClass);

            $violations[] = new Violation(
                location: new Location('', 0), // No specific file location for architectural violations
                symbolPath: SymbolPath::forClass($namespace, $className),
                ruleName: $this->getName(),
                message: \sprintf(
                    'Circular dependency detected: %s',
                    $cycle->toShortString(),
                ),
                severity: $severity,
                metricValue: $cycle->getSize(),
            );
        }

        return $violations;
    }

    /**
     * @return class-string<CircularDependencyOptions>
     */
    public static function getOptionsClass(): string
    {
        return CircularDependencyOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'no-circular-deps' => 'enabled',
            'max-cycle-size' => 'maxCycleSize',
        ];
    }

    /**
     * Splits a fully-qualified class name into namespace and class name.
     *
     * @return array{0: string, 1: string} [namespace, className]
     */
    private function splitFqn(string $fqn): array
    {
        $pos = strrpos($fqn, '\\');
        if ($pos === false) {
            return ['', $fqn];
        }

        return [
            substr($fqn, 0, $pos),
            substr($fqn, $pos + 1),
        ];
    }
}
