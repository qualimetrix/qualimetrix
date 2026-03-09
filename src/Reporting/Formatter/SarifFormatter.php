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
                    'uri' => self::pathToFileUri($context->basePath),
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
                    'text' => $this->getRuleDescription($info['ruleName']),
                ],
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
            default => ucfirst(str_replace(['.', '-'], ' ', $ruleName)),
        };
    }

    /**
     * Formats violations as SARIF results.
     *
     * @param list<Violation> $violations
     *
     * @return list<array<string, mixed>>
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
                    // Omit locations for project-level violations (valid per SARIF 2.1.0)
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

    /**
     * Converts an absolute filesystem path to a file:/// URI (RFC 8089).
     *
     * Handles both Unix (/home/user/project) and Windows (C:\Users\project) paths.
     */
    private static function pathToFileUri(string $path): string
    {
        // Normalize backslashes to forward slashes (Windows)
        $path = str_replace('\\', '/', $path);

        // Ensure trailing slash
        $path = rtrim($path, '/') . '/';

        // RFC 8089: file:///path on Unix, file:///C:/path on Windows
        // Unix paths start with '/', so 'file://' + '/path' = 'file:///path' (correct)
        // Windows paths start with 'C:/', so 'file:///' + 'C:/path' = 'file:///C:/path' (correct)
        return 'file://' . ($path[0] === '/' ? '' : '/') . $path;
    }
}
