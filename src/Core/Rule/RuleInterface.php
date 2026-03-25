<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Rule;

use Qualimetrix\Core\Violation\Violation;

interface RuleInterface
{
    /**
     * Returns unique rule name (slug).
     */
    public function getName(): string;

    /**
     * Returns human-readable description.
     */
    public function getDescription(): string;

    /**
     * Returns rule category for grouping.
     */
    public function getCategory(): RuleCategory;

    /**
     * Returns list of metric names this rule requires.
     *
     * @return list<string>
     */
    public function requires(): array;

    /**
     * Analyzes metrics and generates violations.
     *
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array;

    /**
     * Returns the options class for this rule.
     *
     * @return class-string<RuleOptionsInterface>
     */
    public static function getOptionsClass(): string;

    /**
     * Returns CLI short aliases for rule options.
     *
     * Format: ['alias' => 'optionName']
     * Example: ['cyclomatic-warning' => 'warningThreshold', 'cyclomatic-error' => 'errorThreshold']
     *
     * @return array<string, string>
     */
    public static function getCliAliases(): array;
}
