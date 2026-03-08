<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Formatter;

use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\Report;

/**
 * Formats report as SARIF (Static Analysis Results Interchange Format) JSON.
 *
 * SARIF 2.1.0 spec: https://docs.oasis-open.org/sarif/sarif/v2.1.0/sarif-v2.1.0.html
 * Supported by GitHub Security, VS Code SARIF Viewer, Azure DevOps, JetBrains IDEs.
 */
final class SarifFormatter implements FormatterInterface
{
    private const VERSION = '0.1.0';
    private const SCHEMA = 'https://raw.githubusercontent.com/oasis-tcs/sarif-spec/master/Schemata/sarif-schema-2.1.0.json';
    private const INFORMATION_URI = 'https://github.com/FractalizeR/php_ai_mess_detector';

    public function format(Report $report, FormatterContext $context): string
    {
        $rules = $this->collectRules($report->violations);

        $run = [
            'tool' => [
                'driver' => [
                    'name' => 'AI Mess Detector',
                    'version' => self::VERSION,
                    'informationUri' => self::INFORMATION_URI,
                    'rules' => $rules,
                ],
            ],
            'results' => $this->formatResults($report->violations, $context),
        ];

        // Add originalUriBaseIds when basePath is provided
        if ($context->basePath !== '') {
            $run['originalUriBaseIds'] = [
                '%SRCROOT%' => [
                    'uri' => rtrim($context->basePath, '/') . '/',
                ],
            ];
        }

        $sarif = [
            '$schema' => self::SCHEMA,
            'version' => '2.1.0',
            'runs' => [$run],
        ];

        return json_encode($sarif, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    public function getName(): string
    {
        return 'sarif';
    }

    public function getDefaultGroupBy(): GroupBy
    {
        return GroupBy::None;
    }

    /**
     * Collects unique rules from violations.
     *
     * @param list<Violation> $violations
     *
     * @return list<array{id: string, name: string, shortDescription: array{text: string}, defaultConfiguration: array{level: string}}>
     */
    private function collectRules(array $violations): array
    {
        // Collect unique violation codes
        $violationCodes = [];
        foreach ($violations as $violation) {
            $violationCodes[$violation->violationCode] = $violation->ruleName;
        }

        $rules = [];
        foreach ($violationCodes as $code => $ruleName) {
            $rules[] = [
                'id' => $code,
                'name' => $this->formatRuleName($code),
                'shortDescription' => [
                    'text' => $this->getRuleDescription($ruleName),
                ],
                'defaultConfiguration' => [
                    'level' => 'warning',
                ],
            ];
        }

        return $rules;
    }

    /**
     * Formats rule name from kebab-case to Title Case.
     */
    private function formatRuleName(string $ruleName): string
    {
        // Convert kebab-case and dot-separated names to words
        $words = preg_split('/[-.]/', $ruleName) ?: [$ruleName];
        $words = array_map('ucfirst', $words);

        return implode(' ', $words);
    }

    /**
     * Returns human-readable description for a rule.
     */
    private function getRuleDescription(string $ruleName): string
    {
        return match ($ruleName) {
            'cyclomatic-complexity', 'cognitive-complexity' => 'Code complexity exceeds threshold',
            'class-size', 'namespace-size' => 'Code size exceeds threshold',
            'maintainability-index' => 'Maintainability index below threshold',
            'lcom' => 'Lack of cohesion of methods exceeds threshold',
            'inheritance-depth' => 'Inheritance depth exceeds threshold',
            default => ucfirst(str_replace('-', ' ', $ruleName)),
        };
    }

    /**
     * Formats violations as SARIF results.
     *
     * @param list<Violation> $violations
     *
     * @return list<array{ruleId: string, level: string, message: array{text: string}, locations: list<array{physicalLocation: array<string, mixed>}>}>
     */
    private function formatResults(array $violations, FormatterContext $context): array
    {
        return array_map(
            function (Violation $v) use ($context): array {
                $result = [
                    'ruleId' => $v->violationCode,
                    'level' => $this->mapLevel($v->severity),
                    'message' => ['text' => $v->message],
                ];

                if ($v->location->isNone()) {
                    $result['locations'] = [['physicalLocation' => []]];
                } else {
                    $result['locations'] = [
                        [
                            'physicalLocation' => [
                                'artifactLocation' => $this->buildArtifactLocation(
                                    $context->relativizePath($v->location->file),
                                    $context->basePath !== '',
                                ),
                                'region' => [
                                    'startLine' => $v->location->line ?? 1,
                                    'startColumn' => 1,
                                ],
                            ],
                        ],
                    ];
                }

                return $result;
            },
            $violations,
        );
    }

    /**
     * Maps internal severity to SARIF level.
     *
     * SARIF levels: error, warning, note, none
     */
    /**
     * @return array<string, string>
     */
    private function buildArtifactLocation(string $uri, bool $hasBasePath): array
    {
        $location = ['uri' => $uri];

        if ($hasBasePath) {
            $location['uriBaseId'] = '%SRCROOT%';
        }

        return $location;
    }

    private function mapLevel(Severity $severity): string
    {
        return match ($severity) {
            Severity::Error => 'error',
            Severity::Warning => 'warning',
        };
    }
}
