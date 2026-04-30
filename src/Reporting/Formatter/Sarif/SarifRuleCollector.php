<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Sarif;

use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;

/**
 * Collects and describes SARIF rule entries from a set of violations.
 *
 * Builds the rules array for the SARIF tool driver, including human-readable
 * names, descriptions, and documentation URLs derived from rule categories.
 */
final class SarifRuleCollector
{
    public const INFORMATION_URI = 'https://github.com/qualimetrix/qualimetrix';
    private const DOCS_BASE_URI = 'https://qualimetrix.dev/rules/';

    /** @var array<string, string> Maps rule category prefix to its docs page path segment */
    private const CATEGORY_DOCS_MAP = [
        'complexity' => 'complexity/',
        'coupling' => 'coupling/',
        'cohesion' => 'cohesion/',
        'design' => 'design/',
        'maintainability' => 'maintainability/',
        'size' => 'size/',
        'code-smell' => 'code-smell/',
        'architecture' => 'architecture/',
        'security' => 'security/',
        'duplication' => 'architecture/',
        'computed' => 'maintainability/',
    ];

    /**
     * Collects unique rules from violations.
     *
     * @param list<Violation> $violations
     *
     * @return list<array{id: string, name: string, shortDescription: array{text: string}, fullDescription: array{text: string}, helpUri: string, defaultConfiguration: array{level: string}}>
     */
    public function collectRules(array $violations): array
    {
        // Collect unique violation codes with their max severity
        /** @var array<string, array{ruleName: string, maxSeverity: Severity}> $violationCodes */
        $violationCodes = [];

        foreach ($violations as $violation) {
            $code = $violation->violationCode;

            if (!isset($violationCodes[$code])) {
                $violationCodes[$code] = [
                    'ruleName' => $violation->ruleName,
                    'maxSeverity' => $violation->severity,
                ];
            } elseif ($violation->severity === Severity::Error) {
                $violationCodes[$code]['maxSeverity'] = Severity::Error;
            }
        }

        $rules = [];

        foreach ($violationCodes as $code => $info) {
            $rules[] = [
                'id' => $code,
                'name' => $this->formatRuleName($code),
                'shortDescription' => [
                    'text' => $this->getRuleDescription($code),
                ],
                'fullDescription' => [
                    'text' => $this->getRuleDescription($code),
                ],
                'helpUri' => $this->getHelpUri($code),
                'defaultConfiguration' => [
                    'level' => $this->mapLevel($info['maxSeverity']),
                ],
            ];
        }

        return $rules;
    }

    /**
     * Formats rule name from kebab-case to Title Case.
     */
    public function formatRuleName(string $ruleName): string
    {
        // Convert kebab-case and dot-separated names to words
        $words = (preg_split('/[-.]/', $ruleName) !== false ? preg_split('/[-.]/', $ruleName) : [$ruleName]);
        $words = array_map('ucfirst', $words);

        return implode(' ', $words);
    }

    /**
     * Returns human-readable description for a rule.
     */
    public function getRuleDescription(string $ruleName): string
    {
        return match ($ruleName) {
            'complexity.cyclomatic', 'complexity.cognitive', 'complexity.npath' => 'Code complexity exceeds threshold',
            'complexity.wmc' => 'Weighted methods per class exceeds threshold',
            'size.class-count', 'size.method-count', 'size.property-count', 'size.namespace-size' => 'Code size exceeds threshold',
            'size.loc' => 'Lines of code exceeds threshold',
            'size.long-parameter-list' => 'Too many parameters',
            'maintainability.index' => 'Maintainability index below threshold',
            'design.lcom' => 'Lack of cohesion of methods',
            'design.inheritance', 'design.noc' => 'Inheritance structure issue',
            'design.type-coverage' => 'Type coverage below threshold',
            'coupling.cbo', 'coupling.instability', 'coupling.distance' => 'Coupling issue',
            'architecture.circular-dependency' => 'Circular dependency detected',
            'duplication.code-duplication' => 'Duplicated code block detected',
            'code-smell.constructor-overinjection' => 'Constructor has too many dependencies',
            'design.data-class' => 'Data Class detected (high public surface, low complexity)',
            'design.god-class' => 'God Class detected (complex, large, low cohesion)',
            'code-smell.unused-private' => 'Unused private member detected',
            default => ucfirst(str_replace(['.', '-'], ' ', $ruleName)),
        };
    }

    /**
     * Returns the documentation URL for a rule, based on its category prefix.
     *
     * Maps known category prefixes (e.g. "complexity", "code-smell") to the
     * corresponding page on the Qualimetrix website. Falls back to the repository URL
     * for unknown or user-defined rule names.
     */
    public function getHelpUri(string $ruleName): string
    {
        $dotPos = strpos($ruleName, '.');
        $category = $dotPos !== false ? substr($ruleName, 0, $dotPos) : $ruleName;

        if (isset(self::CATEGORY_DOCS_MAP[$category])) {
            return self::DOCS_BASE_URI . self::CATEGORY_DOCS_MAP[$category];
        }

        return self::INFORMATION_URI;
    }

    /**
     * Maps internal severity to SARIF level.
     *
     * SARIF levels: error, warning, note, none
     */
    public function mapLevel(Severity $severity): string
    {
        return match ($severity) {
            Severity::Error => 'error',
            Severity::Warning => 'warning',
        };
    }
}
